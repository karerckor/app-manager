# Expo Hello World

Минимальное Expo SDK 57 приложение. Оно не логинится в Expo, не создаёт проект на
expo.dev и не вызывает EAS. Локальный `prepare` получает конфигурацию и signing
credentials для Android и iOS из App Manager API.

## Запуск

Сначала запустите Platform и примените demo seed из корня монорепозитория:

```bash
docker compose up -d
cd apps/platform
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

В другом терминале подготовьте demo-приложение:

```bash
cd demos/expo-hello-world
npm install --ignore-scripts
npm run prepare
npm start
```

`--ignore-scripts` нужен только при первой установке зависимостей: `prepare` — это
также lifecycle-скрипт npm, а Platform в этот момент может быть ещё не запущен.
`npm start` использует offline-режим Expo CLI.

## Что создаёт prepare

- `app.json` — общая конфигурация, Android package/versionCode и iOS bundle ID из
  identifiers в Platform;
- `credentials.json` — локальное описание signing credentials обеих платформ;
- `credentials/android/production.keystore` — скачанный Android keystore;
- `credentials/ios/distribution.p12` — iOS distribution certificate;
- `credentials/ios/profile.mobileprovision` — iOS App Store provisioning profile.

Дополнительно prepare скачивает (если настроены в Platform):

- `assets/icon.png` и `assets/splash.png` — иконка и splash, прописываются в `app.json`;
- `google-services.json` / `GoogleService-Info.plist` — клиентский Firebase config,
  прописывается в `expo.android.googleServicesFile` / `expo.ios.googleServicesFile`;
- `credentials/android/google-play-service-account.json` — ключ Google Play для загрузки;
- `credentials/ios/appstore-api-key.p8` — App Store Connect API key для TestFlight;
- `fastlane/context.json` — машинный манифест для Fastlane (идентификаторы, версии,
  типы профилей, пути к store-ключам). Пароли остаются только в `credentials.json`.

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

`credentials.json` следует распространённому формату локальных Expo credentials,
но этот demo не устанавливает и не запускает EAS CLI. Credentials-файлы имеют
права `0600` и исключены из Git.

Demo seed генерирует криптографически корректный self-signed PKCS#12 certificate и
CMS `.mobileprovision`, чтобы API и `prepare` можно было проверить локально. Apple
не доверяет demo-сертификату: для реальной подписи и публикации замените обе iOS
credentials в Platform материалами из Apple Developer account.

## Сборка и деплой (Fastlane)

Требуется Ruby + Fastlane (`bundle install`), а также Xcode (iOS) и Android SDK.

```bash
bundle install
npm run prepare          # готовит app.json, credentials.json, context.json, native-проекты

bundle exec fastlane build platforms:"android"      # signed AAB
bundle exec fastlane build platforms:"ios"          # signed IPA
bundle exec fastlane build                          # обе платформы

# Деплой опционален: по умолчанию lane останавливается на подписанном артефакте.
bundle exec fastlane deploy platforms:"android" submit:true   # → Google Play (internal)
bundle exec fastlane deploy platforms:"ios" submit:true       # → TestFlight
```

`build ios` подписывает IPA вручную: lane создаёт временный keychain, импортирует
скачанный distribution-сертификат, устанавливает provisioning profile и собирает с
manual signing, затем удаляет временный keychain и профиль. Цепочку доверия (WWDR,
Apple root) берёт из системного keychain. CocoaPods поставляется через `Gemfile`,
поэтому `pod install` работает под `bundle exec`.

Демо-credentials самоподписанные — реальные сторы их отклонят. Деплой предназначен
для использования с настоящими store-ключами, загруженными в Platform.

## CI demo fastlane-плагина

Workflow `Demo Android · App Manager Fastlane` и `Demo iOS · App Manager Fastlane`
запускают lane `plugin_demo`. Lane использует локальную версию
`libs/fastlane-plugin`: настраивает App Manager client, получает приложение и его
identifiers, скачивает icon asset, затем печатает этапы demo-сборки в GitHub Actions
log. Параметр `simulate_failure` позволяет проверить неуспешное завершение.

GitHub-hosted runner не может обратиться к Platform на локальном
`http://localhost:8000`, поэтому без дополнительной настройки workflow поднимает
изолированный mock API внутри job. HTTP-запросы выполняет настоящий клиент плагина;
постоянные credentials или GitHub secrets для этого smoke-сценария не нужны.

Для проверки против живой Platform задайте repository secrets
`APP_MANAGER_DEMO_BASE_URL` и `APP_MANAGER_DEMO_API_KEY`. Team и app по умолчанию —
`demo-users-team` и `example-app`; их можно переопределить repository variables
`APP_MANAGER_DEMO_TEAM` и `APP_MANAGER_DEMO_APP`. Если URL опубликован через ngrok,
держите tunnel запущенным до завершения workflow и используйте отдельный
минимально привилегированный API key.
