# UP EDI Scraper — агрегатор EDI‑данных поставщиков

[![Release](https://img.shields.io/github/v/release/sni10/ETL-EDI-data-scrapper?style=for-the-badge&logo=github&logoColor=white)](https://github.com/sni10/ETL-EDI-data-scrapper/releases)
[![Release Workflow](https://img.shields.io/github/actions/workflow/status/sni10/ETL-EDI-data-scrapper/release.yml?style=for-the-badge&logo=githubactions&logoColor=white&label=Release)](https://github.com/sni10/ETL-EDI-data-scrapper/actions/workflows/release.yml)
[![Tests](https://img.shields.io/github/actions/workflow/status/sni10/ETL-EDI-data-scrapper/tests.yml?style=for-the-badge&logo=githubactions&logoColor=white&label=Tests)](https://github.com/sni10/ETL-EDI-data-scrapper/actions/workflows/tests.yml)
[![Coverage](https://img.shields.io/badge/Coverage-65%25-brightgreen?style=for-the-badge&logo=codecov&logoColor=white)](https://github.com/sni10/ETL-EDI-data-scrapper/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com/)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)


Консольное приложение на Symfony 7 / PHP 8.2+, которое агрегирует и нормализует EDI‑данные (прайсы, остатки и т.п.) от разных поставщиков из различных источников (Google Sheets, HTTP/SFTP‑файлы, REST API) и публикует результат в Kafka.

## Основные возможности

- Чтение данных поставщиков из нескольких типов источников:
  - Google Sheets / Google Drive
  - CSV/Excel по HTTP(S)
  - CSV/Excel по SFTP
  - REST API (с JWT‑аутентификацией)
- Поддержка мультисорсного режима: объединение нескольких файлов/источников по ключу (по умолчанию `upc`)
- Гибкий маппинг колонок через `column_map_rules` в Kafka‑сообщении
- Отправка обработанных записей (`DataRow`) в выходной Kafka‑топик
- Логирование и контроль ошибок, автоперезапуск консьюмера через supervisord

## Архитектура в двух словах

- Модели (DataRow, DataCollection, DataSetCollection) — только структура данных, без бизнес‑логики
- Сервисы (Aggregator, Mapper, KafkaConsumer/Producer) — оркестрация и бизнес‑правила
- InputHandler’ы — реализация паттерна Strategy для разных типов источников
- Transports (HttpTransport, SftpTransport) — реализация протоколов доступа к данным
- Фабрики (RestApiHandlerFactory, SftpTransportFactory) — создание хендлеров и транспортов

## Требования

- PHP 8.2+
- Symfony 7.1
- Docker и Docker Compose
- Расширения PHP: rdkafka, amqp, ssh2, xdebug, mbstring, gd, pdo_pgsql, zip, sockets, simplexml

## Быстрый старт (Docker + Makefile)

```powershell
git clone <repository-url>
cd etl-edi-scraper

# выбрать окружение (test или prod)
$env:ENVIRONMENT="test"

# сборка и запуск контейнеров
make dc_up

# логи
make dc_logs

# остановка и очистка
make dc_down

# рестарт
make dc_restart

# вход в контейнер
make dc_exec
```

## Ручной запуск через Docker Compose

```powershell
# из корня репозитория
docker-compose -f docker-compose.yml -f docker\config-envs\$env:ENVIRONMENT\docker-compose.override.yml up -d --build

# остановка и очистка
docker-compose down
```

При старте контейнера `supervisord` автоматически запускает консольную команду:

```powershell
php /var/www/etl-edi-scraper/bin/console app:consume
```

## Поддерживаемые типы источников данных (type_id)

Система поддерживает 8 типов источников данных + мультисорсный режим:

| type_id | Название | Описание | Пример source |
|---------|----------|----------|---------------|
| **1** | **Google Sheets** | Данные из Google Таблицы по ID документа | `1aBcDeFgHiJkLmNoPqRsTuVwXyZ...` |
| **2** | **CSV через HTTP** | CSV-файл, доступный по HTTP(S) URL | `https://example.com/feed.csv` |
| **3** | **Google Drive Folder** | Файлы из папки Google Drive по ID папки | `1xYzAbC123456789DeF0GhIjKl...` |
| **4** | **Excel через HTTP** | Excel-файл (.xlsx), доступный по HTTP(S) URL | `https://example.com/data.xlsx` |
| **5** | **Morris XML через SFTP** | XML-файл в формате Morris, получаемый по SFTP | `AvailableBatch_Full_Product_Data.xml` |
| **6** | **Excel через SFTP** | Excel-файл (.xlsx), получаемый по SFTP | `inventory_feed.xlsx` |
| **7** | **CSV через SFTP** | CSV-файл, получаемый по SFTP | `daily_inventory.csv` |
| **8** | **REST API** | Данные из REST API с JWT-аутентификацией | `https://api.example.com/v1/products` |
| **null** | **Multi-Source** | Объединение нескольких источников разных типов | Массив объектов SubSource |

### Особенности типов

- **Google Sheets (1)** и **Google Drive Folder (3)**: используют Google API, требуют настройки `credentials.json`
- **HTTP источники (2, 4)**: используют `HttpTransport`, поддерживают базовую HTTP-аутентификацию
- **SFTP источники (5, 6, 7)**: используют `SftpTransport`, требуют конфигурации в `sftp_config.json` по `supplier_id`
- **REST API (8)**: поддерживает JWT-аутентификацию, требует конфигурации в `rest.json` и `rest.tokens.json`
- **Morris XML (5)**: специализированный хендлер для XML-формата поставщика Morris Costumes
- **Multi-Source (null)**: позволяет объединять данные из разных источников по ключу (например, `upc`)

### Параметр range

Параметр `range` (опциональный) используется для указания диапазона данных:
- Для **Google Sheets (1)**: формат A1-нотации, например `A1:Z1000` или `A1:D`
- Для **Excel файлов (4, 6)**: аналогично, диапазон ячеек
- Для остальных типов: обычно `null`

## Формат входного сообщения Kafka

Пример одиночного источника:

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

Пример мультисорсного сообщения (`type_id` на верхнем уровне `null`):

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

## Отладка

Для отладки удобнее и безопаснее всего использовать удалённый интерпретатор из контейнера.

- В IDE выбрать конфигурацию типа **PHP Script**
- В качестве интерпретатора указать удалённый интерпретатор из Docker‑контейнера
- IDE поднимет отдельный контейнер с монтированным кодом проекта для изолированного запуска скрипта
- При необходимости можно добавить дополнительные аргументы, переменные окружения и т.п.

Полезные команды:

```powershell
# логи консольной команды
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.out.log
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisor/symfony_command.err.log

# логи supervisord
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/supervisord.log

# PHP-ошибки
docker exec -it up_edi_scraper-php-up-edi-1 tail -f /var/log/php_errors.log
```

Конфигурация Xdebug находится в `docker/config-envs/{ENVIRONMENT}/php.ini`.
