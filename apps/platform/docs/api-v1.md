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
| `credentials:read` | Чтение только metadata credentials |
| `credentials:reveal` | Раскрытие payload и скачивание файлов; требует права команды `manage_credentials` |
| `credentials:write` | Создание, ротация и удаление credentials |
| `builds:read` / `builds:write` | История и детали / создание и изменение сборок |
| `logs:read` / `logs:write` | Чтение / добавление build logs |
| `integrations:read` / `integrations:write` | Просмотр / подключение, sync и custom provider operations |
| `integrations:proxy` | Произвольные authenticated-запросы к provider API, включая remote secrets и mutations |
| `webhooks:read` / `webhooks:write` | Просмотр / управление endpoint-ами, test и redelivery |
| `deliveries:read` | Просмотр истории webhook delivery |

Все API routes проверяют соответствующий scope. Для собственного runner обычно достаточно `builds:write` и `logs:write`; `builds:read` и `logs:read` нужны только для чтения состояния и журнала.

## Mobile apps

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps` | Список приложений команды |
| `POST` | `/apps` | Создать приложение |
| `GET` | `/apps/{app_slug}` | Получить приложение |
| `PATCH` | `/apps/{app_slug}` | Изменить приложение |
| `DELETE` | `/apps/{app_slug}` | Удалить приложение |

Поля приложения: `name` (обязательно при создании), `slug` (опционально, генерируется из name), `description`, `metadata` (опциональный JSON-объект свободной формы). Поле `metadata` возвращается в ответах списка, создания, просмотра и изменения приложения; передайте `null`, чтобы очистить его.

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"name":"Customer App","description":"Public mobile client","metadata":{"owner":"mobile-team","channel":"production"}}'
```

