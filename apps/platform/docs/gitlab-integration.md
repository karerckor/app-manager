# GitLab CI/CD integration

Для общего GitLab интеграция использует OAuth Authorization Code flow. Пользователь также может подключить один или несколько собственных GitLab-инстансов через URL и Personal Access Token. App Manager хранит OAuth-токены и PAT в отдельной таблице `integration_oauth_credentials`; секреты шифруются Laravel cast-ом и не попадают в аудируемое поле `integrations.settings`.

## Настройка OAuth application

Создайте OAuth application в GitLab со scopes `api` и `openid` и callback URL:

```text
https://APP_MANAGER_HOST/integrations/gitlab/callback
```

Для локальной разработки допустим `http://localhost/integrations/gitlab/callback`, если `APP_URL=http://localhost`.

Настройте окружение:

```dotenv
INTEGRATIONS_ENABLED=true
GITLAB_INTEGRATION_ENABLED=true
GITLAB_SELF_HOSTED_ENABLED=true
GITLAB_OAUTH_CLIENT_ID=
GITLAB_OAUTH_CLIENT_SECRET=
GITLAB_API_URL=https://gitlab.com/api/v4
GITLAB_WEB_URL=https://gitlab.com
GITLAB_OAUTH_SCOPES=api,openid
GITLAB_CONNECTION_STATE_TTL=600
```

`GITLAB_API_URL` и `GITLAB_WEB_URL` задают общий OAuth-провайдер. Это может быть GitLab.com или один централизованно настроенный self-managed GitLab. После изменения конфигурации выполните `php artisan config:clear`.

Scope `openid` нужен для повторного подключения: GitLab обрабатывает
`prompt=login` только в OIDC-flow. Благодаря этому кнопка добавления ещё одного
аккаунта завершает текущую GitLab-сессию и показывает форму входа, не
переиспользуя молча уже подключённого пользователя.

## Self-hosted GitLab из интерфейса

На странице **Integrations → CI/CD** выберите **Self-hosted GitLab** и укажите:

- корневой URL инстанса, например `https://gitlab.company.example` (без `/api/v4`);
- Personal Access Token со scope `api`.

App Manager использует API по адресу `<instance URL>/api/v4`. Для GitLab, установленного в подпути, укажите полный корневой URL, например `https://example.com/gitlab`.

Такие подключения не требуют отдельной OAuth application в App Manager. Их можно использовать одновременно с общим GitLab и подключать несколько инстансов или аккаунтов. URL хранится в `integrations.settings`, PAT — отдельно и в зашифрованном виде. Опция управляется через `GITLAB_SELF_HOSTED_ENABLED` и требует `INTEGRATIONS_ENABLED=true`.

## Поддерживаемый lifecycle

- OAuth или self-hosted PAT connect, reconnect, sync и disconnect;
- discovery проектов, где у OAuth-пользователя есть как минимум роль Developer;
- discovery `.gitlab-ci.yml` или `ci_config_path`, branches, tags и pipeline-level `spec:inputs`;
- запуск pipeline через `POST /projects/:id/pipeline`;
- polling статуса, отмена pipeline и сбор trace всех jobs;
- автоматическое обновление и ротация OAuth tokens с блокировкой от параллельного refresh.

GitLab webhook пока не создаётся: automatic builds используют существующий polling lifecycle App Manager. Pipeline inputs через API рассчитаны на GitLab 18.1+; конфигурации без `spec:inputs` тоже поддерживаются.
