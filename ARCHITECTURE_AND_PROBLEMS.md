# Архитектурные проблемы UP EDI Scraper

Этот документ описывает текущие архитектурные ограничения и проблемы системы, а также предлагаемые направления для рефакторинга.

---

## Проблема 1: Жёсткая связь хендлеров с типами источников и протоколами

### Описание проблемы

В текущей реализации каждый `type_id` жёстко привязан к конкретному `InputHandler`, который включает в себя **одновременно**:
- Логику получения данных (протокол: HTTP, SFTP, Google API)
- Логику парсинга формата данных (CSV, Excel, XML, JSON)

Это приводит к следующим проблемам:

#### 1.1. Дублирование кода протоколов

Пример: CSV и Excel могут быть получены как по HTTP, так и по SFTP, что привело к созданию отдельных хендлеров:

```
type_id: 2 → CsvInputHandler (HTTP)
type_id: 7 → CsvInputHandler (SFTP)
type_id: 4 → ExcelInputHandler (HTTP)
type_id: 6 → ExcelInputHandler (SFTP)
```

**Проблема**: один и тот же код парсинга CSV/Excel дублируется в разных хендлерах, отличающихся только способом получения файла.

#### 1.2. Невозможность гибкой комбинации протоколов и форматов

Если завтра потребуется получать:
- CSV по REST API
- Excel из Google Drive
- XML по HTTP
- JSON по SFTP

...придётся создавать новые `type_id` и новые хендлеры для каждой комбинации протокол+формат.

#### 1.3. Нарушение Single Responsibility Principle

Каждый хендлер отвечает за две разные задачи:
1. **Получение данных** (transport layer)
2. **Парсинг формата** (parser layer)

Это затрудняет тестирование, переиспользование и модификацию кода.

### Текущая архитектура (упрощённо)

```
InputHandlerInterface
├── CsvInputHandler (HTTP)        # протокол + парсер CSV
├── CsvInputHandler (SFTP)        # протокол + парсер CSV (дубликат парсера)
├── ExcelInputHandler (HTTP)      # протокол + парсер Excel
├── ExcelInputHandler (SFTP)      # протокол + парсер Excel (дубликат парсера)
├── MorrisXmlSftpInputHandler     # SFTP + парсер Morris XML (жёстко привязан к SFTP!)
├── GoogleSheetsInputHandler      # Google API + парсер (жёстко привязан к Google)
├── GoogleDriveFolderHandler      # Google API + парсер (жёстко привязан к Google)
└── RestApiInputHandler           # HTTP REST + парсер JSON
```

### Предлагаемое решение

**Разделить ответственность на два уровня:**

#### Уровень 1: Transport Layer (Транспорты)
Отвечают **только** за получение данных:

```php
interface TransportInterface
{
    public function fetch(string $source): string; // возвращает сырые данные (string/stream)
}

class HttpTransport implements TransportInterface { ... }
class SftpTransport implements TransportInterface { ... }
class GoogleApiTransport implements TransportInterface { ... }
class RestApiTransport implements TransportInterface { ... }
```

#### Уровень 2: Parser Layer (Парсеры)
Отвечают **только** за парсинг формата:

```php
interface ParserInterface
{
    public function parse(string $rawData, ?string $range = null): DataCollection;
}

class CsvParser implements ParserInterface { ... }
class ExcelParser implements ParserInterface { ... }
class XmlParser implements ParserInterface { ... }
class JsonParser implements ParserInterface { ... }
class MorrisXmlParser implements ParserInterface { ... } // специализированный XML-парсер
```

#### Композитный хендлер

```php
class ComposableInputHandler implements InputHandlerInterface
{
    public function __construct(
        private TransportInterface $transport,
        private ParserInterface $parser,
    ) {}

    public function readData(string $source, ?string $range = null): DataCollection
    {
        $rawData = $this->transport->fetch($source);
        return $this->parser->parse($rawData, $range);
    }
}
```

#### Конфигурация через type_id

Вместо жёстких `type_id` → класс, использовать конфигурационный подход:

