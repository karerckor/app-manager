# Security policy

## Supported versions

App Manager is pre-1.0. Security fixes are applied to the current `main` branch and the most recent tagged release. Older images and commits are not supported.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private vulnerability reporting from the repository's **Security** tab and include:

- the affected component and version or commit;
- reproduction steps or a proof of concept;
- the expected impact;
- any suggested mitigation.

Please avoid accessing data you do not own, disrupting a running service, or publishing details before a fix is available. Maintainers will acknowledge a complete report as soon as practical and coordinate remediation and disclosure through the private report.

## Deployment responsibility

App Manager stores signing credentials and integration secrets. Operators are responsible for TLS, access control, backups, key management, least-privilege API keys, private storage, monitoring, and timely upgrades. Review the [production notes](README.md#production-notes) before using real credentials.
