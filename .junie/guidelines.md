# Сервис агрегации EDI-данных поставщиков (UP EDI Scraper) — гайд для Junie

Этот документ содержит важную информацию по разработке и сопровождению сервиса агрегации EDI‑данных поставщиков (UP EDI Scraper).

---

## Project Overview

**Type**: PHP 8.2+ Symfony 7.1 console application  
**Purpose**: EDI (Electronic Data Interchange) data aggregation and processing from multiple suppliers via Kafka messaging  
**Architecture**: Domain-Driven Design influenced, Service-Oriented Architecture with Strategy and Factory patterns  
**Key Dependencies**: Symfony 7.1, rdkafka, google/apiclient, phpoffice/phpspreadsheet, php-amqplib, phpseclib (SFTP)

---

## Engineering Standard (Authoritative)

This section defines strict, high-signal rules for development.

- **Runtime**
  - PHP: 8.2+ (use modern PHP 8.2 features: typed properties, union types, readonly, etc.)
  - Framework: Symfony 7.1.*
  - Required Extensions: rdkafka, amqp, ssh2, xdebug, mbstring, gd, pdo_pgsql, zip, sockets, simplexml
  - Container: Docker with php:8.2-fpm base image

- **Architecture & Design** — CRITICAL
  - **Bounded Context**: EDI data aggregation and transformation
  - **Model Layer**: Simple data structures (DataRow, DataCollection, DataSetCollection) - no business logic
  - **Service Layer**: Business logic orchestration (Aggregator, Mapper, InputHandlers, Kafka Consumer/Producer)
  - **Config Layer**: Configuration objects (InputConfig, RestApiConfig, SubSource)
  - **Transport Layer**: Protocol implementations (HttpTransport, SftpTransport)
  - **Factory Layer**: Object creation (RestApiHandlerFactory, SftpTransportFactory)
  - **Design Patterns**:
    - Strategy Pattern: InputHandlerInterface with 8 implementations (Google Sheets, CSV/Excel via HTTP/SFTP, REST API, XML SFTP)
    - Factory Pattern: For creating handlers and transports
    - Collection Pattern: DataCollection, DataSetCollection
    - Dependency Injection: Constructor injection via Symfony DI container
  - **Layer Rules**:
    - Models must not depend on services or external APIs
    - Services orchestrate models, handlers, and external integrations
    - Handlers implement single responsibility (one source type)
    - Each factory creates specific type of objects

- **Code Style** (PHP Standards)
  - Follow PSR-12 Extended Coding Style Guide
  - Type hints: Use strict typing for all parameters and return types
  - Properties: Typed properties with visibility modifiers
  - Docstrings: PHPDoc blocks for complex array types, return null cases
  - Nullable types: Use `?Type` syntax or union types `Type|null`
  - TODO comments: Mark technical debt and areas for improvement
  - Exception handling: Use specific exception types, avoid using exceptions for control flow

- **Kafka Integration** — CRITICAL
  - Consumer: Reads supplier data messages from Kafka topic
  - Producer: Sends processed DataRow objects to output topic
  - Configuration: Via KAFKA_TRANSPORT_DSN, KAFKA_EDI_SERVER_HOST, KAFKA_EDI_SERVER_PORT environment variables
  - Message format: JSON with supplier_id, type_id, source, column_map_rules, version, range fields
  - Process management: Supervised by supervisord with automatic restart

- **Multi-Source Processing**
  - DataSetCollection supports merging data from multiple sources by unique key (default: 'upc')
  - Aggregation rules: addArray, max, min for handling duplicate keys
  - SubSource configuration: Each source has type_id, filename, key, fields, range

- **Configuration Management**
  - Environment-based: test and prod environments in docker/config-envs/{ENVIRONMENT}/
  - .env files: APP_ENV, KAFKA settings, Google API credentials, Slack webhooks, timeouts
  - JSON configs: credentials.json (Google), rest.json (REST API endpoints), rest.tokens.json (auth tokens), sftp_config.json (SFTP connections)
  - Config objects: Validated in constructors, throw InvalidArgumentException for missing/invalid data

- **Security & Secrets**
  - Never commit .env files or credentials
  - Google API credentials stored in config/credentials.json (gitignored)
  - REST API tokens in config/rest.tokens.json (gitignored)
  - SFTP credentials in config/sftp_config.json (gitignored)

---

## Project Structure