```json
{
  "supplier_id": 123,
  "transport": "sftp",      // или "http", "google_api", "rest_api"
  "parser": "morris_xml",   // или "csv", "excel", "json", "xml"
  "source": "file.xml",
  "column_map_rules": {...}
}
```

Или оставить обратную совместимость с `type_id`, но внутри маппить их на пары transport+parser.

### Преимущества решения

✅ **Переиспользование кода**: один парсер CSV работает с любым транспортом
✅ **Гибкость**: можно получить Morris XML по HTTP, а не только по SFTP
✅ **Тестируемость**: транспорты и парсеры тестируются отдельно
✅ **Single Responsibility**: каждый класс отвечает за одну задачу
✅ **Расширяемость**: новый формат = один новый парсер; новый протокол = один новый транспорт

---

## Проблема 2: Смешивание создания объектов в Aggregator

### Описание проблемы

Класс `Aggregator` выполняет **две разные роли**:

1. **Orchestrator** (координатор бизнес-логики): маршрутизация задач, вызов хендлеров, маппинг, отправка в Kafka
2. **Factory** (фабрика объектов): создание хендлеров в методе `getHandlerByType()`

Это приводит к следующим проблемам:

#### 2.1. Нарушение Single Responsibility Principle

`Aggregator` знает:
- Как оркестрировать процесс обработки
- Как создавать хендлеры (через match по type_id)
- Какие зависимости нужны каждому хендлеру

Это делает класс сложным для понимания и модификации.

#### 2.2. Перегруженный конструктор

```php
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
) { ... }
```

**Проблемы**:
- `Aggregator` зависит от **всех** хендлеров, даже тех, которые не используются в конкретном запросе
- При добавлении нового хендлера нужно модифицировать конструктор `Aggregator`
- Нарушается Open/Closed Principle (открыт для модификации)

#### 2.3. Смешивание логики создания и использования

Метод `getHandlerByType()` содержит `match`-выражение с логикой создания:

```php
private function getHandlerByType(int $typeId, int $supplierId): InputHandlerInterface
{
    return match ($typeId) {
        1 => $this->googleSheetsInputHandler,
        2 => new CsvInputHandler($this->logger, $this->httpTransport, null),
        3 => $this->googleDriveFolderHandler,
        4 => new ExcelInputHandler($this->logger, $this->httpTransport, null),
        5 => $this->morrisXmlSftpInputHandler,
        6 => new ExcelInputHandler($this->logger, null, $this->sftpTransportFactory->create($supplierId)),
        7 => new CsvInputHandler($this->logger, null, $this->sftpTransportFactory->create($supplierId)),
        8 => $this->restApiHandlerFactory->create($supplierId),
        default => throw new \RuntimeException("Unknown type_id: $typeId"),
    };
}
```

**Проблемы**:
- Часть хендлеров создаётся через `new` (типы 2, 4, 6, 7)
- Часть внедряется готовыми через конструктор (типы 1, 3, 5)
- Часть создаётся через фабрики (тип 8)
- **Нет единого подхода к созданию объектов**

### Предлагаемое решение

#### Вариант 1: Единая фабрика хендлеров

Создать `InputHandlerFactory`, который инкапсулирует всю логику создания:

```php
class InputHandlerFactory
{
    public function __construct(
        private LoggerInterface $logger,
        private HttpTransport $httpTransport,
        private SftpTransportFactory $sftpTransportFactory,
        private GoogleSheetsInputHandler $googleSheetsInputHandler,
        private GoogleDriveFolderHandler $googleDriveFolderHandler,
        private MorrisXmlSftpInputHandler $morrisXmlSftpInputHandler,
        private RestApiHandlerFactory $restApiHandlerFactory,
    ) {}

    public function create(int $typeId, int $supplierId): InputHandlerInterface
    {
        return match ($typeId) {
            1 => $this->googleSheetsInputHandler,
            2 => new CsvInputHandler($this->logger, $this->httpTransport, null),
            // ... и т.д.
        };
    }
}
```

**Тогда Aggregator упрощается:**

