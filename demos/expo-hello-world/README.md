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
