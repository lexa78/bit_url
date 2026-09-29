Dockerfile
```
# ==============================================================================
# Сборка (Тяжелый контейнер со всеми инструментами Go)
# ==============================================================================
FROM golang:1.26-alpine AS builder

# Ставим git, сертификаты и таймзоны
RUN apk update && apk add --no-cache git ca-certificates tzdata

WORKDIR /app

# Кэшируем зависимости
COPY go.mod go.sum ./
RUN go mod download

# Копируем весь исходный код проекта
COPY . .

# Компилируем бинарники отдельно с жесткой оптимизацией
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-w -s" -o /bin/bot ./cmd/bot/main.go
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-w -s" -o /bin/consumer ./cmd/consumer/main.go
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-w -s" -o /bin/ws-feeder ./cmd/ws-feeder/main.go
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-w -s" -o /bin/candles-healer ./cmd/candles-healer/main.go
RUN CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-w -s" -o /bin/trainer ./cmd/trainer/main.go


# ==============================================================================
# Финальный образ для ТОРГОВОГО БОТА (Target: bot)
# ==============================================================================
FROM alpine:3.20 AS bot

WORKDIR /app

# Создаем безопасного пользователя, чтобы не запускаться от root
RUN adduser -D -u 10001 trader

# Копируем корневые сертификаты и таймзоны
COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=builder /usr/share/zoneinfo /usr/share/zoneinfo

# Копируем скомпилированный бинарник бота
COPY --from=builder /bin/bot /app/bot
# Корируем папку с миграциями
COPY migrations /app/migrations

# Папку для моделей не копируем! Мы смонтируем её через Volumes в Kubernetes/Compose
# Передаем права на рабочую директорию нашему пользователю
RUN chown -R trader:trader /app
USER trader

# Открываем порт для Prometheus и health-check'ов
EXPOSE 8080

CMD ["./bot"]

## ==============================================================================
## Финальный образ для КОНСЬЮМЕРА (Target: consumer)
## ==============================================================================
FROM alpine:3.20 AS consumer

WORKDIR /app

RUN adduser -D -u 10001 trader

COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=builder /usr/share/zoneinfo /usr/share/zoneinfo
COPY --from=builder /bin/consumer /app/consumer

RUN chown -R trader:trader /app
USER trader

CMD ["./consumer"]

## ==============================================================================
## Финальный образ для WS-FEEDER (Target: ws-feeder)
## ==============================================================================
FROM alpine:3.20 AS ws-feeder

WORKDIR /app

RUN adduser -D -u 10001 trader

COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=builder /usr/share/zoneinfo /usr/share/zoneinfo
COPY --from=builder /bin/ws-feeder /app/ws-feeder

RUN chown -R trader:trader /app
USER trader

CMD ["./ws-feeder"]

# ==============================================================================
# Финальный образ для ХИЛЕРА (Target: candles-healer)
# ==============================================================================
FROM alpine:3.20 AS candles-healer

WORKDIR /app

RUN adduser -D -u 10001 trader

COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=builder /usr/share/zoneinfo /usr/share/zoneinfo
COPY --from=builder /bin/candles-healer /app/candles-healer

RUN chown -R trader:trader /app
USER trader

CMD ["./candles-healer"]

# ==============================================================================
# Финальный образ для ТРЕЙНЕРА (Target: trainer)
# ==============================================================================
FROM alpine:3.20 AS trainer

WORKDIR /app

RUN adduser -D -u 10001 trader

COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=builder /usr/share/zoneinfo /usr/share/zoneinfo
COPY --from=builder /bin/trainer /app/trainer

RUN chown -R trader:trader /app
USER trader

CMD ["./trainer"]
```