```php
class Aggregator
{
    public function __construct(
        private LoggerInterface $logger,
        private Mapper $mapper,
        private KafkaProducer $producer,
        private InputHandlerFactory $handlerFactory, // только одна зависимость!
    ) {}

    private function getHandlerByType(int $typeId, int $supplierId): InputHandlerInterface
    {
        return $this->handlerFactory->create($typeId, $supplierId);
    }
}
```

#### Вариант 2: Registry pattern

Использовать реестр хендлеров с регистрацией в DI-контейнере:

```php
class InputHandlerRegistry
{
    private array $handlers = [];

    public function register(int $typeId, callable $factory): void
    {
        $this->handlers[$typeId] = $factory;
    }

    public function get(int $typeId, int $supplierId): InputHandlerInterface
    {
        if (!isset($this->handlers[$typeId])) {
            throw new \RuntimeException("Handler for type_id $typeId not registered");
        }
        return ($this->handlers[$typeId])($supplierId);
    }
}
```

Регистрация в `services.yaml`:

```yaml
services:
  App\Service\InputHandler\InputHandlerRegistry:
    calls:
      - method: register
        arguments: [1, '@App\Service\InputHandler\GoogleSheetsInputHandler']
      - method: register
        arguments: [2, !service_closure '@App\Service\InputHandler\CsvHttpHandlerFactory']
      # и т.д.
```

### Преимущества решения

✅ **Разделение ответственности**: Aggregator занимается только оркестрацией, фабрика — созданием
✅ **Упрощённый конструктор**: одна зависимость вместо N хендлеров
✅ **Open/Closed Principle**: новые хендлеры добавляются без изменения Aggregator
✅ **Единообразие**: все хендлеры создаются через единую точку входа
✅ **Тестируемость**: можно мокировать фабрику целиком

---

## Проблема 3: Специализированный Morris XML Handler привязан к SFTP

### Описание проблемы

Класс `MorrisXmlSftpInputHandler` **жёстко привязан к SFTP** уже в названии и реализации.

**Текущая реализация** (псевдокод):

```php
class MorrisXmlSftpInputHandler implements InputHandlerInterface
{
    public function __construct(
        private SftpTransportFactory $sftpTransportFactory,
        // другие зависимости
    ) {}

    public function readData(string $source, ?string $range = null): DataCollection
    {
        // 1. Получение файла по SFTP (жёстко зашито)
        $sftpTransport = $this->sftpTransportFactory->create($supplierId);
        $xmlContent = $sftpTransport->downloadFile($source);

        // 2. Парсинг Morris XML
        $xml = simplexml_load_string($xmlContent);
        // ... парсинг специфичного XML-формата Morris Costumes

        return $dataCollection;
    }
}
```

### Почему это проблема

#### 3.1. Невозможность получить Morris XML другим способом

Сейчас Morris XML можно получить **только по SFTP**. Но что если:
- Поставщик откроет REST API и начнёт отдавать XML по HTTP?
- XML будут публиковать в Google Drive?
- Потребуется скачивать XML по обычному HTTP(S)?

**Текущее решение**: придётся создавать:
- `MorrisXmlHttpInputHandler`
- `MorrisXmlRestApiInputHandler`
- `MorrisXmlGoogleDriveInputHandler`

...и **дублировать весь код парсинга Morris XML** в каждом новом хендлере.

#### 3.2. Нарушение DRY (Don't Repeat Yourself)

Логика парсинга специфичного формата Morris XML (которая может быть сложной) будет размножена по нескольким классам.

#### 3.3. Сложность тестирования

Невозможно протестировать парсер Morris XML отдельно от SFTP-транспорта.

### Предлагаемое решение

Разделить получение данных и парсинг Morris XML на два независимых компонента.

#### Шаг 1: Создать универсальный Morris XML Parser

```php
class MorrisXmlParser implements ParserInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Парсит XML в формате Morris Costumes
     * @param string $xmlContent Сырое содержимое XML-файла
     * @param string|null $range Не используется для XML
     * @return DataCollection
     */
    public function parse(string $xmlContent, ?string $range = null): DataCollection
    {
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            throw new \RuntimeException('Failed to parse Morris XML');
        }

        $dataCollection = new DataCollection();

        // Логика парсинга специфичного формата Morris
        foreach ($xml->Product as $product) {
            $row = new DataRow([
                'gtin' => (string)$product->GTIN,
                'qty' => (int)$product->AvailableQty,
                'price' => (float)$product->WholesalePrice,
                // ... другие поля
            ]);
            $dataCollection->addRow($row);
        }

        return $dataCollection;
    }
}
```

