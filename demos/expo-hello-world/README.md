# Expo Hello World

Минимальное Expo SDK 57 приложение, демонстрирующее интеграцию с App Manager Platform. Приложение не логинится в Expo, не создаёт проект на expo.dev и не вызывает EAS. Локальный CLI `prepare` получает конфигурацию, signing credentials, metadata и произвольные файлы из Platform API.

## What you'll learn

| Демо | Что показывает |
|------|---------------|
| **Metadata → app** | App metadata (feature flags, remote config) хранится в Platform и попадает в приложение при сборке через `platform-metadata.json` |
| **Файлы проекта** | Через API можно скачивать не только credentials (keystore, сертификаты), но и произвольные файлы проекта (release notes, конфиги) |
| **CLI prepare** | Предсборочный скрипт за один вызов подготавливает app.json, credentials, assets, metadata и project files |

## Quick start

```bash
# 1. Start Platform with demo data
docker compose up -d
cd apps/platform
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000

# 2. In another terminal — prepare and run the demo app
cd demos/expo-hello-world
npm install --ignore-scripts
npm run demo          # = prepare + expo start
```

`--ignore-scripts` нужен только при первой установке: `prepare` — это также lifecycle-скрипт npm, а Platform в этот момент может быть ещё не запущен.

## How it works

```
┌──────────────────────────────────────────────────────────────┐
│                     App Manager Platform                      │
│                                                               │
│  Example App                                                  │
│  ├── metadata: { features, remote_config, ... }              │
│  ├── identifiers: Android (com.example.app), iOS             │
│  ├── credentials: keystore, certificate, provisioning, ...   │
│  ├── assets: icon.png, splash.png                            │
│  ├── files: release-notes.md, app-config.json                │
│  ├── builds: history + logs                                  │
│  └── webhooks: build.completed → https://example.com/...     │
└───────────────────────────┬──────────────────────────────────┘
                            │ GET /api/v1/{team}/apps/{app}
                            │ GET /api/v1/{team}/apps/{app}/identifiers
                            │ GET /api/v1/{team}/apps/{app}/credentials
                            │ GET /api/v1/{team}/apps/{app}/files
                            ▼
                   ┌─────────────────┐
                   │  npm run prepare │
                   └────────┬────────┘
                            │
        ┌───────────────────┼───────────────────┐
        ▼                   ▼                   ▼
   app.json          credentials.json    platform-manifest.json
   (Expo config)     (signing creds)     (summary + metadata)
        │                   │                   │
        │                   │                   ▼
        │                   │           platform-metadata.json
        │                   │           (feature flags, config)
        │                   │                   │
        │                   │                   ▼
        │                   │           platform-files/
        │                   │           (release-notes.md, ...)
        ▼                   ▼                   ▼
   ┌──────────────────────────────────────────────┐
   │              Expo React Native App            │
   │                                               │
   │  App.js imports platform-manifest.json        │
   │  and shows: identifiers, files, metadata      │
   └──────────────────────────────────────────────┘
```

## What prepare creates

| Файл | Назначение |
|------|-----------|
| `app.json` | Expo конфигурация: name, slug, version, Android package, iOS bundle ID |
| `credentials.json` | Signing credentials для Expo (keystore path/password, certificate path/password) |
| `credentials/android/production.keystore` | Android signing keystore |
| `credentials/ios/distribution.p12` | iOS distribution certificate |
| `credentials/ios/profile.mobileprovision` | iOS App Store provisioning profile |
| `platform-metadata.json` | App metadata из Platform (feature flags, remote config) |
| `platform-manifest.json` | Сводка: team, identifiers, все скачанные файлы, metadata |
| `platform-files/` | Произвольные файлы проекта из Platform |
| `assets/icon.png` | Иконка приложения (если настроена) |
| `assets/splash.png` | Splash screen (если настроен) |
| `google-services.json` | Firebase client config для Android |
| `GoogleService-Info.plist` | Firebase client config для iOS |
| `credentials/android/google-play-service-account.json` | Google Play upload key |
| `credentials/ios/appstore-api-key.p8` | App Store Connect API key |
| `fastlane/context.json` | Машинный манифест для Fastlane |

## Configuration

По умолчанию используются данные `DemoSeeder`:

```text
APP_MANAGER_BASE_URL=http://localhost:8000
APP_MANAGER_API_KEY=demo-api-key
APP_MANAGER_TEAM=demo-users-team
APP_MANAGER_APP=example-app
APP_MANAGER_ANDROID_IDENTIFIER=com.example.app
APP_MANAGER_ANDROID_PROFILE_TYPE=production
APP_MANAGER_IOS_IDENTIFIER=com.example.app
APP_MANAGER_IOS_PROFILE_TYPE=appstore
```

Любое значение можно переопределить переменной окружения. Если у приложения
несколько identifiers или credentials, доступны точные селекторы:

```text
APP_MANAGER_ANDROID_IDENTIFIER
APP_MANAGER_ANDROID_CREDENTIAL_ID
APP_MANAGER_IOS_IDENTIFIER
APP_MANAGER_IOS_CERTIFICATE_ID
APP_MANAGER_IOS_PROVISIONING_PROFILE_ID
```

## Platform API endpoints used

| Endpoint | Что получаем |
|----------|-------------|
| `GET /apps/{slug}` | App info + metadata + asset URLs |
| `GET /apps/{slug}/identifiers` | Android/iOS identifiers с версиями |
| `GET /apps/{slug}/credentials` | Список credentials с payload и profiles |
| `GET /apps/{slug}/credentials/{id}/file` | Скачивание файла credential |
| `GET /apps/{slug}/files` | Список произвольных файлов проекта |
| `GET /apps/{slug}/files/{name}/content` | Скачивание файла проекта |

## Build & deploy (Fastlane)

Требуется Ruby + Fastlane (`bundle install`), а также Xcode (iOS) и Android SDK.

```bash
bundle install
npm run prepare          # готовит app.json, credentials.json, context.json, native-проекты

bundle exec fastlane build platforms:"android"      # signed AAB
bundle exec fastlane build platforms:"ios"          # signed IPA
bundle exec fastlane build                          # обе платформы

bundle exec fastlane deploy platforms:"android" submit:true   # → Google Play (internal)
bundle exec fastlane deploy platforms:"ios" submit:true       # → TestFlight
```

`build ios` подписывает IPA вручную: lane создаёт временный keychain, импортирует
скачанный distribution-сертификат, устанавливает provisioning profile и собирает с
manual signing, затем удаляет временный keychain и профиль.

Демо-credentials самоподписанные — реальные сторы их отклонят. Деплой предназначен
для использования с настоящими store-ключами, загруженными в Platform.

## CI demo fastlane-плагина

Workflow `Demo Android · App Manager Fastlane` и `Demo iOS · App Manager Fastlane`
запускают lane `plugin_demo`. Lane использует локальную версию
`libs/fastlane-plugin`: настраивает App Manager client, получает приложение и его
identifiers, скачивает icon asset, затем печатает этапы demo-сборки в GitHub Actions
log.

GitHub-hosted runner не может обратиться к Platform на локальном
`http://localhost:8000`, поэтому workflow поднимает изолированный mock API внутри job.
Для проверки против живой Platform задайте repository secrets
`APP_MANAGER_DEMO_BASE_URL` и `APP_MANAGER_DEMO_API_KEY`.