## Icon и splashscreen

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps/{app_slug}/assets` | Получить map доступных файлов и URL для скачивания |
| `POST` / `PUT` | `/apps/{app_slug}/assets/icon` | Загрузить или заменить icon |
| `GET` | `/apps/{app_slug}/assets/icon` | Получить icon |
| `DELETE` | `/apps/{app_slug}/assets/icon` | Удалить icon |
| `POST` / `PUT` | `/apps/{app_slug}/assets/splashscreen` | Загрузить или заменить splashscreen |
| `GET` | `/apps/{app_slug}/assets/splashscreen` | Получить splashscreen |
| `DELETE` | `/apps/{app_slug}/assets/splashscreen` | Удалить splashscreen |

Файл передаётся как multipart-поле `file`, должен быть изображением не больше 10 MB. Для реальных multipart-клиентов рекомендуется `POST`: не все PHP SAPIs разбирают multipart body для `PUT`.

`GET /apps/{app_slug}/assets` возвращает плоский JSON map в поле `data`. Ключ состоит из типа asset и имени конвертации; `original` обозначает исходный файл. В map включаются только загруженные оригиналы и уже готовые конвертации:

```json
{
  "data": {
    "icon.original": "http://localhost:8000/api/v1/acme/apps/customer-app/assets/icon/files/original",
    "icon.ios-icon-40": "http://localhost:8000/api/v1/acme/apps/customer-app/assets/icon/files/ios-icon-40",
    "icon.android-icon-mdpi": "http://localhost:8000/api/v1/acme/apps/customer-app/assets/icon/files/android-icon-mdpi",
    "splashscreen.original": "http://localhost:8000/api/v1/acme/apps/customer-app/assets/splashscreen/files/original"
  }
}
```

Каждый URL требует тот же Bearer token со scope `assets:read` и возвращает файл как attachment.

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

Поля: `platform` (`ios`, `android` или `web`), `identifier` (bundle ID / package name / домен), `name` и `version` (опционально). Для Web требуется домен или поддомен без схемы, порта и пути; он нормализуется в нижний регистр. Положительный целочисленный `version_code` доступен только для Android. Web identifiers не используют platform credentials.

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
| `POST` | `/apps/{app_slug}/credentials/{id}/reveal` | Получить secret payload (`credentials:reveal`) |
| `PATCH` | `/apps/{app_slug}/credentials/{id}` | Изменить name, expires_at или payload |
| `DELETE` | `/apps/{app_slug}/credentials/{id}` | Удалить credential |
| `GET` | `/apps/{app_slug}/credentials/{id}/file` | Скачать файл |
| `POST` | `/apps/{app_slug}/credentials/{id}/file` | Заменить файл |
| `DELETE` | `/apps/{app_slug}/credentials/{id}/file` | Удалить файл |

При создании обязательны `app_identifier_id`, `type`, `name`, подходящий `payload` и multipart-файл `file`. Для `ios_certificate`, `provisioning_profile` и `android_keystore` также обязателен совместимый `profile_type`; эти credentials привязываются к автоматически созданному Profile. Сервисные credentials привязываются непосредственно к identifier и не принимают `profile_type`. `expires_at` опционален. Обычные list/show/create/update ответы содержат только metadata и никогда не возвращают `payload`; секреты доступны только через `POST .../reveal` с `Cache-Control: no-store`. Поля payload шифруются в базе данных, а credential-файлы шифруются приложением до записи на storage. Тип и привязка credential после создания неизменяемы; для их смены credential нужно пересоздать.

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

## Expo.dev

Expo integration должна быть включена оператором через `INTEGRATIONS_ENABLED=true` и
`EXPO_INTEGRATION_ENABLED=true`. Expo access token передаётся только при подключении, сохраняется
в зашифрованном credential store и никогда не возвращается API.

### Подключение и проекты

| Method | Endpoint | Scopes | Назначение |
| --- | --- | --- | --- |
| `POST` | `/integrations/expo` | `integrations:write` | Подключить Expo account или заменить token существующего подключения |
| `POST` | `/integrations/{integration_id}/expo/sync` | `integrations:write` | Обновить account metadata и inventory проектов |
| `GET` | `/integrations/{integration_id}/expo/projects` | `integrations:read` | Получить синхронизированные Expo projects |
| `DELETE` | `/integrations/{integration_id}/expo` | `integrations:write` | Отключить account, сохранив project links и remote history |
| `POST` | `/integrations/{integration_id}/expo/projects/{project_id}/import` | `integrations:write`, `apps:write`, `credentials:write` | Импортировать Expo project как новое приложение вместе с identifiers и EAS credentials |

При доступе token к нескольким Expo accounts укажите `account_name`:

```bash
curl -X POST http://localhost:8000/api/v1/acme/integrations/expo \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"access_token":"EXPO_ACCESS_TOKEN","account_name":"acme"}'
```

Повторный запрос для того же Expo account обновляет token и возвращает `200`; новое подключение
возвращает `201`. Если account подключился, но начальный inventory sync не удался, интеграция
сохраняется в состоянии `degraded`, а причина возвращается в `meta.sync_error`.

### Связь с приложением и sync

| Method | Endpoint | Scopes | Назначение |
| --- | --- | --- | --- |
| `GET` | `/apps/{app_slug}/expo` | `apps:read`, `integrations:read` | Получить link и live Expo metadata, builds и submissions |
| `POST` | `/apps/{app_slug}/expo` | `apps:write`, `credentials:write`, `integrations:write` | Создать Expo project или связать найденный project |
| `POST` | `/apps/{app_slug}/expo/sync` | `apps:write`, `credentials:write`, `integrations:write` | Явно подтянуть metadata, identifiers и EAS credentials из Expo |
| `PATCH` | `/apps/{app_slug}/expo/automatic-sync` | `apps:write`, `credentials:write`, `integrations:write` | Включить или выключить автоматический push App Manager → Expo для приложения (`{"enabled":true}`) |
| `DELETE` | `/apps/{app_slug}/expo` | `apps:write`, `integrations:write` | Удалить локальную связь без удаления remote project |
| `GET` | `/apps/{app_slug}/expo/identifiers` | `identifiers:read`, `integrations:read` | Сравнить identifiers в App Manager и Expo |
| `POST` | `/apps/{app_slug}/expo/identifiers/sync` | `identifiers:write`, `integrations:write` | Импортировать Expo-only и создать на Expo локальные identifiers |
| `POST` | `/apps/{app_slug}/expo/identifiers/{identifier_id}/provision` | `identifiers:write`, `credentials:write`, `integrations:write` | Создать remote identifier и выбранные прикреплённые credentials |
| `POST` | `/apps/{app_slug}/expo/identifiers/{identifier_id}/credentials/{credential_id}` | `credentials:write`, `integrations:write` | Push/re-sync одного credential |

Для создания нового remote project:

```json
{
  "integration_id": "019f...",
  "mode": "create",
  "sync_metadata": true
}
```

Для связи с найденным project используйте `"mode":"existing"` и передайте локальный
`expo_project_id` из endpoint списка. Операция `provision` без `credential_ids` отправляет все
поддерживаемые credentials этого identifier, у которых загружен файл. Если список передан,
каждый credential обязан быть прикреплён к identifier:

```json
{
  "credential_ids": ["019f...", "019f..."]
}
```

Remote credential resources Expo immutable: повторный push создаёт новый ресурс и обновляет
локальную связь; старый remote resource автоматически не удаляется. Частичные ошибки возвращаются
в `data.errors` или `meta.errors`, при этом успешно созданные links сохраняются.

### Expo proxy

REST proxy всегда использует origin из `EXPO_API_URL`; клиент не может передать другой host, а
его `Authorization` header заменяется сохранённым Expo token.

| Method | Endpoint | Scope |
| --- | --- | --- |
| `GET` | `/integrations/{integration_id}/expo/proxy/{expo_api_path}` | `integrations:proxy` |
| `POST` / `PUT` / `PATCH` / `DELETE` | `/integrations/{integration_id}/expo/proxy/{expo_api_path}` | `integrations:proxy` |
| `POST` | `/integrations/{integration_id}/expo/graphql` | `integrations:proxy` |

`integrations:proxy` — отдельный high-trust scope: proxy может читать remote Expo secrets и
выполнять операции, для которых в App Manager есть более узкие scopes. Не выдавайте его runner-ам,
которым достаточно custom endpoints. GraphQL proxy всегда требует этот scope, даже для query:
один GraphQL document может содержать несколько операций, поэтому API не пытается определять права
по тексту запроса.

```bash
curl -X POST \
  http://localhost:8000/api/v1/acme/integrations/INTEGRATION_ID/expo/graphql \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "query":"query Project($id: String!) { app { byId(appId: $id) { id name slug } } }",
    "variables":{"id":"EXPO_PROJECT_ID"},
    "operation_name":"Project"
  }'
