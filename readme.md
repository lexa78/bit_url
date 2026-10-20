```
package trading

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
	"trading-bot/internal/domain"
	"trading-bot/internal/gate"
	"trading-bot/internal/helper"
	"trading-bot/internal/storage"
	"trading-bot/internal/storage/redisstore"
	"trading-bot/internal/telemetry"

	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

var actionAsString = map[int]string{
	1: "Long",
	2: "Short",
	0: "Hold",
}

// QSmoothState описывает историческую инерцию Q-значений для модели
type QSmoothState struct {
	SmoothHold  float64 `json:"smooth_hold"`
	SmoothLong  float64 `json:"smooth_long"`
	SmoothShort float64 `json:"smooth_short"`
	SmoothClose float64 `json:"smooth_close"`
}

// GetQSmoothState достает из Redis сглаженные значения за прошлый 15-минутный тик
func (e *Engine) GetQSmoothState(symbol string) (QSmoothState, bool) {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	key := redisstore.QSmoothKey(symbol)
	val, err := e.commonHelper.Rdb.Get(ctx, key).Result()
	if err != nil {
		if !errors.Is(err, redis.Nil) {
			e.commonHelper.Log.Warn("GetQSmoothState from cache", zap.String("symbol", symbol), zap.Error(err))
		}
		// Если ключа нет (первый запуск), возвращаем пустой стейт и false
		return QSmoothState{}, false
	}

	var state QSmoothState
	if err = json.Unmarshal([]byte(val), &state); err != nil {
		e.commonHelper.Log.Warn("unmarshal QSmoothState", zap.String("key", key), zap.Error(err))

		return QSmoothState{}, false
	}
	return state, true
}

// SaveQSmoothState обновляет инерцию в Redis
func (e *Engine) SaveQSmoothState(symbol string, state QSmoothState) error {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	data, err := json.Marshal(state)
	if err != nil {
		return fmt.Errorf("marshal QSmoothState: %w", err)
	}

	key := redisstore.QSmoothKey(symbol)
	// Храним стейт неделю - этого с головой хватит между тиками
	return e.commonHelper.Rdb.Set(ctx, key, data, redisstore.TTLQSmoothCache).Err()
}

// CalculateLiveRSI Считаем классический RSI за 14 периодов строго по математике env.go
func CalculateLiveRSI(dbCandles []domain.DbCandle) float64 {
	if len(dbCandles) < 15 {
		return 50.0
	}

	var gains, losses float64
	// В массиве 15 свечей (индексы 0-14). Считаем изменения цен закрытия
	for i := 1; i < 15; i++ {
		diff := dbCandles[i].Close - dbCandles[i-1].Close
		if diff > 0 {
			gains += diff
		} else {
			losses -= diff
		}
	}

	if losses == 0 {
		return 100.0
	}

	rs := gains / losses
	return 100.0 - (100.0 / (1.0 + rs))
}

// ApplyQSmoothing берет сырые Q-значения из нейросети, применяет фильтр EMA и выбирает Action
func (e *Engine) ApplyQSmoothing(symbol string, rawQ []float64) int {
	// rawQ содержит 4 элемента: [0]=Hold, [1]=Long, [2]=Short, [3]=Close
	oldState, found := e.GetQSmoothState(symbol)

	// Коэффициенты: альфа 0.3 (свежий сигнал), инерция 0.7 (прошлое)
	alpha := 0.3
	inertia := 0.7

	if !found {
		// Если бот только что запустился в Кубере, инициализируем фильтр текущими сырыми значениями
		oldState = QSmoothState{
			SmoothHold:  rawQ[0],
			SmoothLong:  rawQ[1],
			SmoothShort: rawQ[2],
			SmoothClose: rawQ[3],
		}
	}

	// Считаем новое сглаженное состояние
	newState := QSmoothState{
		SmoothHold:  (rawQ[0] * alpha) + (oldState.SmoothHold * inertia),
		SmoothLong:  (rawQ[1] * alpha) + (oldState.SmoothLong * inertia),
		SmoothShort: (rawQ[2] * alpha) + (oldState.SmoothShort * inertia),
		SmoothClose: (rawQ[3] * alpha) + (oldState.SmoothClose * inertia),
	}

	// Сохраняем обновленный стейт в Redis, чтобы вытащить его через 15 минут
	err := e.SaveQSmoothState(symbol, newState)
	if err != nil {
		e.commonHelper.Log.Warn("Не удалось сохранить обновленный стейт в Redis", zap.Error(err))
	}

	// Находим argMax среди сглаженных значений
	smoothValues := []float64{newState.SmoothHold, newState.SmoothLong, newState.SmoothShort, newState.SmoothClose}
	maxIdx := 0
	maxVal := smoothValues[0]
	for i := 1; i < len(smoothValues); i++ {
		if smoothValues[i] > maxVal {
			maxVal = smoothValues[i]
			maxIdx = i
		}
	}

	return maxIdx
}

// account DTO для получения баланса
type account struct {
	Available     string `json:"available,omitempty"`
	Total         string `json:"total,omitempty"`
	UnrealisedPnl string `json:"unrealised_pnl,omitempty"`
}

// tickerMarkPrice DTO для получения текущей цены монеты
type tickerMarkPrice struct {
	MarkPrice float64 `json:"mark_price,string"`
}

// Engine главный оркестратор живых торгов
type Engine struct {
	candleRepo   storage.CandleRepository   // Интерфейс работы со свечами в БД
	orderRepo    storage.OrderRepository    // Интерфейс работы с ордерами в БД
	symbols      []string                   // Список торгуемых монет (например, ["SOL_USDT"])
	nnModels     map[string]*domain.Network // Загруженные в память нейросети для каждого токена
	js           jetstream.JetStream
	commonHelper *helper.Common
	netLogger    *zap.Logger
	mu           sync.RWMutex
}

// NewEngine инициализирует торговый движок и подгружает веса ИИ для всех монет
func NewEngine(
	symbols []string,
	candleRepo storage.CandleRepository,
	orderRepo storage.OrderRepository,
	js jetstream.JetStream,
	commonHelper *helper.Common,
	netLogger *zap.Logger,
) (*Engine, error) {
	engine := &Engine{
		candleRepo:   candleRepo,
		orderRepo:    orderRepo,
		symbols:      symbols,
		nnModels:     make(map[string]*domain.Network),
		js:           js,
		commonHelper: commonHelper,
		netLogger:    netLogger,
	}

	// Каскадно подгружаем сохраненные JSON-модели для каждой монеты из списка
	for _, symbol := range symbols {
		net := domain.NewNetwork(StateDim, HiddenDim, ActionDim, LR, netLogger)
		modelPath := fmt.Sprintf(commonHelper.Cfg.ModelPath, symbol)

		commonHelper.Log.Info(
			"Загрузка весов ИИ для живых торгов",
			zap.String("symbol", symbol),
			zap.String("path", modelPath))
		if err := net.LoadFromFile(modelPath); err != nil {
			return nil, fmt.Errorf("критическая ошибка: не удалось загрузить веса для %s: %w", symbol, err)
		}

		engine.mu.Lock()
		engine.nnModels[symbol] = &net
		engine.mu.Unlock()
	}

	return engine, nil
}

// ExecuteLiveStep выполняет один полный цикл проверки рынка (вызывается раз в 15 минут)
func (e *Engine) ExecuteLiveStep() {
	e.commonHelper.Log.Info("=== [ENGINE] НАЧАЛО 15-МИНУТНОГО СКАНИРОВАНИЯ РЫНКА ===")

	// Получаем актуальный баланс с биржи или из Redis
	balanceUSDT, err := e.GetAvailableBalance()
	if err != nil {
		e.commonHelper.Log.Warn(
			"Failed to get available balance",
			zap.Error(err),
		)
	}

	for _, symbol := range e.symbols {
		// Скачиваем последние 2 свечи с биржи
		// Качаем 2 свечи, чтобы гарантированно зацепить и новую только что открытую, и предыдущую закрытую
		// т.к. в FetchHistoryCandles пропускаются свечи, у которых хоть один параметр незаполнен,
		// то тут мы будем иметь всего одну нужную свечу в срезе, т.к. у только открытой volume == 0
		freshCandles, err := gate.FetchHistoryCandles(symbol, e.commonHelper.Client, "15m", 2, 0, 0, e.commonHelper.Log)
		if err != nil {
			e.commonHelper.Log.Error("Не удалось скачать свежие свечи", zap.String("symbol", symbol), zap.Error(err))
			continue
		}

		// Защита: если биржа из-за сбоя вернула пустой массив
		if len(freshCandles) == 0 {
			e.commonHelper.Log.Warn(
				"Биржа вернула недостаточно свечей, пропускаю шаг",
				zap.String("symbol", symbol),
			)
			continue
		}

		// в нашем freshCandles должна быть только одна нужная свеча
		// если их больше, то мы все равно берем 0, т.к. она самая старая по вермени
		closedCandle := freshCandles[0]

		// В базу пишем именно закрытую свечу, чтобы история была кристально чистой
		err = e.candleRepo.SaveCandle(domain.DbCandle{
			OpenTime: closedCandle.Time,
			Symbol:   symbol,
			Open:     closedCandle.Open,
			High:     closedCandle.High,
			Low:      closedCandle.Low,
			Close:    closedCandle.Close,
			Volume:   closedCandle.Volume,
		})
		if err != nil {
			e.commonHelper.Log.Error(
				"Ошибка записи свежей свечи в БД",
				zap.String("symbol", symbol),
				zap.Error(err),
			)
			continue
		}

		// Считаем количество обработанных свечей
		telemetry.CandlesProcessedTotal.WithLabelValues(symbol).Inc()

		// Формируем вектор состояния для ИИ
		// Достаем из БД последние 15 свечей (включая только что сохраненную)
		dbCandles, err := e.candleRepo.GetLastCandles(symbol, 15)
		if err != nil || len(dbCandles) < 15 {
			e.commonHelper.Log.Error("Недостаточно свечей в БД для работы ИИ", zap.String("symbol", symbol))
			continue
		}

		state := domain.NewMatrix(1, StateDim)
		for i := 0; i < 15; i++ {
			if i > 0 {
				prev := dbCandles[i-1].Close
				curr := dbCandles[i].Close
				state.Set(0, i, (curr-prev)/prev*10.0) // Нормализация изменения цены * 10
			} else {
				state.Set(0, i, 0.0)
			}
		}

		// Запрашиваем состояние аккаунта
		position, err := e.GetPositionInfo(symbol)
		if err != nil {
			e.commonHelper.Log.Error("Ошибка опроса позиций Gate.io", zap.String("symbol", symbol), zap.Error(err))
			continue
		}

		var (
			realPosType  int
			currentPrice float64
			currentSize  float64
		)

		// если дошли сюда, то можно получать текущую цену монеты
		tickerInfo, err := e.getCurrentPrice(symbol)
		if err != nil {
			e.commonHelper.Log.Error("Ошибка получения текущей цены", zap.String("symbol", symbol), zap.Error(err))
			currentPrice = closedCandle.Close
		} else {
			currentPrice = tickerInfo.MarkPrice
		}

		// На фьючерсах направление определяется знаком в поле "size"
		// size > 0-LONG, size < 0-SHORT, size == 0-позиции нет
		if position == nil {
			realPosType = 0
			currentSize = 0
		} else {
			currentSize = position.Size
			switch {
			case currentSize > 0:
				realPosType = 1
			case currentSize < 0:
				realPosType = -1
			default:
				realPosType = 0
			}
		}

		// Фиксируем размер текущей открытой позиции
		telemetry.PositionSize.WithLabelValues(symbol).Set(currentSize)

		// Заполняем 16-й и 17-й элементы вектора
		state.Set(0, 15, float64(realPosType)*0.1)
		entryDistPct := 0.0
		if realPosType != 0 && position.EntryPrice > 0 {
			entryDistPct = (currentPrice - position.EntryPrice) / position.EntryPrice * 10.0
		}
		state.Set(0, 16, entryDistPct)

		// 18-й элемент: Считаем RSI один в один как в env.go
		rsiVal := CalculateLiveRSI(dbCandles)
		state.Set(0, 17, (rsiVal-50.0)/50.0)

		// Получаем вердикт DQN модели и применяем Q-Smoothing (Redis)
		e.mu.RLock()
		net := e.nnModels[symbol]
		e.mu.RUnlock()
		res := net.ForwardDQN(state)
		rawQ := []float64{res.A2.Get(0, 0), res.A2.Get(0, 1), res.A2.Get(0, 2), res.A2.Get(0, 3)}

		// Фильтр низких частот (EMA)
		aiAction := e.ApplyQSmoothing(symbol, rawQ)

		e.commonHelper.Log.Info("Вердикт модели ИИ",
			zap.String("symbol", symbol),
			zap.Int("raw_action", aiAction),
			zap.String("str_action", actionAsString[aiAction]),
			zap.Int("current_pos", realPosType),
		)

		// Собираем информацию о закрывающем ордере. Если будет сигнал переворота или закрытия, отправим в NATS
		var wasEvent bool
		closeOrderEvent := domain.CloseOrder{
			Symbol:         symbol,
			CurrentBalance: balanceUSDT,
			CreatedAt:      time.Now(),
		}

		// ИСПОЛНЕНИЕ ОРДЕРОВ
		switch aiAction {
		case 0: // HOLD
			e.commonHelper.Log.Info(
				"Модель выбрала удержание (Hold). Никаких действий не требуется.",
				zap.String("symbol", symbol),
			)

		case 1: // LONG (Открыть Лонг / Переворот из Шорта)
			if realPosType == 1 {
				// Защита от овертрейдинга: ИИ кликает покупку внутри покупки
				e.commonHelper.Log.Warn(
					"Снайперский фильтр: Блокировка повторного открытия LONG",
					zap.String("symbol", symbol),
				)
				continue
			}

			// Если мы были в Шорте, сначала закрываем его
			if realPosType == -1 {
				e.commonHelper.Log.Info(
					"Сигнал Переворота! Закрываем текущую позицию SHORT перед входом в LONG",
					zap.String("symbol", symbol),
				)
				var (
					closeErr error
					orderId  int64
				)
				// Передаем currentSize со знаком плюс (так как закрываем шорт покупкой)
				orderId, closeErr = e.PlaceFuturesOrder(
					symbol,
					e.getRightCurrentSizeForDirection(currentSize, domain.OrderDirectionLong),
				)
				if closeErr != nil {
					e.commonHelper.Log.Error("Не удалось закрыть SHORT при перевороте", zap.Error(closeErr))
					continue
				}
				wasEvent = true
				closeOrderEvent.OrderId = orderId
				closeOrderEvent.Side = 1
				closeOrderEvent.TotalSize = currentSize
				closeOrderEvent.EntryPrice = currentPrice
				closeOrderEvent.CloseType = domain.CloseOrderTypeReversal
				currentOrder, err := e.orderRepo.GetCurrentOrder(symbol)
				if err != nil {
					// todo подумать, что делать в таком случае. Прерывать закрытие нельзя
					e.commonHelper.Log.Warn(
						"Не удалось получить текущий ордер. В консюмере будет ошибка записи закрывающего ордера",
						zap.String("symbol", symbol),
						zap.Error(err),
					)
				}
				closeOrderEvent.OrderForClose = currentOrder
			}

			// Открываем чистый LONG
			e.commonHelper.Log.Info("=== ИСПОЛНЕНИЕ: ОТКРЫТИЕ ПОЗИЦИИ LONG ===", zap.String("symbol", symbol))
			err = e.OpenNewPosition(symbol, 1, currentPrice)
			if err != nil {
				e.commonHelper.Log.Error("Критическая ошибка открытия LONG ордера", zap.Error(err))
			}

		case 2: // SHORT (Открыть Шорт / Переворот из Лонга)
			if realPosType == -1 {
				e.commonHelper.Log.Warn(
					"Снайперский фильтр: Блокировка повторного открытия SHORT",
					zap.String("symbol", symbol),
				)
				continue
			}

			// Если мы были в Лонге, сначала закрываем его
			if realPosType == 1 {
				e.commonHelper.Log.Info(
					"Сигнал Переворота! Закрываем текущую позицию LONG перед входом в SHORT",
					zap.String("symbol", symbol),
				)
				var (
					closeErr error
					orderId  int64
				)
				// Передаем currentSize со знаком минус (так как закрываем лонг продажей)
				orderId, closeErr = e.PlaceFuturesOrder(
					symbol,
					e.getRightCurrentSizeForDirection(currentSize, domain.OrderDirectionShort),
				)
				if closeErr != nil {
					e.commonHelper.Log.Error("Не удалось закрыть LONG при перевороте", zap.Error(closeErr))
					continue
				}
				wasEvent = true
				closeOrderEvent.OrderId = orderId
				closeOrderEvent.Side = -1
				closeOrderEvent.TotalSize = currentSize
				closeOrderEvent.EntryPrice = currentPrice
				closeOrderEvent.CloseType = domain.CloseOrderTypeReversal
				currentOrder, err := e.orderRepo.GetCurrentOrder(symbol)
				if err != nil {
					// todo подумать, что делать в таком случае. Прерывать закрытие нельзя
					e.commonHelper.Log.Warn(
						"Не удалось получить текущий ордер. В консюмере будет ошибка записи закрывающего ордера",
						zap.String("symbol", symbol),
						zap.Error(err),
					)
				}
				closeOrderEvent.OrderForClose = currentOrder
			}

			// Открываем чистый SHORT
			e.commonHelper.Log.Info("=== ИСПОЛНЕНИЕ: ОТКРЫТИЕ ПОЗИЦИИ SHORT ===", zap.String("symbol", symbol))
			err = e.OpenNewPosition(symbol, -1, currentPrice)
			if err != nil {
				e.commonHelper.Log.Error("Критическая ошибка открытия SHORT ордера", zap.Error(err))
			}

		case 3: // CLOSE POSITION
			if realPosType != 0 {
				e.commonHelper.Log.Info(
					"=== ИСПОЛНЕНИЕ: ПРИНУДИТЕЛЬНОЕ ЗАКРЫТИЕ ПОЗИЦИИ МОДЕЛЬЮ ===",
					zap.String("symbol", symbol),
				)
				var (
					closeErr error
					side     int
					orderId  int64
				)
				if realPosType == 1 {
					// Были в Лонге -> шлем минус размер
					orderId, closeErr = e.PlaceFuturesOrder(
						symbol,
						e.getRightCurrentSizeForDirection(currentSize, domain.OrderDirectionShort),
					)
					side = -1
				} else {
					// Были в Шорте -> шлем плюс размер
					orderId, closeErr = e.PlaceFuturesOrder(
						symbol,
						e.getRightCurrentSizeForDirection(currentSize, domain.OrderDirectionLong),
					)
					side = 1
				}

				if closeErr != nil {
					e.commonHelper.Log.Error("Ошибка принудительного закрытия позиции", zap.Error(closeErr))
					continue
				}
				wasEvent = true
				closeOrderEvent.OrderId = orderId
				closeOrderEvent.Side = side
				closeOrderEvent.TotalSize = currentSize
				closeOrderEvent.EntryPrice = currentPrice
				closeOrderEvent.CloseType = domain.CloseOrderTypeForce
				currentOrder, err := e.orderRepo.GetCurrentOrder(symbol)
				if err != nil {
					// todo подумать, что делать в таком случае. Прерывать закрытие нельзя
					e.commonHelper.Log.Warn(
						"Не удалось получить текущий ордер. В консюмере будет ошибка записи закрывающего ордера",
						zap.String("symbol", symbol),
						zap.Error(err),
					)
				}
				closeOrderEvent.OrderForClose = currentOrder
			} else {
				e.commonHelper.Log.Warn(
					"Модель нажала CLOSE, но открытых позиций на бирже нет. Игнорируем спам.",
					zap.String("symbol", symbol),
				)
			}
		}
		// Отправляем событие о закрытии ордера в NATS
		if wasEvent {
			eventJSON, jsonErr := json.Marshal(closeOrderEvent)
			if jsonErr != nil {
				e.commonHelper.Log.Warn(
					"marshal closeOrderEvent error. closeOrderEvent didn't send to NATS",
					zap.Error(jsonErr),
				)
			} else {
				err = e.publishEvent(domain.EventOrderClose, string(eventJSON), 0)
				if err != nil {
					e.commonHelper.Log.Error(
						"Не удалось отправить сообщение в NATS",
						zap.String("subject", domain.EventOrderClose),
						zap.ByteString("message", eventJSON),
						zap.Error(err),
					)
				}
				e.commonHelper.Log.Info(
					"Ордер на закрытие успешно отправлен в NATS",
					zap.String("symbol", symbol),
				)
			}
		}
		// Даем сетевому буферу NATS время отправить пакет и получить PubAck
		// 100 миллисекунд полностью снимут сетевую гонку, не замедляя торговлю
		time.Sleep(100 * time.Millisecond)
	}
}

// OpenNewPosition рассчитывает объемы по риск-менеджменту env.go и шлет ордер на биржу
func (e *Engine) OpenNewPosition(symbol string, side int, currentPrice float64) error {
	// Получаем актуальный баланс с биржи или из Redis
	balanceUSDT, err := e.GetAvailableBalance()
	if err != nil {
		e.commonHelper.Log.Warn(
			"Failed to get available balance",
			zap.Error(err),
		)
	}

	if balanceUSDT == 0.0 {
		return fmt.Errorf("была ошибка получения баланса. См. warn Failed to get available balance")
	}

	if balanceUSDT <= 5.0 {
		return fmt.Errorf("недостаточно средств на балансе: %f", balanceUSDT)
	}

	// Считаем полный объем позиции (в контрактах или лотах) строго по env.go
	riskRate := 0.02    // Риск 2% на сделку
	stopLossPct := 0.01 // Первоначальный стоп 1%

	riskUSDT := balanceUSDT * riskRate
	positionValue := riskUSDT / stopLossPct

	quantoMultiplier, err := e.commonHelper.GetQuantoMultiplier(symbol)
	if err != nil {
		// todo придумать, что делать, если тут ошибка
		quantoMultiplier = 1.0
	}

	// Считаем, сколько всего монет мы можем купить на наш баланс (float64)
	totalCoins := positionValue / currentPrice

	// Переводим монеты в КОНТРАКТЫ Gate.io
	// Делим количество монет на размер одного контракта
	totalSize := int(totalCoins / quantoMultiplier)
	if totalSize < 3 {
		return fmt.Errorf("слишком маленький баланс, невозможно разделить объем %d на 3 части каскада", totalSize)
	}

	// Делим объем на 3 части (по 33% объема на каждый TP)
	sizePart := totalSize / 3
	sizeRemainder := totalSize % 3 // Остаток докидываем в TP1

	sizeTP1 := sizePart + sizeRemainder
	sizeTP2 := sizePart
	sizeTP3 := sizePart

	// Отправляем основной рыночный ордер на вход
	// Для LONG знак плюс (side = 1), для SHORT знак минус (side = -1)
	orderId, err := e.PlaceFuturesOrder(symbol, side*totalSize)
	if err != nil {
		return fmt.Errorf("биржа отклонила рыночный вход: %w", err)
	}

	// Расчет цен Каскада (TP1=+1%, TP2=+2%, TP3=+3%, SL=-1%)
	var stopPrice, tp1Price, tp2Price, tp3Price float64

	if side == 1 { // LONG
		stopPrice = currentPrice * (1.0 - 0.01)
		tp1Price = currentPrice * (1.0 + 0.01)
		tp2Price = currentPrice * (1.0 + 0.02)
		tp3Price = currentPrice * (1.0 + 0.03)
	} else { // SHORT
		stopPrice = currentPrice * (1.0 + 0.01)
		tp1Price = currentPrice * (1.0 - 0.01)
		tp2Price = currentPrice * (1.0 - 0.02)
		tp3Price = currentPrice * (1.0 - 0.03)
	}

	// ПУШ В NATS: Собираем полный отчет по всем ценам и объемам для базы данных
	orderEvent := domain.CreateOrder{
		Symbol:         symbol,
		Side:           side,
		TotalSize:      float64(totalSize),
		EntryPrice:     currentPrice,
		StopPrice:      stopPrice,
		Tp1Price:       tp1Price,
		Tp1Size:        sizeTP1,
		Tp2Price:       tp2Price,
		Tp2Size:        sizeTP2,
		Tp3Price:       tp3Price,
		Tp3Size:        sizeTP3,
		OrderId:        orderId,
		CreatedAt:      time.Now(),
		CurrentBalance: balanceUSDT,
	}

	eventJSON, jsonErr := json.Marshal(orderEvent)
	if jsonErr != nil {
		e.commonHelper.Log.Warn("marshal orderEvent error. orderEvent didn't send to NATS", zap.Error(jsonErr))
	} else {
		err = e.publishEvent(domain.EventOrderCreate, string(eventJSON), 10*time.Second)
		if err != nil {
			e.commonHelper.Log.Error(
				"Не удалось отправить сообщение в NATS",
				zap.String("subject", domain.EventOrderCreate),
				zap.ByteString("message", eventJSON),
				zap.Error(err),
			)
		} else {
			e.commonHelper.Log.Info("Каскад ордеров успешно размещен и отправлен в NATS", zap.String("symbol", symbol))
		}
	}

	// считаем количество открытых ордеров
	telemetry.OpenOrdersTotal.WithLabelValues(symbol).Inc()

	return nil
}

// publishEvent Вспомогательный метод для быстрой публикации логов в шину сообщений NATS
func (e *Engine) publishEvent(subject, message string, delay time.Duration) error {
	if e.js == nil {
		return errors.New("nats not initialized")
	}

	// Создаем объект сообщения NATS Отправляем на служебный delayed-subject
	schedulerSubject := subject + ".delayed"
	// Создаем объект сообщения NATS
	msg := nats.NewMsg(schedulerSubject)
	msg.Data = []byte(message)

	// Если передана задержка, добавляем специальный заголовок
	if delay > 0 {
		deliverAt := time.Now().Add(delay).Format(time.RFC3339)
		msg.Header.Set("Nats-Schedule", "@at "+deliverAt)
		msg.Header.Set("Nats-Schedule-Target", subject)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	// Публикуем сообщение в JetStream через PublishMsg
	_, err := e.js.PublishMsg(ctx, msg)
	if err != nil {
		return fmt.Errorf("nats publish event: %w", err)
	}

	return nil
}

// GetPositionInfo возвращает объект PositionFromApi
func (e *Engine) GetPositionInfo(symbol string) (*domain.PositionFromApi, error) {
	positionObj, found := e.getPositionInfo(symbol)

	if found {
		return &positionObj, nil
	}

	// если в кэше позиции нет, идем в API
	host := e.commonHelper.Cfg.ApiHost
	path := "/api/v4/futures/usdt/positions"
	u := &url.URL{
		Scheme: "https",
		Host:   host,
		Path:   path,
	}

	q := url.Values{}
	q.Set("holding", "true")
	u.RawQuery = q.Encode()

	req, err := http.NewRequest("GET", u.String(), nil)
	if err != nil {
		return nil, fmt.Errorf("create get position request: %w", err)
	}

	// Генерируем заголовки подписи (для GET запроса тело пустое "")
	headers := e.commonHelper.Client.SignGateV4("GET", path, q, "", e.commonHelper.Cfg.ApiKey, e.commonHelper.Cfg.ApiSecret)
	for key, val := range headers {
		req.Header.Set(key, val)
	}

	resp, err := e.commonHelper.Client.Do(req, "")

	if err != nil {
		return nil, fmt.Errorf("get positions exec: %w", err)
	}

	defer func(Body io.ReadCloser) {
		err = Body.Close()
		if err != nil {
			e.commonHelper.Log.Warn("Failed to close response body", zap.Error(err))
		}
	}(resp.Body)

	// Парсим ответ (Gate возвращает объект позиции)
	var rawData []domain.PositionFromApi
	if err = json.NewDecoder(resp.Body).Decode(&rawData); err != nil {
		return nil, fmt.Errorf("decode positions body: %w", err)
	}

	if len(rawData) == 0 {
		return nil, nil
	}

	for _, item := range rawData {
		if item.Symbol == symbol {
			positionObj = item
			break
		}
	}
	if positionObj.Size == 0.0 {
		// почему-то получили позицию без количества, либо не получили вообще
		e.commonHelper.Log.Warn("necessary symbol not found", zap.String("symbol", symbol))
		return nil, nil
	}

	//кэшируем
	err = e.cachePosition(symbol, positionObj)
	if err != nil {
		e.commonHelper.Log.Error("Не удалось сохранить позицию в кэш", zap.String("symbol", symbol), zap.Error(err))
	}

	return &positionObj, nil
}

// PlaceFuturesOrder отправляет рыночный ордер на демо-счет.
// size передается со знаком: положительный-BUY, отрицательный-SELL.
func (e *Engine) PlaceFuturesOrder(symbol string, size int) (int64, error) {
	host := e.commonHelper.Cfg.ApiHost
	path := "/api/v4/futures/usdt/orders"

	// Создаем уникальный Client Order ID с префиксом t- по спецификации Gate.io
	// Используем UNIX-наносекунды, чтобы ID гарантированно никогда не повторился
	symbolAsSlice := strings.Split(symbol, "_")
	clientOrderId := fmt.Sprintf("t-%s-%d", strings.ToLower(symbolAsSlice[0]), time.Now().UnixNano())

	// Формируем JSON-тело с поддержкой идемпотентности
	bodyMap := map[string]interface{}{
		"contract": symbol,
		"size":     strconv.Itoa(size),
		"price":    "0",           // Market Price
		"tif":      "ioc",         // Immediate or Cancel
		"text":     clientOrderId, // Передаем наш уникальный токен
	}

	bodyBytes, jsonErr := json.Marshal(bodyMap)
	if jsonErr != nil {
		return 0, fmt.Errorf("marshal bodyMap for place order: %w", jsonErr)
	}
	bodyStr := string(bodyBytes)

	// Задаем жесткий, но адекватный контекстный таймаут (30 секунд) для ордера
	// Это заставит рантайм Go держать соединение до победного, что критично для лагающего тестнета
	ctx, cancel := context.WithTimeout(context.Background(), domain.ContextTimeoutForSendRequest)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, "POST", fmt.Sprintf("https://%s%s", host, path), strings.NewReader(bodyStr))
	if err != nil {
		return 0, fmt.Errorf("create place order request: %w", err)
	}

	// Подписываем POST запрос с телом
	headers := e.commonHelper.Client.SignGateV4("POST", path, url.Values{}, bodyStr, e.commonHelper.Cfg.ApiKey, e.commonHelper.Cfg.ApiSecret)
	for key, val := range headers {
		req.Header.Set(key, val)
	}

	resp, err := e.commonHelper.Client.Do(req, "")

	if err != nil {
		e.commonHelper.Log.Error("Критический сбой сети при отправке ордера",
			zap.String("symbol", symbol),
			zap.String("client_order_id", clientOrderId),
			zap.String("body", bodyStr),
			zap.Error(err),
		)
		return 0, fmt.Errorf("place order exec network failure: %w", err)
	}

	defer func(Body io.ReadCloser) {
		err = Body.Close()
		if err != nil {
			e.commonHelper.Log.Warn("Failed to close response body", zap.Error(err))
		}
	}(resp.Body)

	// Если биржа вернула плохой статус (например, 400 из-за дубликата `text`)
	if resp.StatusCode != http.StatusCreated && resp.StatusCode != http.StatusOK {
		e.commonHelper.Log.Warn(
			"Биржа отклонила ордер по бизнес-логике",
			zap.Int("status", resp.StatusCode),
			zap.String("client_order_id", clientOrderId),
			zap.String("body", bodyStr),
		)
		return 0, fmt.Errorf("биржа отклонила ордер, статус: %s", resp.Status)
	}

	rb := domain.RespBody{}
	err = json.NewDecoder(resp.Body).Decode(&rb)
	if err != nil {
		return 0, fmt.Errorf("decode place order response: %w", err)
	}

	e.commonHelper.Log.Info("Ордер успешно подтвержден биржей через REST",
		zap.String("symbol", symbol),
		zap.Int64("order_id", rb.Id),
		zap.String("client_order_id", clientOrderId),
	)
	return rb.Id, nil
}

// GetAvailableBalance запрашивает доступный баланс в USDT с фьючерсного демо-счета
func (e *Engine) GetAvailableBalance() (float64, error) {
	var accountObj account

	ctx, cancel := context.WithTimeout(context.Background(), domain.ContextTimeoutForSendRequest)
	defer cancel()

	key := redisstore.CurrentBalanceKey()
	accountObjJson, err := e.commonHelper.Rdb.Get(ctx, key).Result()
	if err == nil {
		e.commonHelper.Log.Info("Got balance from redis", zap.String("key", key))

		err = json.Unmarshal([]byte(accountObjJson), &accountObj)
		if err != nil {
			return 0, fmt.Errorf("decode account from cache: %w", err)
		}

		if accountObj.Available == "" || accountObj.Total == "" || accountObj.UnrealisedPnl == "" {
			return 0, fmt.Errorf("не удалось распарсить одно из полей баланса из строки кэша")
		}
	} else {
		if !errors.Is(err, redis.Nil) {
			e.commonHelper.Log.Warn("get current balance from redis failed", zap.Error(err))
		}
		//если баланса в кэше нет, то берем из API

		host := e.commonHelper.Cfg.ApiHost
		path := "/api/v4/futures/usdt/accounts"

		req, err := http.NewRequest("GET", fmt.Sprintf("https://%s%s", host, path), nil)
		if err != nil {
			return 0, fmt.Errorf("create get balance request: %w", err)
		}

		// Генерируем заголовки подписи для GET-запроса (тело пустое "")
		headers := e.commonHelper.Client.SignGateV4("GET", path, url.Values{}, "", e.commonHelper.Cfg.ApiKey, e.commonHelper.Cfg.ApiSecret)
		for key, val := range headers {
			req.Header.Set(key, val)
		}

		resp, err := e.commonHelper.Client.Do(req, "")

		if err != nil {
			return 0, fmt.Errorf("get balance exec: %w", err)
		}

		defer func(Body io.ReadCloser) {
			err = Body.Close()
			if err != nil {
				e.commonHelper.Log.Warn("Failed to close response body", zap.Error(err))
			}
		}(resp.Body)

		if err = json.NewDecoder(resp.Body).Decode(&accountObj); err != nil {
			return 0, fmt.Errorf("decode account body: %w", err)
		}

		if accountObj.Available == "" || accountObj.Total == "" || accountObj.UnrealisedPnl == "" {
			return 0, fmt.Errorf("не удалось распарсить одно из полей баланса в ответе биржи")
		}

		// сохраняем в кэш
		data, err := json.Marshal(accountObj)
		if err != nil {
			e.commonHelper.Log.Error("marshal accountObj Balance info didn't save to cache", zap.Error(err))
		} else {
			err = e.commonHelper.Rdb.Set(ctx, key, data, redisstore.TTLCurrentBalanceCache).Err()
			if err != nil {
				e.commonHelper.Log.Error("save accountObj Balance info didn't save to cache", zap.Error(err))
			}
		}
	}
	accountVal, err := strconv.ParseFloat(accountObj.Available, 64)
	if err != nil {
		return 0, fmt.Errorf("parse account available: %w", err)
	}
	// Фиксируем финальный баланс в Prometheus для визуализации в Grafana
	telemetry.CurrentBalance.WithLabelValues("USDT").Set(accountVal)

	totalVal, err := strconv.ParseFloat(accountObj.Total, 64)
	if err != nil {
		return 0, fmt.Errorf("parse account total: %w", err)
	}
	// Фиксируем общий баланс чтобы в сочетании с UnrealisedPnl было видно, что что-то пошло не так
	telemetry.EquityCurve.WithLabelValues("testnet").Set(totalVal)

	unrealisedPnl, err := strconv.ParseFloat(accountObj.UnrealisedPnl, 64)
	if err != nil {
		return 0, fmt.Errorf("parse account unrealised_pnl: %w", err)
	}
	// Фиксируем общий баланс чтобы в сочетании с Total было видно, что что-то пошло не так
	telemetry.UnrealizedPnL.Set(unrealisedPnl)

	return accountVal, nil
}

// UpdateModel обновляет веса модели после дообучения раз в неделю
func (e *Engine) UpdateModel(symbol string) error {
	net := domain.NewNetwork(StateDim, HiddenDim, ActionDim, LR, e.netLogger)
	modelPath := fmt.Sprintf(e.commonHelper.Cfg.ModelPath, symbol)

	e.commonHelper.Log.Info(
		"Загрузка весов ИИ для живых торгов",
		zap.String("symbol", symbol),
		zap.String("path", modelPath))
	if err := net.LoadFromFile(modelPath); err != nil {
		return fmt.Errorf("критическая ошибка: не удалось загрузить веса для %s: %w", symbol, err)
	}

	e.mu.Lock()
	e.nnModels[symbol] = &net
	e.mu.Unlock()

	return nil
}

// getCurrentPrice получает информацию о текущей цене монеты
func (e *Engine) getCurrentPrice(symbol string) (tickerMarkPrice, error) {
	host := e.commonHelper.Cfg.ApiHost
	path := "/api/v4/futures/usdt/tickers"
	u := &url.URL{
		Scheme: "https",
		Host:   host,
		Path:   path,
	}

	q := url.Values{}
	q.Set("contract", symbol)
	u.RawQuery = q.Encode()

	tickerInfo := make([]tickerMarkPrice, 1)

	ctx, cancel := context.WithTimeout(context.Background(), domain.ContextTimeoutForSendRequest)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, "GET", u.String(), nil)
	if err != nil {
		return tickerMarkPrice{}, fmt.Errorf("create get current price request: %w", err)
	}

	req.Header.Add("Accept", "application/json")

	resp, err := e.commonHelper.Client.Do(req, "")

	if err != nil {
		return tickerMarkPrice{}, fmt.Errorf("get current price exec: %w", err)
	}

	defer func(Body io.ReadCloser) {
		err = Body.Close()
		if err != nil {
			e.commonHelper.Log.Warn("Failed to close response body", zap.Error(err))
		}
	}(resp.Body)

	if err = json.NewDecoder(resp.Body).Decode(&tickerInfo); err != nil {
		return tickerMarkPrice{}, fmt.Errorf("decode contract body: %w", err)
	}

	return tickerInfo[0], nil
}

// cachePosition сохраняет информацию о позиции в Redis
func (e *Engine) cachePosition(symbol string, position domain.PositionFromApi) error {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	data, err := json.Marshal(position)
	if err != nil {
		return fmt.Errorf("marshal position info: %w", err)
	}

	// Храним позицию сутки. Если она в это время изменится, она инвалидируется
	key := redisstore.CurrentPositionKey(symbol)

	return e.commonHelper.Rdb.Set(ctx, key, data, redisstore.TTLCurrentPositionCache).Err()
}

// getPositionInfo достает из Redis информацию о текущей позиции
func (e *Engine) getPositionInfo(symbol string) (domain.PositionFromApi, bool) {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	var position domain.PositionFromApi

	key := redisstore.CurrentPositionKey(symbol)
	val, err := e.commonHelper.Rdb.Get(ctx, key).Result()
	if err != nil {
		if !errors.Is(err, redis.Nil) {
			e.commonHelper.Log.Warn("getPositionInfo from cache", zap.String("symbol", symbol), zap.Error(err))
		}
		// Если ключа нет (первый запуск), возвращаем пустой стейт и false
		return position, false
	}

	if err = json.Unmarshal([]byte(val), &position); err != nil {
		e.commonHelper.Log.Warn("Unmarshal position info error", zap.Error(err))
		return position, false
	}

	e.commonHelper.Log.Info("Got position from redis", zap.String("key", key))

	return position, true
}

func (e *Engine) getRightCurrentSizeForDirection(size float64, sizeForDirection string) int {
	if size == 0.0 {
		return 0
	}

	result := int(size)
	if sizeForDirection == domain.OrderDirectionLong {
		if result < 0 {
			result = result * -1
		}
	} else {
		if result > 0 {
			result = result * -1
		}
	}

	return result
}
```
