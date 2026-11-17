# Changelog

## 1.0.0 — 2026-11-17

- Package the existing Bagisto NOWPayments integration for Composer and ZIP/path installation.
- Add Laravel discovery with automatic, idempotent Concord registration.
- Add an idempotent `nowpayments:install` command for package-only migrations.
- Include hosted crypto checkout, signed IPN verification, API-verified settlement, exact amount checks and scheduled reconciliation.
- Include 23 locale files, standalone unit tests, Bagisto integration tests, CI and installation documentation.