#### Шаг 2: Использовать композицию транспорт + парсер

**Вариант A: через ComposableInputHandler** (из Проблемы 1)

```php
// Получение Morris XML по SFTP (текущий кейс)
$handler = new ComposableInputHandler(
    transport: $sftpTransport,
    parser: new MorrisXmlParser($logger)
);

// Получение Morris XML по HTTP (новый кейс)
$handler = new ComposableInputHandler(
    transport: $httpTransport,
    parser: new MorrisXmlParser($logger)
);

// Получение Morris XML по REST API (будущий кейс)
$handler = new ComposableInputHandler(
    transport: $restApiTransport,
    parser: new MorrisXmlParser($logger)
);
```

**Вариант B: через специализированные адаптеры**

Если требуется сохранить именованные хендлеры для обратной совместимости:

```php
class MorrisXmlSftpInputHandler implements InputHandlerInterface
{
    public function __construct(
        private SftpTransport $transport,
        private MorrisXmlParser $parser,
    ) {}

    public function readData(string $source, ?string $range = null): DataCollection
    {
        $xmlContent = $this->transport->fetch($source);
        return $this->parser->parse($xmlContent, $range);
    }
}

class MorrisXmlHttpInputHandler implements InputHandlerInterface
{
    public function __construct(
        private HttpTransport $transport,
        private MorrisXmlParser $parser,
    ) {}

    public function readData(string $source, ?string $range = null): DataCollection
    {
        $xmlContent = $this->transport->fetch($source);
        return $this->parser->parse($xmlContent, $range);
    }
}
```

Но здесь всё ещё есть дублирование кода хендлера. Поэтому **Вариант A предпочтительнее**.

#### Шаг 3: Конфигурация

Конфигурационное сообщение для Morris XML может выглядеть так:

```json
{
  "supplier_id": 105,
  "transport": "sftp",
  "parser": "morris_xml",
  "source": "AvailableBatch_Full_Product_Data.xml",
  "column_map_rules": {
    "qty": "qty",
    "upc": "gtin",
    "price": "price"
  }
}
```

Или с сохранением обратной совместимости через `type_id`:

```json
{
  "supplier_id": 105,
  "type_id": 5,  // маппится на transport: "sftp" + parser: "morris_xml"
  "source": "AvailableBatch_Full_Product_Data.xml",
  "column_map_rules": {...}
}
```

### Преимущества решения

✅ **Гибкость получения**: Morris XML можно получить любым транспортом
✅ **DRY**: логика парсинга Morris XML находится в одном месте
✅ **Тестируемость**: парсер можно тестировать отдельно с mock-данными
✅ **Расширяемость**: добавление нового транспорта не требует дублирования парсера
✅ **Переиспользование**: парсер Morris XML можно использовать где угодно

### Пример применения

**Текущий сценарий (SFTP):**
```php
$handler = new ComposableInputHandler(
    transport: $sftpTransport,
    parser: new MorrisXmlParser($logger)
);
$data = $handler->readData('AvailableBatch_Full_Product_Data.xml');
```

**Будущий сценарий (HTTP):**
```php
$handler = new ComposableInputHandler(
    transport: $httpTransport,
    parser: new MorrisXmlParser($logger)
);
$data = $handler->readData('https://morris.com/api/products.xml');
```

**Будущий сценарий (REST API с авторизацией):**
```php
$handler = new ComposableInputHandler(
    transport: $restApiTransport, // уже умеет работать с JWT
    parser: new MorrisXmlParser($logger)
);
$data = $handler->readData('https://api.morris.com/v2/inventory');
```

---

## Общий план рефакторинга

### Этап 1: Разделение транспортов и парсеров

