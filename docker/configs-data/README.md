# Mock Configuration Files

Эта папка содержит **моковые версии конфигурационных файлов** для сборки Docker-образа UP EDI Scraper.

---

## Назначение

При сборке Docker-образа файлы из этой директории копируются в `/var/www/up-edi-scraper/config/` внутри контейнера (см. `Dockerfile`).

Эти файлы необходимы для успешной сборки образа, так как:
- Реальные конфигурационные файлы содержат **секретные данные** (API ключи, пароли, токены)
- Реальные файлы находятся в `.gitignore` и **не коммитятся в репозиторий**
- Без этих файлов сборка Docker-образа завершится ошибкой (COPY не найдёт файлы)

⚠️ **Важно**: Файлы содержат **МОКОВЫЕ данные** и не будут работать с реальными API и сервисами!

Реальные конфигурационные файлы должны находиться в `/config/` на хосте (они в `.gitignore`) и заменяют моковые при запуске контейнера.

---

## Список файлов

| Файл | Описание | Используется в |
|------|----------|----------------|
| `credentials.json` | Google API OAuth 2.0 Client Credentials | Google Sheets (type_id: 1), Google Drive Folder (type_id: 3) |
| `token.json` | Google API OAuth 2.0 Access/Refresh Tokens | Google Sheets (type_id: 1), Google Drive Folder (type_id: 3) |
| `sftp_config.json` | SFTP connection configurations по supplier_id | SFTP handlers (type_id: 5, 6, 7) |
| `rest.json` | REST API endpoint configurations по supplier_id | REST API handler (type_id: 8) |
| `rest.tokens.json` | JWT tokens для REST API по supplier_id | REST API handler (type_id: 8) |

---

## Подробное описание структур

### 1. credentials.json

**Назначение**: Google OAuth 2.0 Client Credentials для авторизации в Google API (Sheets, Drive).

**Где используется**:
- `GoogleSheetsInputHandler` (type_id: 1)
- `GoogleDriveFolderHandler` (type_id: 3)
- `GoogleApiInputHandler`

**Структура**:
```json
{
  "web": {
    "client_id": "string",                      // OAuth 2.0 Client ID
    "project_id": "string",                     // Google Cloud Project ID
    "auth_uri": "string",                       // OAuth authorization endpoint
    "token_uri": "string",                      // OAuth token endpoint
    "auth_provider_x509_cert_url": "string",    // Certificate URL
    "client_secret": "string",                  // OAuth Client Secret
    "redirect_uris": ["string"]                 // Redirect URIs (обычно localhost для консольного приложения)
  }
}
```

**Пример моковых данных**:
```json
{
  "web": {
    "client_id": "123456789012-abcdefghijklmnopqrstuvwxyz123456.apps.googleusercontent.com",
    "project_id": "example-project-12345",
    "auth_uri": "https://accounts.google.com/o/oauth2/auth",
    "token_uri": "https://oauth2.googleapis.com/token",
    "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
    "client_secret": "GOCSPX-ExAmPlEsEcReTkEy1234567890",
    "redirect_uris": ["http://localhost"]
  }
}
```