docker-compose
```
services:
  # --- ИНФРАСТРУКТУРА ДАННЫХ ---
  postgres:
    image: postgres:15-alpine
    container_name: crypto-postgres
    environment:
      POSTGRES_USER: crypto_user
      POSTGRES_PASSWORD: crypto_password
      POSTGRES_DB: crypto_market
    ports:
      - "${COMP_DB_PORT}:${DB_PORT}"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U crypto_user -d crypto_market"]
      interval: 5s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: crypto-redis
    ports:
      - "${COMP_REDIS_PORT}:${REDIS_PORT}"
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 5

  nats:
    image: nats:2.10-alpine
    container_name: crypto-nats
    ports:
      - "${COMP_NATS_PORT}:${NATS_PORT}" # Клиентский порт
      - "${COMP_NATS_MONITORING_PORT}:${NATS_MONITORING_PORT}" # Мониторинг HTTP
    command: ["nats-server", "-js", "-m", "8222", "-sd", "/data"]
    volumes:
      - nats_data:/data
    healthcheck:
      # Просто пингуем открытый порт 4222 через netcat.
      # Если порт отвечает — значит сервер готов принимать конейны.
      test: [ "CMD", "nc", "-z", "localhost", "4222" ]
      interval: 3s
      timeout: 2s
      retries: 5

  # --- МОНИТОРИНГ ---
  prometheus:
    image: prom/prometheus:v2.45.0
    container_name: crypto-prometheus
    ports:
      - "${COMP_PT_PORT}:${PT_PORT}"
    dns:
      - 8.8.8.8  # <--- Добавляем публичный DNS Google, чтобы ожил Telegram
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus_data:/prometheus
    depends_on:
      - bot

  grafana:
    image: grafana/grafana:11.1.3
    container_name: crypto-grafana
    ports:
      - "${COMP_GF_PORT}:${GF_PORT}"
    dns:
      - 8.8.8.8  # <--- Добавляем публичный DNS Google, чтобы ожил Telegram
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=${GF_SECURITY_ADMIN_PASSWORD}
    volumes:
      - ./grafana-data:/var/lib/grafana
      - ./grafana-data/provisioning:/etc/grafana/provisioning
    depends_on:
      - prometheus

  # --- НАШИ ПРИЛОЖЕНИЯ (GO СТЕК) ---
  bot:
    build:
      context: .
      dockerfile: Dockerfile
      target: bot
    container_name: crypto-bot
    environment:
#      - DB_DSN=postgres://${DB_USER}:${DB_PASSWORD}@postgres:${DB_PORT}/${DB_NAME}?sslmode=disable
      - DB_HOST=postgres
      - DB_USER=${DB_USER}
      - DB_PASSWORD=${DB_PASSWORD}
      - DB_PORT=${DB_PORT}
      - DB_NAME=${DB_NAME}
      - REDIS_PORT=${REDIS_PORT}
      - REDIS_HOST=redis
      - NATS_PORT=${NATS_PORT}
      - NATS_PROTOCOL=nats
      - NATS_HOST=nats
      - MAIN_PROMETHEUS_ADDR=${MAIN_PROMETHEUS_ADDR}
      - MODEL_PATH=/app/models/dqn_%s_model.json
    env_file:
      - .env  # Docker Compose сам прочитает файл и засунет переменные в os.Getenv
    ports:
      - "${COMP_MAIN_HEALTHCHECK_PORT}:${MAIN_HEALTHCHECK_PORT}"
    volumes:
      - ./models:/app/models
    logging:
      driver: "json-file"
      options:
        max-size: "10m"   # Максимальный размер одного файла лога 10MB
        max-file: "3"     # Хранить максимум 3 таких файлов. Старые будут стираться автоматически.
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy
      nats:
        condition: service_healthy
  consumer:
    build:
      context: .
      dockerfile: Dockerfile
      target: consumer
    container_name: crypto-consumer
    environment:
      - DB_HOST=postgres
      - DB_USER=${DB_USER}
      - DB_PASSWORD=${DB_PASSWORD}
      - DB_PORT=${DB_PORT}
      - DB_NAME=${DB_NAME}
      - REDIS_PORT=${REDIS_PORT}
      - REDIS_HOST=redis
      - NATS_PORT=${NATS_PORT}
      - NATS_PROTOCOL=nats
      - NATS_HOST=nats
    env_file:
      - .env
    ports:
      - "${COMP_CONSUMER_HEALTHCHECK_PORT}:${CONSUMER_HEALTHCHECK_PORT}" # Порт для хелсчеков
    logging:
      driver: "json-file"
      options:
        max-size: "10m"   # Максимальный размер одного файла лога 10MB
        max-file: "3"     # Хранить максимум 3 таких файлов. Старые будут стираться автоматически.
    depends_on:
      nats:
        condition: service_healthy
      postgres:
        condition: service_healthy

  ws-feeder:
      build:
        context: .
        dockerfile: Dockerfile
        target: ws-feeder
      container_name: crypto-ws-feeder
      environment:
        - NATS_PORT=${NATS_PORT}
        - NATS_PROTOCOL=nats
        - NATS_HOST=nats
      env_file:
        - .env
      ports:
        - "${COMP_WS_FEEDER_HEALTHCHECK_PORT}:${WS_FEEDER_HEALTHCHECK_PORT}" # Порт для хелсчеков
      logging:
        driver: "json-file"
        options:
          max-size: "10m"   # Максимальный размер одного файла лога 10MB
          max-file: "3"     # Хранить максимум 3 таких файлов. Старые будут стираться автоматически.
      depends_on:
        nats:
          condition: service_healthy

  healer:
    build:
      context: .
      dockerfile: Dockerfile
      target: candles-healer
    profiles:
      - tools # Контейнер запустится только при явном вызове
    environment:
      - DB_HOST=postgres
      - DB_USER=${DB_USER}
      - DB_PASSWORD=${DB_PASSWORD}
      - DB_PORT=${DB_PORT}
      - DB_NAME=${DB_NAME}
    env_file:
      - .env
    depends_on:
      postgres:
        condition: service_healthy

  trainer:
    build:
      context: .
      dockerfile: Dockerfile
      target: trainer
    profiles:
      - tools # Контейнер запустится только при явном вызове
    environment:
      - DB_HOST=postgres
      - DB_USER=${DB_USER}
      - DB_PASSWORD=${DB_PASSWORD}
      - DB_PORT=${DB_PORT}
      - DB_NAME=${DB_NAME}
      - MODEL_PATH=/app/models/dqn_%s_model.json
    env_file:
      - .env
    volumes:
      - ./models:/app/models # Смотрит в ту же физическую папку, что и бот!
    depends_on:
      postgres:
        condition: service_healthy

volumes:
  postgres_data:
  redis_data:
  nats_data:
  prometheus_data:

```