```
etl-edi-scraper/
├── src/
│   ├── Command/
│   │   └── ConsumerCommand.php        # Main entry: app:consume command
│   ├── Model/
│   │   ├── DataRow.php                # Single data record (fields array wrapper)
│   │   ├── DataCollection.php         # Collection of DataRow objects
│   │   └── DataSetCollection.php      # Indexed collection with merge/aggregation support
│   ├── Service/
│   │   ├── Aggregator/
│   │   │   └── Aggregator.php         # Main orchestrator: routes to handlers, maps, produces
│   │   ├── Auth/                      # JWT authentication for REST APIs
│   │   │   ├── FileTokenPersistence.php
│   │   │   ├── PlainStringJwtManager.php
│   │   │   └── SafeJwtManagerWrapper.php
│   │   ├── Config/
│   │   │   ├── InputConfig.php        # Main input message configuration
│   │   │   ├── RestApiConfig.php      # REST API endpoint configuration
│   │   │   ├── RestApiConfigProvider.php
│   │   │   └── SubSource.php          # Multi-source configuration item
│   │   ├── Factory/
│   │   │   ├── RestApiHandlerFactory.php
│   │   │   └── SftpTransportFactory.php
│   │   ├── InputHandler/              # Strategy implementations (8 types)
│   │   │   ├── InputHandlerInterface.php
│   │   │   ├── CsvInputHandler.php
│   │   │   ├── ExcelInputHandler.php
│   │   │   ├── GoogleApiInputHandler.php
│   │   │   ├── GoogleDriveFolderHandler.php
│   │   │   ├── GoogleSheetsInputHandler.php
│   │   │   ├── MorrisXmlSftpInputHandler.php
│   │   │   └── RestApiInputHandler.php
│   │   ├── Kafka/
│   │   │   ├── KafkaConsumer.php      # Reads messages from Kafka
│   │   │   └── KafkaProducer.php      # Sends processed data to Kafka
│   │   ├── Mapper/
│   │   │   └── Mapper.php             # Column mapping and transformation
│   │   ├── Transport/
│   │   │   ├── HttpTransport.php      # HTTP file downloads
│   │   │   └── SftpTransport.php      # SFTP file access
│   │   └── PriceService/
│   │       └── PriceServiceInterface.php
│   └── Kernel.php
├── config/
│   ├── packages/                      # Symfony package configs
│   ├── routes/                        # Symfony routing (minimal for console app)
│   ├── bundles.php
│   ├── services.yaml                  # Symfony DI configuration
│   ├── config.yml
│   ├── credentials.json               # Google API credentials (gitignored)
│   ├── rest.json                      # REST API endpoints config (gitignored)
│   ├── rest.tokens.json               # REST API tokens (gitignored)
│   ├── sftp_config.json               # SFTP connections (gitignored)
│   ├── supervisord.conf               # Process manager configuration
│   └── message_in.json                # Example input message
├── docker/
│   └── config-envs/
│       ├── test/
│       │   ├── .env.test
│       │   ├── docker-compose.override.yml
│       │   └── php.ini
│       └── prod/
│           ├── .env.prod
│           ├── docker-compose.override.yml
│           └── php.ini
├── Dockerfile                         # Main container definition
├── docker-compose.yml                 # Base Docker Compose config
├── Makefile                           # Build automation commands
├── composer.json                      # PHP dependencies
└── README.md
```

### Key Architectural Principles

- **Service Layer Orchestration**: Aggregator coordinates all data processing
- **Strategy Pattern**: Different input handlers for different source types (CSV, Excel, Google, SFTP, REST)
- **Factory Pattern**: Handlers and transports created by factories with supplier-specific configuration
- **Collection Pattern**: DataCollection and DataSetCollection for data manipulation
- **Configuration as Data**: InputConfig validates and structures incoming Kafka messages
- **Separation of Concerns**: Models, Services, Handlers, Transports, Factories in separate layers

---

## Build & Configuration Instructions

### 1. Environment Setup

**Prerequisites:**
- Docker and Docker Compose
- Git

**Initial Setup:**

```powershell
# Clone repository
git clone <repository-url>
cd etl-edi-scraper

# Set environment (test or prod)
$env:ENVIRONMENT="test"
```

### 2. Configuration Files

**Required configuration files (create from examples or populate):**

1. **Google API credentials**: `config/credentials.json`
2. **REST API config**: `config/rest.json` and `config/rest.tokens.json`
3. **SFTP config**: `config/sftp_config.json`
4. **Environment file**: Automatically copied from `docker/config-envs/{ENVIRONMENT}/.env.{ENVIRONMENT}`