**Как получить реальный файл**:
1. Создать проект в [Google Cloud Console](https://console.cloud.google.com/)
2. Включить Google Sheets API и Google Drive API
3. Создать OAuth 2.0 Client ID (тип: Web Application)
4. Скачать JSON с креденшелами

---

### 2. token.json

**Назначение**: Google OAuth 2.0 Access Token и Refresh Token для авторизованных запросов к Google API.

**Где используется**:
- `GoogleSheetsInputHandler` (type_id: 1)
- `GoogleDriveFolderHandler` (type_id: 3)
- `GoogleApiInputHandler`

**Структура**:
```json
{
  "access_token": "string",      // Актуальный access token (истекает через ~1 час)
  "expires_in": number,          // Время жизни access token в секундах (обычно 3599)
  "scope": "string",             // Разрешения (scopes): drive, spreadsheets
  "token_type": "string",        // Тип токена (обычно "Bearer")
  "created": number,             // Unix timestamp создания токена
  "refresh_token": "string"      // Refresh token для получения нового access token
}
```

**Пример моковых данных**:
```json
{
  "access_token": "ya29.a0EXAMPLE_ACCESS_TOKEN_STRING_1234567890abcdefghijklmnopqrstuvwxyz",
  "expires_in": 3599,
  "scope": "https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets",
  "token_type": "Bearer",
  "created": 1700000000,
  "refresh_token": "1//0EXAMPLE_REFRESH_TOKEN_STRING_abcdefghijklmnopqrstuvwxyz1234567890"
}
```

**Как получить реальный файл**:
- Автоматически генерируется при первой авторизации через `credentials.json`
- Обновляется автоматически при истечении `access_token` с помощью `refresh_token`
- Хранится в `/config/token.json`

**Важно**:
- `access_token` истекает через ~1 час
- `refresh_token` используется для получения нового `access_token` без повторной авторизации
- Если `refresh_token` истёк, требуется повторная OAuth авторизация

---

### 3. sftp_config.json

**Назначение**: Конфигурация SFTP-подключений для каждого поставщика, использующего SFTP для передачи данных.

**Где используется**:
- `MorrisXmlSftpInputHandler` (type_id: 5) - Morris XML через SFTP
- `ExcelInputHandler` с SFTP транспортом (type_id: 6) - Excel через SFTP
- `CsvInputHandler` с SFTP транспортом (type_id: 7) - CSV через SFTP
- `SftpTransport` и `SftpTransportFactory`

**Структура**:
```json
{
  "supplier_id": {                          // ID поставщика (ключ)
    "sftp": {
      "host": "string",                     // SFTP сервер (hostname или IP)
      "port": number,                       // SFTP порт (обычно 22)
      "username": "string",                 // SFTP логин
      "password": "string",                 // SFTP пароль
      "remotePath": "string"                // Удалённый путь к директории с файлами
    },
    "proxy": {                              // Опционально: прокси-сервер
      "host": "string",                     // Прокси хост
      "port": number,                       // Прокси порт
      "username": "string",                 // Прокси логин
      "password": "string"                  // Прокси пароль
    }
  }
}
```

**Пример моковых данных**:
```json
{
  "100": {
    "sftp": {
      "host": "sftp.example-supplier-1.com",
      "port": 22,
      "username": "example_user_1",
      "password": "ExAmPlEpAsSwOrD123!",
      "remotePath": "/data/feeds/production"
    },
    "proxy": {
      "host": "proxy.example.com",
      "port": 5000,
      "username": "proxy_user",
      "password": "Pr0xyP@ssw0rd!"
    }
  },
  "101": {
    "sftp": {
      "host": "transfer.example-supplier-2.com",
      "port": 22,
      "username": "example_user_2",
      "password": "An0th3rP@ssw0rd456#",
      "remotePath": "/home/feeds"
    },
    "proxy": {}
  }
}
```

**Важно**:
- Каждый `supplier_id` должен иметь уникальную конфигурацию SFTP
- Поле `proxy` может быть пустым объектом `{}`, если прокси не используется
- `remotePath` - базовая директория, относительно которой указываются пути к файлам в `source`

---

### 4. rest.json

**Назначение**: Конфигурация REST API endpoints для каждого поставщика, использующего REST API.

**Где используется**:
- `RestApiInputHandler` (type_id: 8)
- `RestApiHandlerFactory`
- `RestApiConfigProvider`

**Структура**:
```json
{
  "supplier_id": {                                  // ID поставщика (ключ)
    "name": "string",                               // Название поставщика (для логирования)
    "base_uri": "string",                           // Базовый URL API
    "verify_ssl": boolean,                          // Проверять SSL-сертификат (true для production)
    "auth": {                                       // Настройки аутентификации
      "token_uri": "string",                        // Endpoint для получения JWT токена
      "token_json": boolean,                        // Токен в JSON ответе (true) или в заголовке (false)
      "token_include_company_in_body": boolean,     // Включать company_id в body запроса токена
      "username": "string",                         // API логин
      "password": "string",                         // API пароль
      "company_id": number                          // ID компании (если требуется API)
    },
    "items": {                                      // Настройки endpoint'а для получения данных
      "uri": "string",                              // Endpoint для получения товаров
      "method": "string",                           // HTTP метод (обычно GET)
      "page_size": number,                          // Размер страницы (количество записей)
      "page_param": "string",                       // Название GET-параметра для номера страницы
      "size_param": "string"                        // Название GET-параметра для размера страницы
    }
  }
}
```

**Пример моковых данных**:
```json
{
  "100": {
    "name": "Example REST API Supplier",
    "base_uri": "https://api.example-supplier.com",
    "verify_ssl": true,
    "auth": {
      "token_uri": "/api/v1/auth/login",
      "token_json": true,
      "token_include_company_in_body": true,
      "username": "api_user_example",
      "password": "ExAmPlEaPiP@ss123",
      "company_id": 999
    },
    "items": {
      "uri": "/api/v1/products",
      "method": "GET",
      "page_size": 100,
      "page_param": "page",
      "size_param": "per_page"
    }
  }
}
```

**Важно**:
- `base_uri` + `auth.token_uri` = полный URL для получения токена
- `base_uri` + `items.uri` = полный URL для получения данных
- Пагинация реализуется через GET-параметры: `?page=1&per_page=100`

**Пример запросов**:
1. **Получение токена**:
   ```
   POST https://api.example-supplier.com/api/v1/auth/login
   Body: {"username": "api_user_example", "password": "ExAmPlEaPiP@ss123", "company_id": 999}
   ```

2. **Получение данных**:
   ```
   GET https://api.example-supplier.com/api/v1/products?page=1&per_page=100
   Headers: Authorization: Bearer {JWT_TOKEN}
   ```

---

### 5. rest.tokens.json

**Назначение**: Хранилище JWT токенов для REST API по каждому поставщику (кеш токенов).

**Где используется**:
- `RestApiInputHandler` (type_id: 8)
- `FileTokenPersistence`
- `SafeJwtManagerWrapper`

**Структура**:
```json
{
  "supplier_id": {              // ID поставщика (ключ)
    "token": "string",          // JWT токен
    "expiresAt": number|null    // Unix timestamp истечения токена или null
  }
}
```

**Пример моковых данных**:
```json
{
  "100": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkV4YW1wbGUgVXNlciIsImlhdCI6MTUxNjIzOTAyMn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c",
    "expiresAt": null
  }
}
```

**Важно**:
- Файл **автоматически обновляется** при получении нового токена через `FileTokenPersistence`
- Если `expiresAt` = `null`, токен считается бессрочным (до тех пор, пока API не вернёт 401)
- При ошибке аутентификации (401) токен автоматически обновляется через `rest.json` конфигурацию
- JWT токен в примере выше - стандартный JWT формат (header.payload.signature)

**Декодированный пример JWT**:
```json
// Header
{
  "alg": "HS256",
  "typ": "JWT"
}

// Payload
{
  "sub": "1234567890",
  "name": "Example User",
  "iat": 1516239022
}
```

---

## Использование в коде

### Google API (credentials.json + token.json)

```php
// src/Service/InputHandler/GoogleSheetsInputHandler.php
$client = new Google_Client();
$client->setAuthConfig('/var/www/up-edi-scraper/config/credentials.json');
$client->setAccessType('offline');

// Загрузка токена
$accessToken = json_decode(file_get_contents('/var/www/up-edi-scraper/config/token.json'), true);
$client->setAccessToken($accessToken);

// Автообновление токена
if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    file_put_contents('/var/www/up-edi-scraper/config/token.json', json_encode($client->getAccessToken()));
}
```

### SFTP (sftp_config.json)

```php
// src/Service/Factory/SftpTransportFactory.php
$config = json_decode(file_get_contents('/var/www/up-edi-scraper/config/sftp_config.json'), true);
$supplierConfig = $config[$supplierId] ?? null;

if (!$supplierConfig) {
    throw new \RuntimeException("SFTP config not found for supplier_id: $supplierId");
}

$sftp = new SftpTransport(
    host: $supplierConfig['sftp']['host'],
    port: $supplierConfig['sftp']['port'],
    username: $supplierConfig['sftp']['username'],
    password: $supplierConfig['sftp']['password'],
    remotePath: $supplierConfig['sftp']['remotePath']
);
```

### REST API (rest.json + rest.tokens.json)

```php
// src/Service/Factory/RestApiHandlerFactory.php
$restConfig = json_decode(file_get_contents('/var/www/up-edi-scraper/config/rest.json'), true);
$tokensConfig = json_decode(file_get_contents('/var/www/up-edi-scraper/config/rest.tokens.json'), true);

$supplierRestConfig = $restConfig[$supplierId] ?? null;
$supplierToken = $tokensConfig[$supplierId]['token'] ?? null;

// Создание REST API handler с JWT аутентификацией
$handler = new RestApiInputHandler(
    config: new RestApiConfig($supplierRestConfig),
    tokenManager: new SafeJwtManagerWrapper(...),
    httpClient: new HttpClient()
);
```

---

## Безопасность

### ⚠️ Что НЕ нужно делать:

❌ **Коммитить реальные конфигурационные файлы** в репозиторий
❌ **Использовать моковые данные** в production
❌ **Хардкодить креденшелы** в коде
❌ **Передавать конфигурационные файлы** по незащищённым каналам

### ✅ Что нужно делать:

✅ **Хранить реальные файлы** вне репозитория (в `/config/` на хосте, который в `.gitignore`)
✅ **Использовать переменные окружения** где возможно (для non-sensitive данных)
✅ **Использовать secrets management** в production (Vault, AWS Secrets Manager, и т.п.)
✅ **Регулярно ротировать** пароли и токены
✅ **Ограничивать доступ** к конфигурационным файлам (chmod 600)

---

## Workflow при разработке

### Первичная настройка (новый разработчик):

1. Клонировать репозиторий
2. Получить реальные конфигурационные файлы у DevOps/Team Lead (по защищённому каналу)
3. Положить их в `/config/` (эта папка в `.gitignore`)
4. Собрать и запустить Docker-контейнер

### Обновление конфигураций:

1. Моковые файлы в `docker/configs-data/` **обновляются только при изменении структуры**
2. Реальные файлы в `/config/` **обновляются при изменении креденшелов/endpoints**
3. При изменении структуры - обновить моковые файлы и этот README

---

## FAQ

**Q: Почему моковые файлы копируются в образ, если они не работают?**
A: Потому что `COPY` в Dockerfile требует, чтобы файлы существовали. Без моковых файлов сборка образа упадёт с ошибкой.

**Q: Как заменить моковые файлы на реальные в контейнере?**
A: Реальные файлы находятся в `/config/` на хосте (в `.gitignore`) и монтируются в контейнер при его запуске, заменяя моковые.

**Q: Нужно ли обновлять моковые файлы при изменении паролей?**
A: Нет. Моковые файлы обновляются только при изменении **структуры данных** (новые поля, изменение формата).

**Q: Можно ли использовать эти файлы для тестирования?**
A: Нет, они содержат фейковые данные. Для тестов нужны либо реальные креденшелы, либо mock-объекты в unit-тестах.

**Q: Где хранятся реальные конфигурационные файлы в production?**
A: Зависит от инфраструктуры. Обычно: Kubernetes Secrets, Docker Secrets, HashiCorp Vault, AWS Secrets Manager, или примонтированный volume с ограниченным доступом.

---

## Пример реальной структуры проекта

```
up-edi-scraper/
├── config/                          # Реальные конфиги (в .gitignore, НЕ в git)
│   ├── credentials.json             # ← Реальный Google OAuth credentials
│   ├── token.json                   # ← Реальный Google OAuth token
│   ├── sftp_config.json             # ← Реальные SFTP креденшелы
│   ├── rest.json                    # ← Реальные REST API конфиги
│   └── rest.tokens.json             # ← Реальные JWT токены
│
├── docker/
│   └── configs-data/                # Моковые конфиги (в git, для сборки образа)
│       ├── credentials.json         # ← Моковый Google OAuth credentials
│       ├── token.json               # ← Моковый Google OAuth token
│       ├── sftp_config.json         # ← Моковые SFTP креденшелы
│       ├── rest.json                # ← Моковые REST API конфиги
│       ├── rest.tokens.json         # ← Моковые JWT токены
│       └── README.md                # ← Этот файл
│
├── Dockerfile                       # Копирует моковые файлы из docker/configs-data/ в /config/
└── .gitignore                       # Содержит /config/*.json (реальные файлы не коммитятся)
```

**При сборке Docker-образа**:
- Моковые файлы из `docker/configs-data/` копируются в `/var/www/up-edi-scraper/config/` внутри контейнера

**При запуске контейнера**:
- Реальные файлы из `/config/` на хосте заменяют моковые файлы в контейнере

---

## История изменений

- **2025-01-XX**: Создание моковых конфигурационных файлов с полной структурой
- **TODO**: При изменении структуры API конфигураций - обновить моковые файлы и этот документ
