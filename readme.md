```
package main

import (
	"context"
	"crypto/hmac"
	"crypto/sha512"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"
	"trading-bot/internal/config"
	"trading-bot/internal/domain"
	"trading-bot/internal/telemetry"

	"github.com/gorilla/websocket"
	"github.com/joho/godotenv"
	"github.com/nats-io/nats.go"
	"go.uber.org/zap"
)

const (
	loginChannel = "futures.login"
	pingInterval = 20 * time.Second
)

// Базовые структуры для общения с Gate.io WS

type GateRequest struct {
	Id      int64          `json:"id,omitempty"`
	Time    int64          `json:"time"`
	Channel string         `json:"channel"`
	Event   string         `json:"event"`
	Payload any            `json:"payload,omitempty"`
	Auth    *GateAuthBlock `json:"auth,omitempty"`
}

type UnAuthGateRequest struct {
	Time    int64  `json:"time"`
	Channel string `json:"channel"`
	Event   string `json:"event"`
	Payload any    `json:"payload,omitempty"`
}

type GateAuthBlock struct {
	Method string `json:"method"`
	KEY    string `json:"KEY"`
	SIGN   string `json:"SIGN"`
}

type GateResponse struct {
	Channel string          `json:"channel"`
	Event   string          `json:"event"`
	Error   *GateErrorBlock `json:"error,omitempty"`
	Header  map[string]any  `json:"header,omitempty"`
}

type GateErrorBlock struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

type Feeder struct {
	apiKey        string
	apiSecret     string
	wsHost        string
	wsPath        string
	js            nats.JetStreamContext
	lastHeartbeat time.Time
}

func main() {
	// Инициализируем конфиг из .env
	if err := godotenv.Load(); err != nil {
		fmt.Println("[WARN] Файл .env не найден, читаем системные переменные окружения")
	}

	cfg := config.Load()

	// 2. Инициализируем Uber Zap Логгер
	mainLogger := telemetry.InitLogger(cfg.IsProd)
	defer func(Log *zap.Logger) {
		_ = Log.Sync()
	}(mainLogger)

	mainLogger.Info("=== [CORE] ЗАПУСК API WEBSOCKET FEEDER ===")

	if cfg.ApiKey == "" || cfg.ApiSecret == "" || cfg.WsApiHost == "" || cfg.WsApiPath == "" {
		mainLogger.Fatal("Критическая ошибка: Переменные окружения GATE_API_* не установлены")
	}

	f := &Feeder{
		apiKey:        cfg.ApiKey,
		apiSecret:     cfg.ApiSecret,
		wsHost:        cfg.WsApiHost,
		wsPath:        cfg.WsApiPath,
		lastHeartbeat: time.Now(),
	}

	// Подключаемся к NATS
	natsURL := cfg.NatsURL
	if natsURL == "" {
		natsURL = nats.DefaultURL
	}
	nc, err := nats.Connect(natsURL, nats.Timeout(5*time.Second))
	if err != nil {
		mainLogger.Fatal("Не удалось подключиться к NATS", zap.Error(err))
	}
	defer nc.Close()

	js, err := nc.JetStream()
	if err != nil {
		mainLogger.Fatal("Не удалось инициализировать подсистему NATS JetStream", zap.Error(err))
	}
	f.js = js

	srv := &http.Server{
		Addr: ":" + cfg.WsFeederHealthcheckPort,
	}

	// Запускаем HTTP-сервер для проб Кубера (Health-checks)
	go startHealthCheckServer(srv, cfg.WsFeederHealthcheckPort, nc, f, mainLogger)

	// Канал прерывания для Graceful Shutdown
	stopChan := make(chan os.Signal, 1)
	signal.Notify(stopChan, os.Interrupt, syscall.SIGTERM)

	mainLogger.Info("Сервер успешно инициализирован, запуск главного цикла соединения...")

	// Бесконечный цикл реконнекта
	for {
		select {
		case <-stopChan:
			mainLogger.Info("Получен сигнал завершения. Выключаемся...")
			return
		default:
			mainLogger.Info("Попытка подключения к Gate.io WebSocket...")
			err = f.connectAndListen(stopChan, srv, nc, mainLogger, cfg.GateUserUid, cfg.ActiveSymbols)
			if err != nil {
				mainLogger.Warn("Ошибка соединения. Реконнект через 5 секунд...", zap.Error(err))
				time.Sleep(5 * time.Second)
			}
		}
	}
}

// connectAndListen подключение к ws и ловля событий
func (f *Feeder) connectAndListen(
	stopChan chan os.Signal,
	srv *http.Server,
	nc *nats.Conn,
	logger *zap.Logger,
	userUid string,
	symbols []string,
) error {
	u := url.URL{Scheme: "wss", Host: f.wsHost, Path: f.wsPath}
	dialer := websocket.DefaultDialer
	dialer.HandshakeTimeout = 20 * time.Second

	conn, _, err := dialer.Dial(u.String(), nil)
	if err != nil {
		return fmt.Errorf("ошибка Dial: %w", err)
	}
	defer func(conn *websocket.Conn) {
		err = conn.Close()
		if err != nil {
			logger.Warn("close websocket connection", zap.Error(err))
		}
	}(conn)

	logger.Info("WebSocket соединение установлено")

	// Авторизация
	if err = f.authenticate(conn, logger); err != nil {
		return fmt.Errorf("ошибка авторизации: %w", err)
	}

	// Подписка на нужные топики фьючерсов
	channels := map[string][]any{
		domain.WsChannelOrders:  {userUid, symbols},
		domain.WsChannelTickers: {symbols},
	}
	var isAuth bool
	for ch, payload := range channels {
		switch ch {
		case domain.WsChannelOrders:
			isAuth = true
		case domain.WsChannelTickers:
			isAuth = false
		}
		if err = f.subscribeChannel(conn, ch, payload, isAuth); err != nil {
			return fmt.Errorf("ошибка подписки на %s: %w", ch, err)
		}
	}

	// Канал для отслеживания падения горутины пинга
	pingDone := make(chan struct{})
	go f.startPingLoop(conn, pingDone, logger)

	// Буфер для чтения
	readChan := make(chan []byte)
	errChan := make(chan error)

	// Отдельная горутина на чтение из сокета, чтобы не блокировать селекты
	go func() {
		for {
			_, message, err := conn.ReadMessage()
			if err != nil {
				errChan <- err
				return
			}
			readChan <- message
		}
	}()

	// Главный цикл обработки событий (Слушаем сокет, пинг и сигнал остановки)
	for {
		select {
		case sig := <-stopChan:
			handleWsFeederShutdown(sig, srv, conn, nc, logger)

			return nil

		case <-pingDone:
			return fmt.Errorf("цикл пинга неожиданно завершился")

		case err = <-errChan:
			return fmt.Errorf("ошибка чтения из сокета: %w", err)

		case rawMsg := <-readChan:
			f.lastHeartbeat = time.Now()
			var gateResp GateResponse
			if err = json.Unmarshal(rawMsg, &gateResp); err != nil {
				logger.Warn("unmarshal ws msg to gate response error", zap.Error(err))
				continue
			}

			// Пропускаем служебные ответы
			if gateResp.Channel == "futures.pong" || gateResp.Event == "subscribe" {
				continue
			}

			// Если биржа прислала ошибку на операцию
			if gateResp.Error != nil {
				logger.Error(
					"Ошибка от Gate.io",
					zap.Int("code", gateResp.Error.Code),
					zap.String("message", gateResp.Error.Message),
				)
				continue
			}

			// ОТПРАВКА В NATS JETSTREAM
			// В качестве Subject используем имя канала (например, futures.orders)
			_, pubErr := f.js.Publish(gateResp.Channel, rawMsg)
			if pubErr != nil {
				logger.Error("КРИТИЧЕСКАЯ ОШИБКА: Не удалось отправить сообщение в NATS", zap.Error(pubErr))
			} else {
				logger.Info("Доставлено в NATS", zap.String("subject", gateResp.Channel))
			}
		}
	}
}

// authenticate аутентификация при подключении к ws
func (f *Feeder) authenticate(conn *websocket.Conn, logger *zap.Logger) error {
	ts := time.Now().Unix()

	signString := fmt.Sprintf("api\n%s\n\n%d", loginChannel, ts)
	mac := hmac.New(sha512.New, []byte(f.apiSecret))
	mac.Write([]byte(signString))
	signature := hex.EncodeToString(mac.Sum(nil))

	req := GateRequest{
		Time:    ts,
		Channel: loginChannel,
		Event:   "api",
		Payload: map[string]any{
			"api_key":   f.apiKey,
			"signature": signature,
			"timestamp": strconv.FormatInt(ts, 10),
			"req_id":    strconv.FormatInt(time.Now().UnixNano()/1e6, 10) + "-1",
		},
	}

	if err := conn.WriteJSON(req); err != nil {
		return fmt.Errorf("отправка сообщения авторизации: %w", err)
	}
	logger.Info("Сообщение авторизации отправлено")

	// Ждем ответ авторизации в течение 5 секунд
	_ = conn.SetReadDeadline(time.Now().Add(5 * time.Second))
	_, msg, err := conn.ReadMessage()
	_ = conn.SetReadDeadline(time.Time{}) // сбрасываем таймаут
	if err != nil {
		return fmt.Errorf("таймаут ответа авторизации: %w", err)
	}

	var resp GateResponse
	if err = json.Unmarshal(msg, &resp); err != nil {
		return fmt.Errorf("unmarshal response error: %w", err)
	}

	if status, ok := resp.Header["status"].(string); !ok || status != "200" {
		return fmt.Errorf("отказ авторизации, сырой ответ: %s", string(msg))
	}

	logger.Info("Авторизация на Gate.io пройдена успешно")

	return nil
}

// subscribeChannel подписка на каналы ws
func (f *Feeder) subscribeChannel(conn *websocket.Conn, channel string, payload []any, isAuth bool) error {
	ts := time.Now().Unix()
	signStr := fmt.Sprintf("channel=%s&event=subscribe&time=%d", channel, ts)
	mac := hmac.New(sha512.New, []byte(f.apiSecret))
	mac.Write([]byte(signStr))
	signature := hex.EncodeToString(mac.Sum(nil))

	if isAuth {
		req := GateRequest{
			Id:      time.Now().UnixNano() / 1000,
			Time:    ts,
			Channel: channel,
			Event:   "subscribe",
			Payload: payload,
			Auth: &GateAuthBlock{
				Method: "api_key",
				KEY:    f.apiKey,
				SIGN:   signature,
			},
		}

		return conn.WriteJSON(req)
	}

	req := UnAuthGateRequest{
		Time:    ts,
		Channel: channel,
		Event:   "subscribe",
		Payload: payload,
	}

	return conn.WriteJSON(req)
}

// startPingLoop проверка подключения к ws
func (f *Feeder) startPingLoop(conn *websocket.Conn, done chan struct{}, logger *zap.Logger) {
	defer close(done)
	ticker := time.NewTicker(pingInterval)
	defer ticker.Stop()

	for range ticker.C {
		pingReq := map[string]any{
			"channel": "futures.ping",
			"time":    time.Now().UnixNano() / 1e6,
		}
		if err := conn.WriteJSON(pingReq); err != nil {
			logger.Warn("Ошибка отправки пинга", zap.Error(err))
			return
		}
	}
}

// startHealthCheckServer Хелсчек сервер для Kubernetes
func startHealthCheckServer(srv *http.Server, port string, nc *nats.Conn, f *Feeder, log *zap.Logger) {
	http.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		// Если от биржи не было вестей больше 1 минуты — сокет мертв!
		if time.Since(f.lastHeartbeat) > 1*time.Minute {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}

		// Проверяем живое ли соединение с NATS
		if !nc.IsConnected() {
			log.Error("Readiness failed: NATS disconnected")
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}

		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("OK"))
	})

	log.Info("Health-check сервер запущен на порту :" + port)
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Error("Ошибка хелсчек сервера", zap.Error(err))
	}
}

// handleWsFeederShutdown обеспечивает Graceful Shutdown
func handleWsFeederShutdown(
	sig os.Signal,
	srv *http.Server,
	conn *websocket.Conn,
	natsConn *nats.Conn,
	log *zap.Logger,
) {
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	log.Warn(
		"Получен сигнал остановки, запускаю Graceful Shutdown WS FEEDER...",
		zap.String("signal", sig.String()),
	)

	// Красиво закрываем WebSocket по протоколу
	err := conn.WriteMessage(websocket.CloseMessage, websocket.FormatCloseMessage(websocket.CloseNormalClosure, ""))
	if err != nil {
		log.Warn("send close message error", zap.Error(err))
	}

	// Останавливаем HTTP-сервер
	log.Info("Остановка HTTP-сервера...")
	if err = srv.Shutdown(shutdownCtx); err != nil {
		log.Warn("Ошибка остановки сервера, принудительное закрытие", zap.Error(err))
		err = srv.Close()
		if err != nil {
			log.Warn("Ошибка принудительного закрытия", zap.Error(err))
		}
	}

	// Плавно закрываем общее соединение NATS
	if natsConn != nil {
		log.Info("Закрытие общего соединения NATS...")
		if err = natsConn.Drain(); err != nil {
			log.Warn("Ошибка при общем Drain NATS", zap.Error(err))
		}
	}

	log.Info("=== WS FEEDER УСПЕШНО ОСТАНОВЛЕН ===")
}
```
