# Platform API v1

Base URL:

```text
http://localhost:8000/api/v1/{team_slug}
```

Все запросы требуют API key пользователя, который состоит в указанной команде. Ключ создаётся в dashboard и передаётся как Bearer token:

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

Если ключ отсутствует или недействителен, API отвечает `401`. Если пользователь не состоит в `{team_slug}`, API отвечает `403`. Приложения и вложенные сущности другой команды не раскрываются и отвечают `404`.

API keys имеют ограниченный срок действия и явные abilities:

| Ability | Доступ |
| --- | --- |
| `apps:read` | Приложения, identifiers, assets и каталог credential types |
| `apps:write` | Создание и изменение приложений, identifiers и assets |
| `secrets:read` | Build context, credential payloads и скачивание credential files |
| `secrets:write` | Создание, изменение и удаление credentials |
| `builds:read` | История сборок и immutable context snapshots |
| `builds:write` | Регистрация, запуск, logs, отмена и завершение CI builds |

Обычный build key использует `apps:read`, `secrets:read`, `builds:read` и
`builds:write`. Write abilities автоматически включают необходимые read
abilities. Старые wildcard keys с ability `*` продолжают работать, но новые CI
keys следует выдавать с минимальным набором прав и сроком действия.

## Mobile apps

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps` | Список приложений команды |
| `POST` | `/apps` | Создать приложение |
| `GET` | `/apps/{app_slug}` | Получить приложение |
| `PATCH` | `/apps/{app_slug}` | Изменить приложение |
| `DELETE` | `/apps/{app_slug}` | Удалить приложение |

Поля приложения: `name` (обязательно при создании), `slug` (опционально, генерируется из name), `description`.

Одна запись Mobile app представляет одно распространяемое приложение или
white-label бренд. Одна кодовая база может собирать несколько брендов, меняя
`{app_slug}` / `APP_MANAGER_APP`. Разные identifiers внутри приложения
предназначены для платформ и каналов одного бренда, например production и beta.

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

Поля: `platform` (`ios` или `android`), `identifier` (bundle ID / package name),
`name` и `version` (опционально). Положительный целочисленный `version_code`
доступен только для Android. Строковый `build_number` доступен только для iOS и
соответствует `CFBundleVersion` / Expo `ios.buildNumber`.

`is_default` отмечает identifier, который build-клиенты используют, когда
точный identifier не передан. Для каждой пары приложение/platform может быть не
больше одного default identifier. Первый созданный identifier платформы
становится default автоматически. Изменение default на другом identifier
атомарно снимает флаг с предыдущего.

API сортирует identifiers по platform, затем default-first и name. Порядок
ответа не является способом выбора default.

## Build context

`build-context` возвращает согласованный набор данных для одной платформы и
build profile:

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps/{app_slug}/build-context` | Получить app, target, profile, assets и совместимые credentials |

Обязательные query-параметры:

- `platform`: `android` или `ios`;
- `profile_type`: совместимый build profile платформы.

`identifier` опционален. Порядок выбора target:

1. точное совпадение с переданным `identifier`;
2. identifier с `is_default=true`;
3. единственный identifier выбранной платформы;
4. ошибка `422`, если выбор неоднозначен или identifiers отсутствуют.

```bash
curl 'http://localhost:8000/api/v1/acme/apps/customer-app/build-context?platform=android&profile_type=production' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json'
```

Сокращённый пример ответа:

```json
{
  "data": {
    "schema_version": 1,
    "app": {
      "id": "019f...",
      "slug": "customer-app",
      "name": "Customer App",
      "description": "Public mobile client"
    },
    "target": {
      "id": "019f...",
      "platform": "android",
      "identifier": "com.example.customer",
      "name": "Production",
      "version": "2.3.0",
      "version_code": 20300,
      "build_number": null,
      "is_default": true
    },
    "profile": {
      "value": "production",
      "platform": "android",
      "label": "Production",
      "artifact": ".aab",
      "description": "For a fully signed Android App Bundle submitted to Google Play.",
      "id": "019f...",
      "name": "Production"
    },
    "assets": {
      "icon": {
        "name": "icon.png",
        "mime_type": "image/png",
        "size": 4096,
        "url": "https://manager.example/api/v1/acme/apps/customer-app/assets/icon"
      },
      "splashscreen": null
    },
    "credentials": [
      {
        "id": "019f...",
        "type": "android_keystore",
        "name": "Production keystore",
        "payload": {
          "store_password": "***",
          "key_alias": "release",
          "key_password": "***"
        },
        "expires_at": null,
        "profiles": [
          {
            "id": "019f...",
            "type": "production",
            "name": "Production",
            "app_identifier": {
              "id": "019f...",
              "platform": "android",
              "identifier": "com.example.customer"
            }
          }
        ],
        "app_identifiers": [],
        "file": {
          "name": "production.jks",
          "mime_type": "application/octet-stream",
          "size": 4096,
          "url": "https://manager.example/api/v1/acme/apps/customer-app/credentials/019f.../file"
        }
      }
    ]
  }
}
```

`profile.id` и `profile.name` могут быть `null`, если совместимый profile ещё
не был создан credential attachment. Остальные поля profile всегда берутся из
`BuildProfileSchema`.

