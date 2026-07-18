# Platform API v1

Base URL:

```text
http://localhost:8000/api/v1/{team_slug}
```

Все запросы требуют API key, привязанный к указанной команде. Владелец команды создаёт ключ в разделе **API keys**, выбирает scopes и передаёт полученное значение как Bearer token:

```http
Authorization: Bearer 1|plain-text-api-key
Accept: application/json
```

После `php artisan db:seed` готовый demo account использует team slug `demo-users-team` и API key `demo-api-key`:

```bash
curl http://localhost:8000/api/v1/demo-users-team/apps \
  -H 'Authorization: Bearer demo-api-key' \
  -H 'Accept: application/json'
```

Если ключ отсутствует или недействителен, API отвечает `401`. Если ключ выпущен для другой команды или у него нет требуемого scope, API отвечает `403`. Приложения и вложенные сущности другой команды не раскрываются и отвечают `404`.

Ключ всегда принадлежит одной команде. Членство создателя в нескольких командах не расширяет доступ ключа. При удалении команды все её ключи отзываются автоматически, а plaintext нового ключа показывается только один раз.

## Scopes

Scopes чтения и записи независимы: например, CI runner может получить `builds:write` и `logs:write`, не имея доступа к просмотру истории или логов.

| Scope | Доступ |
| --- | --- |
| `apps:read` / `apps:write` | Просмотр / управление приложениями |
| `identifiers:read` / `identifiers:write` | Просмотр / управление platform identifiers |
| `assets:read` / `assets:write` | Скачивание / загрузка и удаление icon и splashscreen |
| `credentials:read` / `credentials:write` | Чтение secrets и файлов / создание, ротация и удаление credentials |
| `builds:read` / `builds:write` | История и детали / создание и изменение сборок |
| `logs:read` / `logs:write` | Чтение / добавление build logs |

Все API routes проверяют соответствующий scope. Для собственного runner обычно достаточно `builds:write` и `logs:write`; `builds:read` и `logs:read` нужны только для чтения состояния и журнала.

## Mobile apps

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps` | Список приложений команды |
| `POST` | `/apps` | Создать приложение |
| `GET` | `/apps/{app_slug}` | Получить приложение |
| `PATCH` | `/apps/{app_slug}` | Изменить приложение |
| `DELETE` | `/apps/{app_slug}` | Удалить приложение |

Поля приложения: `name` (обязательно при создании), `slug` (опционально, генерируется из name), `description`.

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"name":"Customer App","description":"Public mobile client"}'
```

## Icon и splashscreen

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `POST` / `PUT` | `/apps/{app_slug}/assets/icon` | Загрузить или заменить icon |
| `GET` | `/apps/{app_slug}/assets/icon` | Получить icon |
| `DELETE` | `/apps/{app_slug}/assets/icon` | Удалить icon |
| `POST` / `PUT` | `/apps/{app_slug}/assets/splashscreen` | Загрузить или заменить splashscreen |
| `GET` | `/apps/{app_slug}/assets/splashscreen` | Получить splashscreen |
| `DELETE` | `/apps/{app_slug}/assets/splashscreen` | Удалить splashscreen |

Файл передаётся как multipart-поле `file`, должен быть изображением не больше 10 MB. Для реальных multipart-клиентов рекомендуется `POST`: не все PHP SAPIs разбирают multipart body для `PUT`.

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps/customer-app/assets/icon \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -F 'file=@icon.png'
```

## App identifiers

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps/{app_slug}/identifiers` | Список identifiers |
| `POST` | `/apps/{app_slug}/identifiers` | Создать identifier |
| `GET` | `/apps/{app_slug}/identifiers/{id}` | Получить identifier |
| `PATCH` | `/apps/{app_slug}/identifiers/{id}` | Изменить identifier |
| `DELETE` | `/apps/{app_slug}/identifiers/{id}` | Удалить identifier |

Поля: `platform` (`ios` или `android`), `identifier` (bundle ID / package name), `name` и `version` (опционально). Положительный целочисленный `version_code` доступен только для Android.

## Credentials

`GET /credential-types` возвращает поддерживаемые типы, платформы, допустимые расширения, обязательные поля `payload`, способ привязки (`attachment`) и доступные build profiles (`profiles`).

Signing credentials привязываются к build profile, который определяет канал распространения и формат результата:

| Platform | `profile_type` | Артефакт | Назначение |
| --- | --- | --- | --- |
| iOS | `development` | `.ipa` | Личная разработка и тестирование на зарегистрированных устройствах |
| iOS | `adhoc` | `.ipa` | Тестовые сборки для заданного списка устройств |
| iOS | `appstore` | `.ipa` | TestFlight и App Store |
| iOS | `enterprise` | `.ipa` | Внутренняя Enterprise-дистрибуция |
| Android | `development` | `.apk` | Личная разработка и локальное тестирование |
| Android | `preview` | `.apk` | Передача команде и внутренним тестировщикам |
| Android | `production` | `.aab` | Полностью подписанный bundle для Google Play |

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps/{app_slug}/credentials` | Список credentials приложения |
| `POST` | `/apps/{app_slug}/credentials` | Создать credential и прикрепить к identifier |
| `GET` | `/apps/{app_slug}/credentials/{id}` | Получить credential |
| `PATCH` | `/apps/{app_slug}/credentials/{id}` | Изменить name, expires_at или payload |
| `DELETE` | `/apps/{app_slug}/credentials/{id}` | Удалить credential |
| `GET` | `/apps/{app_slug}/credentials/{id}/file` | Скачать файл |
| `POST` | `/apps/{app_slug}/credentials/{id}/file` | Заменить файл |
| `DELETE` | `/apps/{app_slug}/credentials/{id}/file` | Удалить файл |

При создании обязательны `app_identifier_id`, `type`, `name`, подходящий `payload` и multipart-файл `file`. Для `ios_certificate`, `provisioning_profile` и `android_keystore` также обязателен совместимый `profile_type`; эти credentials привязываются к автоматически созданному Profile. Сервисные credentials привязываются непосредственно к identifier и не принимают `profile_type`. `expires_at` опционален. Поля payload шифруются в базе данных. Тип и привязка credential после создания неизменяемы; для их смены credential нужно пересоздать.

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps/customer-app/credentials \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -F 'app_identifier_id=019f...' \
  -F 'type=android_keystore' \
  -F 'profile_type=production' \
  -F 'name=Release keystore' \
  -F 'payload[store_password]=secret' \
  -F 'payload[key_alias]=release' \
  -F 'payload[key_password]=secret' \
  -F 'file=@release.jks'
```

Успешное создание возвращает `201`, чтение и изменение — `200`, удаление — `204`. Ошибки валидации возвращаются стандартным JSON Laravel со статусом `422`.

## Builds и logs

Builds поддерживают два режима отчётности:

- `automatic` — App Manager запускает CI/CD pipeline, проверяет состояние у провайдера и загружает его журнал;
- `manual` — собственный runner управляет lifecycle и дописывает plain-text журнал через API.

Pipeline сохраняет режим в каждом новом build, поэтому изменение настройки не меняет уже запущенные задачи. Для automatic build ручная запись статуса или лога возвращает `409`.

| Method | Endpoint | Scope | Назначение |
| --- | --- | --- | --- |
| `GET` | `/apps/{app_slug}/builds` | `builds:read` | Последние builds с пагинацией |
| `POST` | `/apps/{app_slug}/builds` | `builds:write` | Создать manual build в `pending` |
| `GET` | `/apps/{app_slug}/builds/{id}` | `builds:read` | Прочитать состояние build |
| `PATCH` | `/apps/{app_slug}/builds/{id}` | `builds:write` | Установить `running`, `completed`, `failed` или `cancelled` |
| `GET` | `/apps/{app_slug}/builds/{id}/logs` | `logs:read` | Прочитать полный журнал |
| `POST` | `/apps/{app_slug}/builds/{id}/logs` | `logs:write` | Дописать plain-text chunk |

Создание идемпотентно по паре team + `request_uuid`. Повторный запрос возвращает тот же build и не создаёт дубликат:

```bash
BUILD_RESPONSE=$(curl -sS -X POST \
  http://localhost:8000/api/v1/acme/apps/customer-app/builds \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "request_uuid":"598a349f-b526-40ba-8e54-247772bce160",
    "profile_type":"production",
    "app_identifier_id":"019f...",
    "context":{"commit":"abc123","runner":"self-hosted"}
  }')
```

Runner использует `data.id` из ответа для последующих запросов:

```bash
curl -X PATCH http://localhost:8000/api/v1/acme/apps/customer-app/builds/BUILD_ID \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"status":"running"}'

curl -X POST http://localhost:8000/api/v1/acme/apps/customer-app/builds/BUILD_ID/logs \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"content":"compile: started\n"}'

curl -X PATCH http://localhost:8000/api/v1/acme/apps/customer-app/builds/BUILD_ID \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"status":"completed"}'
```

Один log chunk ограничен 256 KiB, полный журнал — 5 MB. Переводы статуса идемпотентны; попытка изменить уже завершённый build на другой terminal status возвращает `409`. Запрос отмены из UI переводит manual build в `cancel_requested`, после чего runner подтверждает `cancelled` либо сообщает фактический `completed`/`failed`.

Для manual GitHub pipeline App Manager создаёт build до dispatch и передаёт его UUID через выбранный string input `workflow_dispatch`. Runner должен экспортировать это значение как `APP_MANAGER_BUILD_ID` или явно передать в actions fastlane-плагина. Полный пример находится в [`libs/fastlane-plugin/README.md`](../../../libs/fastlane-plugin/README.md).