**Environment variables in `.env.test` or `.env.prod`:**
- `APP_ENV` - Symfony environment (dev/prod)
- `APP_SECRET` - Symfony secret key
- `KAFKA_TRANSPORT_DSN` - Kafka connection string (kafka://kafka:9093)
- `KAFKA_EDI_SERVER_HOST` - Kafka host
- `KAFKA_EDI_SERVER_PORT` - Kafka port
- `GOOGLE_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` - Google API credentials
- `GOOGLE_AUTH_CONFIG` - Path to credentials.json
- `SLACK_WEBHOOK_URL`, `SLACK_ERROR_WEBHOOK_URL`, `SLACK_INFO_WEBHOOK_URL` - Slack notifications
- `API_ENDPOINT` - External API endpoint
- `KAFKA_WAIT_MESSAGE_TIME` - Message wait timeout (milliseconds)
- `ON_KAFKA_MAX_ERROR_COUNT_REPEAT` - Max retry attempts
- `RECORD_LIFETIME` - Record lifetime (days)

### 3. Build and Run

**Using Makefile (recommended):**

```powershell
# Set environment
$env:ENVIRONMENT="test"

# Build and start
make dc_up

# View logs
make dc_logs

# Stop and cleanup
make dc_down

# Restart
make dc_restart

# Execute bash in container
make dc_exec
```

**Manual Docker Compose:**

```powershell
# Build and start
docker-compose -f docker-compose.yml -f docker/config-envs/test/docker-compose.override.yml up -d --build

# Stop
docker-compose down
```

**The application automatically starts `app:consume` command via supervisord on container startup.**

### 4. Manual Command Execution

```powershell
# Execute consume command manually
docker exec -it php-up-edi php /var/www/etl-edi-scraper/bin/console app:consume

# View logs
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.out.log
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.err.log
```

---

## Input Message Format

The `app:consume` command reads JSON messages from Kafka with the following structure:

```json
{
  "supplier_id": 123,
  "name": "Supplier Name",
  "type_id": 1,
  "source": "https://example.com/data.csv",
  "range": "A1:Z1000",
  "column_map_rules": {
    "product_name": "name",
    "price": "cost"
  },
  "version": 1
}
```

**Multi-source format (type_id can be null):**

```json
{
  "supplier_id": 123,
  "name": "Supplier Name",
  "type_id": null,
  "source": [
    {
      "type_id": 1,
      "filename": "sheet1",
      "key": "upc",
      "fields": ["name", "price"],
      "range": "A1:Z1000"
    },
    {
      "type_id": 4,
      "filename": "https://example.com/prices.xlsx",
      "key": "upc",
      "fields": ["discount"],
      "range": null
    }
  ],
  "column_map_rules": {
    "product_name": "name",
    "price": "cost"
  },
  "version": 1
}
```

**Type IDs:**
- 1: Google Sheets
- 2: CSV via HTTP
- 3: Google Drive Folder
- 4: Excel via HTTP
- 5: Morris XML via SFTP
- 6: Excel via SFTP
- 7: CSV via SFTP
- 8: REST API

---

## Data Processing Flow

1. **ConsumerCommand** reads message from Kafka (KafkaConsumer)
2. **InputConfig** validates and parses message structure
3. **Aggregator** determines processing mode:
   - Single source: Get handler by type_id
   - Multi-source: Process each SubSource, merge by key
4. **InputHandler** (Strategy) reads data from source → DataCollection
5. **Mapper** transforms columns according to column_map_rules
6. **KafkaProducer** sends each DataRow to output topic

---

## Code Style & Development Guidelines

### PHP Modern Practices

- **Strict typing**: Use typed properties, parameters, return types
- **Union types**: Use `string|array`, `?Type` for nullable
- **Constructor property promotion**: Use when appropriate (PHP 8.0+)
- **Match expressions**: Prefer over switch where applicable (see Aggregator::getHandlerByType)
- **Named arguments**: Use for clarity when calling methods with many optional parameters

### Service Design

**Services use constructor dependency injection:**

```php
class Aggregator
{
    public function __construct(
        LoggerInterface $logger,
        Mapper $mapper,
        KafkaProducer $producer,
        HttpTransport $httpTransport,
        SftpTransportFactory $sftpTransportFactory,
        GoogleSheetsInputHandler $googleSheetsInputHandler,
        GoogleDriveFolderHandler $googleDriveFolderHandler,
        MorrisXmlSftpInputHandler $morrisXmlSftpInputHandler,
        RestApiHandlerFactory $restApiHandlerFactory,
    ) {
        // Assignment
    }
}
```

### Configuration Objects

**Validate in constructor, throw InvalidArgumentException:**

```php
class InputConfig
{
    private int $supplierId;
    private ?int $type_id;
    
    public function __construct(array $input)
    {
        if (!isset($input['supplier_id'], $input['source'])) {
            throw new \InvalidArgumentException('Required fields missing');
        }
        
        $this->supplierId = (int)$input['supplier_id'];
        $this->type_id = isset($input['type_id']) ? 
            ($input['type_id'] !== null ? (int)$input['type_id'] : null) : null;
    }
}
```

### Input Handlers

**All implement InputHandlerInterface:**

```php
interface InputHandlerInterface
{
    public function readData(string $source, ?string $range = null): DataCollection;
}
```

**Example implementation:**

```php
class CsvInputHandler implements InputHandlerInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ?HttpTransport $httpTransport = null,
        private ?SftpTransport $sftpTransport = null
    ) {}
    
    public function readData(string $source, ?string $range = null): DataCollection
    {
        // Implementation
    }
}
```

### Error Handling

- **Log errors with context**: Use LoggerInterface, include supplier_id, type_id, source
- **Throw specific exceptions**: InvalidArgumentException for config errors, RuntimeException for processing errors
- **ConsumerCommand catches all exceptions** and returns FAILURE status
- **Avoid using exceptions for control flow** (noted as technical debt in TODO comments)

---

## Debugging Tips

### Common Issues

**1. Kafka connection fails:**
- Check KAFKA_EDI_SERVER_HOST and KAFKA_EDI_SERVER_PORT in .env
- Ensure Kafka container is running
- Verify network connectivity between containers

**2. Google API authentication fails:**
- Verify config/credentials.json exists and is valid
- Check GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET in .env
- Ensure GOOGLE_AUTH_CONFIG path is correct

**3. SFTP connection fails:**
- Check config/sftp_config.json for supplier_id entry
- Verify SSH2 extension is loaded (php -m | grep ssh2)
- Check SFTP host, port, username, password

**4. Input handler not found:**
- Verify type_id in message matches one of 8 defined types
- For multi-source, check each SubSource has valid type_id
- See Aggregator::getHandlerByType() match expression

**5. Data not appearing in output:**
- Check Mapper column_map_rules are correct
- Verify KafkaProducer is sending to correct topic
- Review supervisor logs for processing errors

### Useful Commands

**View supervisor logs:**
```powershell
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.out.log
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.err.log
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisord.log
```

**View PHP errors:**
```powershell
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/php_errors.log
```

**Check running processes:**
```powershell
docker exec -it up_edi_scraper-php-up-edi-1 supervisorctl status
```

**Restart consumer process:**
```powershell
docker exec -it up_edi_scraper-php-up-edi-1 supervisorctl restart symfony_command
```

**Execute bash in container:**
```powershell
docker exec -it php-up-edi bash
```

**Check Kafka consumer group:**
```powershell
# From Kafka container
kafka-consumer-groups --bootstrap-server localhost:9092 --describe --group your-group-id
```

### Xdebug Configuration

- Xdebug is enabled in container
- Port: 9003
- IDE key: EDI-Debug (see PHP_IDE_CONFIG in .env)
- Use remote interpreter from container in IDE
- IDE creates isolated copy of PHP image for debugging

**php.ini location:** `docker/config-envs/{ENVIRONMENT}/php.ini`

---

## Development Workflow

### Adding New Input Handler

1. **Create handler class** implementing InputHandlerInterface
2. **Register in Aggregator::getHandlerByType()** with new type_id
3. **Add to constructor** if handler needs specific dependencies
4. **Configure in services.yaml** if needed (most handlers auto-wired)
5. **Document type_id** in this file and README.md

### Adding New Data Source Type

1. **Define type_id** (next available number)
2. **Create InputHandler** implementation
3. **Add to Aggregator** type_id match expression
4. **Create Factory** if handler needs supplier-specific config
5. **Update documentation**

### Modifying Data Transformation

1. **Column mapping**: Modify column_map_rules in Kafka message
2. **Aggregation rules**: Update DataSetCollection::applyRules()
3. **Custom transformation**: Extend Mapper service

### Adding Configuration

1. **Environment variables**: Add to docker/config-envs/{ENVIRONMENT}/.env.{ENVIRONMENT}
2. **JSON config**: Create config file, add to .gitignore
3. **Config object**: Create validator class extending InputConfig pattern

---

## Important Notes for Junie

### Before Making Changes

1. **Understand the flow**: Kafka → Consumer → Aggregator → Handler → Mapper → Producer
2. **Check type_id mapping**: Each type_id maps to specific handler
3. **Respect interface contracts**: All handlers must implement InputHandlerInterface
4. **Use dependency injection**: Don't instantiate services manually (except in factories)

### When Adding Features

1. **Follow existing patterns**: Strategy for handlers, Factory for creation
2. **Use configuration objects**: Validate input data in constructors
3. **Log with context**: Include supplier_id, type_id, source in all logs
4. **Handle errors gracefully**: Catch, log, and return appropriate status

### When Debugging

1. **Check supervisor logs first**: Most errors appear in symfony_command.out.log or .err.log
2. **Verify configuration**: Ensure all required config files exist and are valid
3. **Test with simple case**: Use single-source message before multi-source
4. **Use Xdebug**: Remote debugging is configured and ready to use

### Architecture Rules

- **Models** (DataRow, DataCollection) contain no business logic
- **Services** (Aggregator, Mapper) orchestrate but don't know transport details
- **Handlers** (InputHandler*) know how to read specific source types
- **Transports** (Http, Sftp) handle protocol communication
- **Factories** create handlers/transports with supplier-specific config
- **Never bypass factories** for handler/transport creation

---

## Technical Debt & TODOs

Current known issues (from code comments):

1. **InputConfig::sourceDecode()**: Using exceptions for control flow (line 54)
2. **InputConfig::isMultiSource()**: Should check by type_id only, not by trying to decode (line 68)
3. **Aggregator::getHandlerByType()**: Add type for multi-source scenario (line 90)
4. **Multi-source decoding**: Should decode JSON in InputConfig constructor (line 35)

These are acceptable for now but should be addressed in future refactoring.

---

## Performance Considerations

- **Memory usage**: Each message processing tracked in ConsumerCommand
- **Execution time**: Logged per message for performance monitoring
- **KAFKA_WAIT_MESSAGE_TIME**: 1200000ms (20 minutes) timeout for message wait
- **Supervisor autorestart**: Ensures consumer recovers from crashes
- **Single message processing**: app:consume processes ONE message per invocation (supervisor loops)

---

## External Integrations

1. **Kafka**: Message queue for input/output
2. **Google APIs**: Sheets API, Drive API for Google data sources
3. **REST APIs**: Various supplier endpoints with JWT authentication
4. **SFTP**: Secure file transfer for file-based sources
5. **Slack**: Webhooks for error and info notifications
6. **PostgreSQL**: Database (pdo_pgsql extension installed, usage TBD)

---

## Руководство на русском языке

Ниже — русская версия гайда. Содержание повторяет англоязычный раздел выше и является основным источником правил для разработки.

### Обзор проекта

**Тип**: консольное приложение на PHP 8.2+ / Symfony 7.1  
**Назначение**: агрегирование и обработка EDI‑данных (Electronic Data Interchange) от нескольких поставщиков с помощью Kafka‑сообщений  
**Архитектура**: вдохновлена DDD, сервисно‑ориентированная архитектура, активно используются паттерны Strategy и Factory  
**Ключевые зависимости**: Symfony 7.1, rdkafka, google/apiclient, phpoffice/phpspreadsheet, php-amqplib, phpseclib (SFTP)

### Инженерные стандарты (обязательные)

**Runtime**

- PHP 8.2+ (используем современные возможности: типизированные свойства, union‑типы, readonly и т.д.)
- Framework: Symfony 7.1.*
- Обязательные расширения PHP: rdkafka, amqp, ssh2, xdebug, mbstring, gd, pdo_pgsql, zip, sockets, simplexml
- Контейнер: Docker на базе образа `php:8.2-fpm`

**Архитектура и дизайн (критично)**

- Граничный контекст: агрегация и трансформация EDI‑данных
- Модельный слой: простые структуры данных (DataRow, DataCollection, DataSetCollection), без бизнес‑логики
- Сервисный слой: оркестрация бизнес‑логики (Aggregator, Mapper, InputHandlers, Kafka Consumer/Producer)
- Конфигурационный слой: конфигурационные объекты (InputConfig, RestApiConfig, SubSource)
- Транспортный слой: реализации протоколов (HttpTransport, SftpTransport)
- Фабрики: создание объектов (RestApiHandlerFactory, SftpTransportFactory)

Используемые паттерны:

- Strategy: InputHandlerInterface и 8 реализаций (Google Sheets, CSV/Excel через HTTP/SFTP, REST API, XML SFTP)
- Factory: фабрики для создания хендлеров и транспортов
- Collection: DataCollection, DataSetCollection
- Dependency Injection: все сервисы внедряются через конструктор (Symfony DI)

Правила слоёв:

- Модели не зависят от сервисов или внешних API
- Сервисы оркестрируют модели, хендлеры и внешние интеграции
- Каждый InputHandler отвечает за один тип источника
- Каждая фабрика создаёт объекты только своего типа

**Стиль кода (PHP)**

- Следуем PSR‑12 (расширенный стандарт код‑стайла)
- Везде используем строгие типы аргументов и возвращаемых значений
- Свойства: обязательно типизированные и с модификаторами видимости
- Для сложных массивов и `null`‑возвратов используем PHPDoc
- Nullable‑типы: `?Type` или `Type|null`
- TODO‑комментарии: только для фиксации долга и планируемых улучшений
- Исключения: используем конкретные типы, не применяем исключения как механизм обычного управления потоком

**Интеграция с Kafka (критично)**

- Consumer читает сообщения с данными поставщиков из входного Kafka‑топика
- Producer отправляет обработанные объекты DataRow в выходной топик
- Конфигурация берётся из переменных окружения: `KAFKA_TRANSPORT_DSN`, `KAFKA_EDI_SERVER_HOST`, `KAFKA_EDI_SERVER_PORT`
- Формат сообщения: JSON с полями `supplier_id`, `type_id`, `source`, `column_map_rules`, `version`, `range`
- Управление процессом: через supervisord с автоперезапуском

**Мультисорсная обработка**

- DataSetCollection умеет объединять данные из нескольких источников по уникальному ключу (по умолчанию `upc`)
- При коллизиях по ключу используются правила агрегации: `addArray`, `max`, `min`
- SubSource‑конфигурация: для каждого источника задаются `type_id`, `filename`, `key`, `fields`, `range`

**Управление конфигурацией**

- Окружения: `test` и `prod` в `docker/config-envs/{ENVIRONMENT}/`
- `.env`‑файлы: настраивают `APP_ENV`, Kafka, Google API, Slack, таймауты и т.д.
- JSON‑конфиги: `credentials.json` (Google), `rest.json` (REST‑эндпоинты), `rest.tokens.json` (токены), `sftp_config.json` (SFTP)
- Конфигурационные объекты валидируют данные в конструкторах и выбрасывают `InvalidArgumentException` при ошибках

**Безопасность и секреты**

- Никогда не коммитим `.env` и любые креды
- Google‑креды: `config/credentials.json` (в .gitignore)
- REST‑токены: `config/rest.tokens.json` (в .gitignore)
- SFTP‑креды: `config/sftp_config.json` (в .gitignore)

### Структура проекта

```
etl-edi-scraper/
├── src/
│   ├── Command/
│   │   └── ConsumerCommand.php        # Точка входа: консольная команда app:consume
│   ├── Model/
│   │   ├── DataRow.php                # Одна запись данных (обёртка над массивом полей)
│   │   ├── DataCollection.php         # Коллекция объектов DataRow
│   │   └── DataSetCollection.php      # Индексированная коллекция с поддержкой merge/агрегации
│   ├── Service/
│   │   ├── Aggregator/
│   │   │   └── Aggregator.php         # Главный оркестратор: выбор хендлеров, маппинг, отправка в Kafka
│   │   ├── Auth/                      # JWT‑аутентификация для REST API
│   │   │   ├── FileTokenPersistence.php
│   │   │   ├── PlainStringJwtManager.php
│   │   │   └── SafeJwtManagerWrapper.php
│   │   ├── Config/
│   │   │   ├── InputConfig.php        # Основная конфигурация входного сообщения
│   │   │   ├── RestApiConfig.php      # Конфигурация REST‑эндпоинта
│   │   │   ├── RestApiConfigProvider.php
│   │   │   └── SubSource.php          # Элемент конфигурации мультисорсного режима
│   │   ├── Factory/
│   │   │   ├── RestApiHandlerFactory.php
│   │   │   └── SftpTransportFactory.php
│   │   ├── InputHandler/              # Реализации стратегии для разных типов источников
│   │   │   ├── InputHandlerInterface.php
│   │   │   ├── CsvInputHandler.php
│   │   │   ├── ExcelInputHandler.php
│   │   │   ├── GoogleApiInputHandler.php
│   │   │   ├── GoogleDriveFolderHandler.php
│   │   │   ├── GoogleSheetsInputHandler.php
│   │   │   ├── MorrisXmlSftpInputHandler.php
│   │   │   └── RestApiInputHandler.php
│   │   ├── Kafka/
│   │   │   ├── KafkaConsumer.php      # Читает сообщения из Kafka
│   │   │   └── KafkaProducer.php      # Отправляет обработанные данные в Kafka
│   │   ├── Mapper/
│   │   │   └── Mapper.php             # Маппинг и трансформация колонок
│   │   ├── Transport/
│   │   │   ├── HttpTransport.php      # Загрузка файлов по HTTP
│   │   │   └── SftpTransport.php      # Доступ к файлам по SFTP
│   │   └── PriceService/
│   │       └── PriceServiceInterface.php
│   └── Kernel.php
├── config/
│   ├── packages/                      # Конфиги пакетов Symfony
│   ├── routes/                        # Маршруты Symfony (минимум, т.к. приложение консольное)
│   ├── bundles.php
│   ├── services.yaml                  # Конфигурация DI‑контейнера
│   ├── config.yml
│   ├── credentials.json               # Google‑креды (в .gitignore)
│   ├── rest.json                      # Конфиг REST‑эндпоинтов (в .gitignore)
│   ├── rest.tokens.json               # REST‑токены (в .gitignore)
│   ├── sftp_config.json               # SFTP‑подключения (в .gitignore)
│   ├── supervisord.conf               # Конфиг process‑manager’а
│   └── message_in.json                # Пример входного сообщения
├── docker/
│   └── config-envs/
│       ├── test/
│       │   ├── .env.test
│       │   ├── docker-compose.override.yml
│       │   └── php.ini
│       └── prod/
│           ├── .env.prod
│           ├── docker-compose.override.yml
│           └── php.ini
├── Dockerfile                         # Основной Docker‑образ
├── docker-compose.yml                 # Базовый docker‑compose
├── Makefile                           # Утилитные команды сборки/запуска
├── composer.json                      # PHP‑зависимости
└── README.md
```

Ключевые принципы архитектуры:

- Оркестрация в сервисном слое: Aggregator координирует весь процесс обработки
- Strategy: отдельный InputHandler под каждый тип источника (CSV, Excel, Google, SFTP, REST)
- Factory: фабрики для создания хендлеров и транспортов с учётом конфигурации поставщика
- Collection: DataCollection и DataSetCollection для работы с наборами данных
- Конфигурация как данные: InputConfig валидирует и структурирует входящие Kafka‑сообщения
- Разделение ответственности: модели, сервисы, хендлеры, транспорты и фабрики — в отдельных слоях

### Сборка и конфигурация

**1. Подготовка окружения**

Требуется:

- Docker и Docker Compose
- Git

Первичный запуск:

```powershell
git clone <repository-url>
cd etl-edi-scraper

# выбор окружения (test или prod)
$env:ENVIRONMENT="test"
```

**2. Конфигурационные файлы**

Обязательные файлы (создать из примеров или заполнить вручную):

1. Google‑креды: `config/credentials.json`
2. REST‑конфиг: `config/rest.json` и `config/rest.tokens.json`
3. SFTP‑конфиг: `config/sftp_config.json`
4. `.env` для окружения: автоматически копируется из `docker/config-envs/{ENVIRONMENT}/.env.{ENVIRONMENT}`

Основные переменные окружения (`.env.test` / `.env.prod`):

- `APP_ENV` — окружение Symfony (dev/prod)
- `APP_SECRET` — секретный ключ Symfony
- `KAFKA_TRANSPORT_DSN` — строка подключения к Kafka (например, `kafka://kafka:9093`)
- `KAFKA_EDI_SERVER_HOST` — хост Kafka
- `KAFKA_EDI_SERVER_PORT` — порт Kafka
- `GOOGLE_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` — креды Google API
- `GOOGLE_AUTH_CONFIG` — путь к `credentials.json`
- `SLACK_WEBHOOK_URL`, `SLACK_ERROR_WEBHOOK_URL`, `SLACK_INFO_WEBHOOK_URL` — webhooks для Slack
- `API_ENDPOINT` — внешний API‑эндпоинт
- `KAFKA_WAIT_MESSAGE_TIME` — таймаут ожидания сообщения (мс)
- `ON_KAFKA_MAX_ERROR_COUNT_REPEAT` — максимальное число ретраев
- `RECORD_LIFETIME` — срок жизни записей (дни)

**3. Сборка и запуск**

Через Makefile (рекомендуется):

```powershell
$env:ENVIRONMENT="test"

make dc_up      # сборка и запуск контейнеров
make dc_logs    # просмотр логов
make dc_down    # остановка и очистка
make dc_restart # рестарт
make dc_exec    # shell внутри PHP‑контейнера
```

Ручной запуск Docker Compose:

```powershell
docker-compose -f docker-compose.yml -f docker/config-envs/test/docker-compose.override.yml up -d --build

docker-compose down
```

При старте контейнера `supervisord` автоматически запускает консольную команду `app:consume`.

**4. Ручной запуск команд**

```powershell
# разовый запуск консьюмера
docker exec -it php-up-edi php /var/www/etl-edi-scraper/bin/console app:consume

# просмотр логов
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.out.log
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.err.log
```

### Формат входного сообщения

Команда `app:consume` читает из Kafka JSON‑сообщения формата:

```json
{
  "supplier_id": 123,
  "name": "Supplier Name",
  "type_id": 1,
  "source": "https://example.com/data.csv",
  "range": "A1:Z1000",
  "column_map_rules": {
    "product_name": "name",
    "price": "cost"
  },
  "version": 1
}
```

**Мультисорсный формат** (поле `type_id` может быть `null`):

```json
{
  "supplier_id": 123,
  "name": "Supplier Name",
  "type_id": null,
  "source": [
    {
      "type_id": 1,
      "filename": "sheet1",
      "key": "upc",
      "fields": ["name", "price"],
      "range": "A1:Z1000"
    },
    {
      "type_id": 4,
      "filename": "https://example.com/prices.xlsx",
      "key": "upc",
      "fields": ["discount"],
      "range": null
    }
  ],
  "column_map_rules": {
    "product_name": "name",
    "price": "cost"
  },
  "version": 1
}
```

Типы источников (`type_id`):

- 1: Google Sheets
- 2: CSV по HTTP
- 3: Папка Google Drive
- 4: Excel по HTTP
- 5: Morris XML по SFTP
- 6: Excel по SFTP
- 7: CSV по SFTP
- 8: REST API

### Поток обработки данных

1. `ConsumerCommand` читает сообщение из Kafka (KafkaConsumer)
2. `InputConfig` валидирует и парсит структуру сообщения
3. `Aggregator` определяет режим обработки:
   - одиночный источник: выбирается обработчик по `type_id`
   - мультисорс: каждый `SubSource` обрабатывается отдельно, далее данные мёрджатся по ключу
4. `InputHandler` (Strategy) читает данные из источника → `DataCollection`
5. `Mapper` применяет `column_map_rules` и трансформирует колонки
6. `KafkaProducer` отправляет каждую строку (`DataRow`) в выходной Kafka‑топик

### Стиль кода и подходы к разработке

**PHP‑практики**

- Строгая типизация: свойства, аргументы, возвращаемые значения
- Union‑типы (`string|array`), nullable‑типы (`?Type`)
- По возможности — promotion свойств в конструкторах
- `match` вместо `switch`, где это улучшает читаемость (см. `Aggregator::getHandlerByType`)
- Named‑аргументы для методов с большим числом опциональных параметров

**Сервисы и DI**

Сервисы создаются через DI‑контейнер, зависимости — через конструктор:

```php
class Aggregator
{
    public function __construct(
        LoggerInterface $logger,
        Mapper $mapper,
        KafkaProducer $producer,
        HttpTransport $httpTransport,
        SftpTransportFactory $sftpTransportFactory,
        GoogleSheetsInputHandler $googleSheetsInputHandler,
        GoogleDriveFolderHandler $googleDriveFolderHandler,
        MorrisXmlSftpInputHandler $morrisXmlSftpInputHandler,
        RestApiHandlerFactory $restApiHandlerFactory,
    ) {
        // сохранение зависимостей
    }
}
```

**Конфигурационные объекты**

Валидация — в конструкторе, при ошибках бросаем `InvalidArgumentException`:

```php
class InputConfig
{
    private int $supplierId;
    private ?int $type_id;

    public function __construct(array $input)
    {
        if (!isset($input['supplier_id'], $input['source'])) {
            throw new \InvalidArgumentException('Required fields missing');
        }

        $this->supplierId = (int) $input['supplier_id'];
        $this->type_id = isset($input['type_id'])
            ? ($input['type_id'] !== null ? (int) $input['type_id'] : null)
            : null;
    }
}
```

**InputHandler’ы**

Все обработчики реализуют общий интерфейс:

```php
interface InputHandlerInterface
{
    public function readData(string $source, ?string $range = null): DataCollection;
}
```

Пример реализации:

```php
class CsvInputHandler implements InputHandlerInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ?HttpTransport $httpTransport = null,
        private ?SftpTransport $sftpTransport = null,
    ) {
    }

    public function readData(string $source, ?string $range = null): DataCollection
    {
        // чтение и парсинг CSV
    }
}
```

**Обработка ошибок**

- Логируем ошибки с контекстом (`supplier_id`, `type_id`, `source`)
- Для ошибок конфигурации — `InvalidArgumentException`, для ошибок обработки — `RuntimeException`
- `ConsumerCommand` перехватывает все исключения и возвращает статус FAILURE
- Не используем исключения как обычный механизм управления потоком (см. TODO в коде)

### Отладка и типовые проблемы

**Частые проблемы:**

1. Проблемы с подключением к Kafka:
   - проверьте `KAFKA_EDI_SERVER_HOST` и `KAFKA_EDI_SERVER_PORT` в `.env`
   - убедитесь, что Kafka‑контейнер запущен
   - проверьте сеть между контейнерами

2. Ошибки аутентификации Google API:
   - убедитесь, что `config/credentials.json` существует и валиден
   - проверьте `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` в `.env`
   - убедитесь, что `GOOGLE_AUTH_CONFIG` указывает на корректный файл

3. Ошибки SFTP‑подключения:
   - убедитесь, что в `config/sftp_config.json` есть запись для нужного `supplier_id`
   - проверьте, что расширение `ssh2` подключено (`php -m`)
   - проверьте хост, порт, логин и пароль SFTP

4. Хендлер для `type_id` не найден:
   - проверьте, что `type_id` соответствует одному из 8 поддерживаемых типов
   - для мультисорс‑режима проверьте `type_id` у каждого `SubSource`
   - посмотрите `Aggregator::getHandlerByType()`

5. Данные не доходят до выходного топика:
   - проверьте корректность `column_map_rules` в сообщении
   - убедитесь, что `KafkaProducer` публикует в нужный топик
   - посмотрите логи `supervisord` и приложения

**Полезные команды:**

```powershell
# логи supervisord
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.out.log
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.err.log
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisord.log

# PHP‑ошибки
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/php_errors.log

# процессы supervisord
docker exec -it up_edi_scraper-php-up-edi-1 supervisorctl status

# рестарт консюмера
docker exec -it up_edi_scraper-php-up-edi-1 supervisorctl restart symfony_command

# shell внутри PHP‑контейнера
docker exec -it php-up-edi bash
```

Xdebug:

- включён в контейнере
- порт: `9003`
- IDE‑key: `EDI-Debug` (см. `PHP_IDE_CONFIG` в `.env`)
- отладка через удалённый интерпретатор из контейнера
- `php.ini` для окружения: `docker/config-envs/{ENVIRONMENT}/php.ini`

### Рабочий процесс разработки

**Добавление нового InputHandler:**

1. Создать класс, реализующий `InputHandlerInterface`
2. Зарегистрировать новый `type_id` в `Aggregator::getHandlerByType()`
3. Пробросить зависимость через конструктор `Aggregator`, если нужно
4. При необходимости добавить конфигурацию в `services.yaml`
5. Задокументировать новый `type_id` в этом гайде и в README

**Добавление нового типа источника данных:**

1. Выбрать новый `type_id`
2. Реализовать новый `InputHandler`
3. Добавить обработку `type_id` в `Aggregator`
4. Создать фабрику, если хендлер требует специфической конфигурации
5. Обновить документацию

**Изменение трансформации данных:**

1. Для изменения маппинга колонок — обновить `column_map_rules` в Kafka‑сообщении
2. Для изменения правил агрегации — править `DataSetCollection::applyRules()`
3. Для кастомных трансформаций — расширять `Mapper`

**Добавление новой конфигурации:**

1. Новые переменные окружения — добавить в `.env.{ENVIRONMENT}` в `docker/config-envs/{ENVIRONMENT}/`
2. Новый JSON‑конфиг — создать файл и добавить в `.gitignore`
3. Новый конфигурационный объект — реализовать по примеру `InputConfig`

### Важные замечания для Junie

Перед изменениями:

1. Понимай общий поток: Kafka → Consumer → Aggregator → Handler → Mapper → Producer
2. Проверяй корректность `type_id`
3. Соблюдай контракты интерфейсов: все хендлеры обязаны реализовывать `InputHandlerInterface`
4. Всегда используй DI: не создавай сервисы и транспорты вручную, кроме фабрик

При добавлении фич:

1. Следуй существующим паттернам (Strategy, Factory)
2. Оборачивай входные данные в конфигурационные объекты
3. Логируй ошибки с максимально полезным контекстом
4. Не забывай про аккуратную обработку ошибок

При отладке:

1. В первую очередь проверяй логи supervisord
2. Убедись, что все конфиги на месте и валидны
3. Тестируй сначала на простом одиночном источнике, а уже затем мультисорс
4. Используй Xdebug для сложных кейсов

Архитектурные правила:

- Модели (DataRow, DataCollection) не содержат бизнес‑логики
- Сервисы (Aggregator, Mapper) оркестрируют, но не знают деталей транспорта
- Хендлеры (InputHandler*) знают, как читать свой тип источника
- Транспорты (Http, Sftp) отвечают за протокол
- Фабрики создают хендлеры и транспорты с учётом конфигурации
- Нельзя обходить фабрики при создании транспортов/хендлеров

### Технический долг и TODO

Текущие известные проблемы (см. комментарии в коде):

1. `InputConfig::sourceDecode()`: используется исключение для управления потоком
2. `InputConfig::isMultiSource()`: сейчас опирается на попытку декодинга, нужно ориентироваться только на `type_id`
3. `Aggregator::getHandlerByType()`: требуется отдельный вариант для мультисорс‑сценария
4. Декодинг мультисорс‑структуры должен происходить в конструкторе `InputConfig`

Пока это допустимый долг, но при рефакторинге стоит устранить эти моменты.

### Производительность

- Память: использование отслеживается в `ConsumerCommand` для каждого сообщения
- Время выполнения: логируется по каждому сообщению
- `KAFKA_WAIT_MESSAGE_TIME` по умолчанию — 1 200 000 мс (20 минут)
- `supervisord` обеспечивает автоперезапуск консьюмера при падениях
- `app:consume` обрабатывает одно сообщение за запуск (цикл управления в `supervisord`)

### Внешние интеграции

1. Kafka — очередь сообщений для входа/выхода
2. Google APIs — Sheets API и Drive API для Google‑источников
3. REST API — внешние поставщики с JWT‑аутентификацией
4. SFTP — защищённый доступ к файлам поставщиков
5. Slack — уведомления об ошибках и инфосообщениях
6. PostgreSQL — БД (расширение `pdo_pgsql` подключено, использование зависит от задач)