`credentials` содержит все совместимые candidates, а не неявно выбранный
credential каждого типа. Если candidates одного нужного типа несколько, клиент
должен потребовать точный credential id/name. Credentials другого identifier,
profile, app или team в ответ не попадают. Payload содержит реальные секреты;
к нему применяются те же authentication и team access правила, что к
`GET /credentials`.

## Build traces

Build trace фиксирует входные данные и результат конкретного CI run:

| Method | Endpoint | Назначение |
| --- | --- | --- |
| `GET` | `/apps/{app_slug}/builds` | История сборок приложения |
| `POST` | `/apps/{app_slug}/builds` | Зарегистрировать pending build и зафиксировать context snapshot |
| `GET` | `/apps/{app_slug}/builds/external/{external_id}` | Найти build по ID вызывающей системы |
| `GET` | `/apps/{app_slug}/builds/{id}` | Получить build trace |
| `POST` | `/apps/{app_slug}/builds/{id}/start` | Атомарно закрепить build за CI run |
| `PATCH` | `/apps/{app_slug}/builds/{id}` | Завершить build как `completed`, `failed` или `cancelled` |
| `GET` | `/apps/{app_slug}/builds/{id}/logs` | Читать sequenced build logs |
| `POST` | `/apps/{app_slug}/builds/{id}/logs` | Добавить batch build logs |

При создании обязательны `platform` и `profile_type`. Опционально передаются
`external_id`, `identifier` и `commit_sha`. `external_id` — стабильный ID
сборки в вызывающей системе; если он не передан, Platform сгенерирует UUID.
Повторная регистрация одного `external_id` идемпотентна в пределах team, а
попытка использовать его для другого приложения ответит `409`.

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps/customer-app/builds \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "platform":"android",
    "profile_type":"production",
    "external_id":"customer-build-42",
    "commit_sha":"8d87d71c06b18b09e221f0cb06807e47ec44cfa8"
  }'
```

При `POST` platform заново разрешает build context и сохраняет immutable
`context_snapshot`: app, target, profile, assets и credentials. Credential
payload и download URLs в snapshot не попадают; для файлов сохраняются только
metadata и SHA-256, чтобы позднее проверить точный набор входов сборки.

Новая запись получает status `pending` и ещё не имеет `started_at`. Runner
получает только `external_id`, читает immutable snapshot (включая version,
`version_code` / `build_number`) и атомарно запускает запись:

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps/customer-app/builds/BUILD_ID/start \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "ci_provider":"github",
    "ci_run_id":"12345",
    "ci_run_url":"https://github.com/acme/customer-app/actions/runs/12345"
  }'
```

`start` переводит `pending → running` и идемпотентен для того же CI run. Другой
runner не может забрать уже запущенную сборку. Если пользователь успел отменить
pending build, `start` вернёт запись со status `cancelled`, не меняя её на
`running`. Это закрывает race между проверкой status и фактическим запуском.

Завершение выполняется один раз:

```bash
curl -X PATCH http://localhost:8000/api/v1/acme/apps/customer-app/builds/BUILD_ID \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"status":"completed","artifact_url":"https://ci.example/artifacts/customer-app.aab"}'
```

Допустимые переходы:

```text
pending ──▶ running ──▶ completed
   │           ├──────▶ failed
   └───────────┴──────▶ cancelled
```

`completed` и `failed` допустимы только из `running`. Отменить можно `pending`
или `running`. Повтор того же terminal status идемпотентен; переход между
terminal statuses и замена `context_snapshot` запрещены.

Логи отправляются батчами до 100 записей. `sequence` уникален внутри build,
поэтому повторная доставка одного batch безопасна:

```bash
curl -X POST http://localhost:8000/api/v1/acme/apps/customer-app/builds/BUILD_ID/logs \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"entries":[
    {"sequence":1,"stream":"system","level":"info","message":"Runner started"},
    {"sequence":2,"stream":"stdout","level":"info","message":"Compiling release bundle"}
  ]}'
```

Для incremental чтения используется
`GET .../logs?after_sequence=2&limit=200`. Последние 20 builds, их logs и кнопка
Cancel отображаются во вкладке Builds карточки приложения.

## Credentials

`GET /credential-types` возвращает поддерживаемые типы, платформы, допустимые расширения, обязательные поля `payload`, способ привязки (`attachment`) и доступные build profiles (`profiles`).

Текущие credential types:

| Type | Platform | Attachment | Назначение |
| --- | --- | --- | --- |
| `ios_certificate` | iOS | profile | Signing certificate `.p12` / `.pfx` |
| `provisioning_profile` | iOS | profile | Provisioning profile `.mobileprovision` |
| `apns_key` | iOS | identifier | APNs authentication key `.p8` |
| `apple_api_key` | iOS | identifier | App Store Connect API key `.p8` |
| `android_keystore` | Android | profile | Signing keystore `.jks` / `.keystore` |
| `firebase_config` | iOS, Android | identifier | Firebase HTTP v1 service account |
| `google_play_service_account` | Android | identifier | Google Play service account |
| `google_services_json` | Android | identifier | Client `google-services.json` |
| `google_service_info_plist` | iOS | identifier | Client `GoogleService-Info.plist` |

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