```

REST proxy сохраняет upstream status и body, а также безопасные response headers
`Content-Type`, `Retry-After`, `ETag` и rate-limit headers. GraphQL errors возвращаются как
`502 expo_upstream_error`; недоступность Expo — как `503`.

## Outbound webhooks

| Method | Endpoint | Scope | Назначение |
| --- | --- | --- | --- |
| `GET` | `/webhooks` | `webhooks:read` | Список endpoint-ов |
| `POST` | `/webhooks` | `webhooks:write` | Создать endpoint и получить signing secret |
| `GET` | `/webhooks/{endpoint_id}` | `webhooks:read` | Получить endpoint без secret |
| `PATCH` | `/webhooks/{endpoint_id}` | `webhooks:write` | Изменить endpoint, subscriptions и filters |
| `POST` | `/webhooks/{endpoint_id}/test` | `webhooks:write` | Поставить test delivery в очередь |
| `POST` | `/webhooks/{endpoint_id}/rotate-secret` | `webhooks:write` | Немедленно заменить signing secret |
| `DELETE` | `/webhooks/{endpoint_id}` | `webhooks:write` | Отключить и удалить endpoint |
| `GET` | `/webhooks/{endpoint_id}/deliveries` | `deliveries:read` | История delivery с cursor pagination |
| `GET` | `/webhooks/{endpoint_id}/deliveries/{delivery_id}` | `deliveries:read` | Метаданные одной delivery |
| `POST` | `/webhooks/{endpoint_id}/deliveries/{delivery_id}/redeliver` | `webhooks:write` | Создать связанную manual redelivery |

При создании и изменении endpoint можно передать необязательный объект `filters`:

```json
{
  "name": "Production failures",
  "url": "https://automation.example.com/app-manager",
  "events": ["build.completed", "artifact.created"],
  "filters": {
    "mobile_app_ids": ["019f..."],
    "tags": ["release", "customer-a"],
    "build_statuses": ["failed"],
    "profile_types": ["production"]
  }
}
```

Пустая группа filter не ограничивает delivery. Непустые группы соединяются через AND, значения
внутри группы — через OR. Если событие не содержит требуемого application или build context, оно
не совпадает с фильтром. Контекст фиксируется вместе с outbox event: последующее изменение тегов,
status или приложения не меняет результат публикации уже созданного события. Явный test webhook
всегда отправляется выбранному endpoint независимо от subscriptions и filters.

Каждый запрос содержит `X-App-Manager-Event`, `X-App-Manager-Delivery`,
`X-App-Manager-Timestamp` и `X-App-Manager-Signature`. Подпись `v1` — это HMAC-SHA256 от строки
`<timestamp>.<raw_body>` с использованием secret, показанного только при создании или ротации.
Consumer должен проверять timestamp и подпись до JSON parsing, а также обрабатывать delivery
идемпотентно. Retries сохраняют delivery ID; manual redelivery получает новый delivery ID и
ссылается на исходную через `redelivery_of_id`.

## Builds и logs

Builds поддерживают три режима отчётности:

- `automatic` — App Manager запускает GitHub Actions, GitLab CI/CD или Expo EAS Workflow pipeline, проверяет состояние у провайдера и загружает журнал либо безопасную сводку EAS jobs;
- `manual` — собственный runner управляет lifecycle и дописывает plain-text журнал через API;
- `eas` — build запущен напрямую на expo.dev (например, `eas build` из CLI); App Manager регистрирует его по `expo_build_id` и сам опрашивает EAS до терминального статуса, забирая реальные таймстемпы, журнал и артефакты.

Pipeline сохраняет режим в каждом новом build, поэтому изменение настройки не меняет уже запущенные задачи. Для automatic build ручная запись статуса или лога возвращает `409`.

| Method | Endpoint | Scope | Назначение |
| --- | --- | --- | --- |
| `GET` | `/apps/{app_slug}/builds` | `builds:read` | Последние builds с пагинацией |
| `POST` | `/apps/{app_slug}/builds` | `builds:write` | Создать manual или EAS build в `pending` |
| `GET` | `/apps/{app_slug}/builds/{id}` | `builds:read` | Прочитать состояние build |
| `PATCH` | `/apps/{app_slug}/builds/{id}` | `builds:write` | Установить `running`, `completed`, `failed` или `cancelled` (только manual) |
| `POST` | `/apps/{app_slug}/builds/{id}/sync-eas` | `builds:write` | Немедленно синхронизировать EAS build с expo.dev |
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
    "context":{"commit":"abc123","runner":"self-hosted"},
    "parameters":{"channel":"internal","minify":true}
  }')
```