1. Создать интерфейс `ParserInterface`
2. Реализовать парсеры: `CsvParser`, `ExcelParser`, `JsonParser`, `XmlParser`, `MorrisXmlParser`
3. Унифицировать `TransportInterface` (уже частично есть: `HttpTransport`, `SftpTransport`)
4. Создать `GoogleApiTransport`, `RestApiTransport`
5. Создать `ComposableInputHandler` для композиции transport+parser

### Этап 2: Создание InputHandlerFactory

1. Создать `InputHandlerFactory`
2. Перенести всю логику создания хендлеров из `Aggregator::getHandlerByType()` в фабрику
3. Упростить конструктор `Aggregator` — внедрить только фабрику
4. Обновить `services.yaml`

### Этап 3: Миграция конфигурации

1. Добавить поддержку новых полей `transport` и `parser` в `InputConfig`
2. Создать маппинг старых `type_id` → пары (transport, parser) для обратной совместимости
3. Обновить документацию
4. Постепенно мигрировать конфигурации поставщиков на новый формат

### Этап 4: Рефакторинг Morris XML Handler

1. Выделить `MorrisXmlParser` из `MorrisXmlSftpInputHandler`
2. Покрыть парсер unit-тестами
3. Переключить текущие конфигурации Morris на композитный хендлер
4. Удалить `MorrisXmlSftpInputHandler` (опционально, для обратной совместимости можно оставить как алиас)

### Этап 5: Покрытие тестами и документация

1. Unit-тесты для всех парсеров
2. Unit-тесты для всех транспортов
3. Integration-тесты для композитных хендлеров
4. Обновить `CLAUDE.md`, `README.md`, `ARCHITECTURE_AND_PROBLEMS.md`

---

## Backward Compatibility (обратная совместимость)

Все изменения должны быть **обратно совместимы** со старым форматом сообщений:

### Старый формат (type_id)
```json
{
  "supplier_id": 105,
  "type_id": 5,
  "source": "file.xml",
  "column_map_rules": {...}
}
```

### Новый формат (transport + parser)
```json
{
  "supplier_id": 105,
  "transport": "sftp",
  "parser": "morris_xml",
  "source": "file.xml",
  "column_map_rules": {...}
}
```

### Реализация обратной совместимости

```php
class InputConfig
{
    private const TYPE_ID_MAPPING = [
        1 => ['transport' => 'google_api', 'parser' => 'google_sheets'],
        2 => ['transport' => 'http', 'parser' => 'csv'],
        3 => ['transport' => 'google_api', 'parser' => 'google_drive'],
        4 => ['transport' => 'http', 'parser' => 'excel'],
        5 => ['transport' => 'sftp', 'parser' => 'morris_xml'],
        6 => ['transport' => 'sftp', 'parser' => 'excel'],
        7 => ['transport' => 'sftp', 'parser' => 'csv'],
        8 => ['transport' => 'rest_api', 'parser' => 'json'],
    ];

    public function __construct(array $input)
    {
        // Поддержка старого формата через type_id
        if (isset($input['type_id']) && $input['type_id'] !== null) {
            $mapping = self::TYPE_ID_MAPPING[$input['type_id']] ?? null;
            if ($mapping) {
                $this->transport = $mapping['transport'];
                $this->parser = $mapping['parser'];
            }
        }

        // Приоритет новому формату
        if (isset($input['transport'])) {
            $this->transport = $input['transport'];
        }
        if (isset($input['parser'])) {
            $this->parser = $input['parser'];
        }
    }
}
```

---

## Заключение

Предложенный рефакторинг решает три ключевые архитектурные проблемы:

1. ✅ Разделение протоколов и парсеров → гибкость, переиспользование, отсутствие дублирования
2. ✅ Единая фабрика хендлеров → упрощение Aggregator, Single Responsibility, Open/Closed
3. ✅ Универсальный Morris XML Parser → возможность получать Morris XML любым транспортом

При этом сохраняется **обратная совместимость** со старыми конфигурациями через маппинг `type_id`.

Рефакторинг можно проводить **постепенно**, не ломая работающую систему, начиная с наиболее критичных мест (например, Morris XML).
