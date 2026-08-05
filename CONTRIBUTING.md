# Contributing to App Manager

Thanks for helping improve App Manager. Bug reports, documentation fixes, focused feature proposals, and code contributions are welcome.

## Before you start

- Search existing issues and pull requests before opening a duplicate.
- Use an issue to discuss large changes before investing in an implementation.
- Never include real credentials, signing files, tokens, customer data, or production logs in an issue, test fixture, screenshot, or commit.
- Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).

## Development setup

The quickest route is the [local development guide](README.md#local-quick-start). Platform-specific conventions are documented in [apps/platform/CONTRIBUTING.md](apps/platform/CONTRIBUTING.md).

Create a branch from `main`, keep each change focused, and use a Conventional Commit-style message such as `fix(platform): reject an invalid build transition`.

## Validation

Run the checks relevant to your change before opening a pull request:

```bash
# Laravel, Svelte, and design checks
cd apps/platform
vendor/bin/pint --test
composer test
npm run verify

# Bash CLI
cd ../..
bash -n libs/cli/bin/appmctl
bash libs/cli/tests/test_cli.sh

# Fastlane plugin
cd libs/fastlane-plugin
bundle exec rspec

# Documentation site
cd ../../apps/docs
npm run build
```

Changes to `apps/platform/Dockerfile`, `deploy/docker`, or the container workflow should also pass:

```bash
docker build -f apps/platform/Dockerfile apps/platform
./deploy/docker/smoke-test.sh
```

## Pull requests

Describe what changed, why it changed, any migration or operator impact, and the checks you ran. Add or update tests for behavior changes and keep API, CLI, Fastlane, deployment, and user documentation aligned where applicable.

All contributions are accepted under the repository's [MIT License](LICENSE).