`parameters` — произвольный JSON object размером до 64 KiB. App Manager сохраняет его в зашифрованном поле build и возвращает только в авторизованном build API; webhook payloads его не содержат. `context` остаётся техническим snapshot runner-а и не заменяет параметры запуска.

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

Один log chunk ограничен 256 KiB, полный журнал — 5 MB. Переводы статуса идемпотентны; попытка изменить уже завершённый build на другой terminal status возвращает `409`. Запрос отмены из UI переводит manual build в `cancel_requested`, после чего runner подтверждает `cancelled` либо сообщает фактический `completed`/`failed`. Если runner больше не отвечает, пользователь с правом запуска сборок может выбрать **Force status** в истории и принудительно завершить manual build как `completed`, `failed` или `cancelled`.

### Отслеживание внешнего EAS build

Если build запущен напрямую на expo.dev, зарегистрируйте его с `reporting_mode: "eas"` — приложение должно быть связано с Expo-проектом, а `expo_build_id` обязан присутствовать в последних 50 builds проекта:

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps/customer-app/builds \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "request_uuid":"7f3a2c1e-9b4d-4e6a-8c5f-1d2e3f4a5b6c",
    "profile_type":"production",
    "reporting_mode":"eas",
    "expo_build_id":"4b425690-00c4-4d74-9fdd-bc44a122e8c3"
  }'
```

После создания App Manager сам опрашивает EAS каждые ~10 секунд до терминального статуса: `started_at`/`finished_at` берутся из реальных таймстемпов EAS, журнал загружается из log-файлов EAS, артефакты скачиваются и становятся доступны через artifacts API. Клиенту достаточно poll-ить `GET /builds/{id}` либо подписаться на webhooks `build.started`/`build.completed`. `POST /builds/{id}/sync-eas` форсирует немедленную синхронизацию нетерминального build; ручной `PATCH` статуса для EAS builds возвращает `409`.

Для manual CI/CD pipeline App Manager создаёт build до dispatch и передаёт его UUID через выбранный string input провайдера (`workflow_dispatch` в GitHub Actions или `spec:inputs` в GitLab CI/CD). Runner должен экспортировать это значение как `APP_MANAGER_BUILD_ID` или явно передать в actions fastlane-плагина. Полный пример находится в [`libs/fastlane-plugin/README.md`](../../../libs/fastlane-plugin/README.md).
