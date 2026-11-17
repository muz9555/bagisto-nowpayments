# Verification — 17 November 2026

Tested on PHP 8.5.12 against the local Bagisto 2.5.x-dev application. Standalone development dependencies resolved Laravel 13.42.0, Concord 1.18.1 and Pest 5.3.7.

- `composer install --prefer-dist --no-interaction`: passed in this standalone package checkout.
- `composer validate --strict`: passed.
- `composer lint`: passed.
- `composer test`: 7 unit tests passed, 12 assertions.
- `BAGISTO_PATH=/absolute/path/to/bagisto composer test:bagisto`: 35 unit/integration tests passed, 111 assertions.
- A separate application fixture removed both legacy provider and Concord registrations and loaded the external package through Laravel's discovery manifest. Contract/proxy resolution, webhook routes and exactly one five-minute reconciliation schedule passed.
- The package-only installer passed with an existing migration record, preserving the installed payment table.
- Fresh installation and repeated installation were checked against an isolated SQLite in-memory database: exactly one package migration ran and the payment tracking table was created.
- Checkout and settlement tests use HTTP fakes and database transactions. No live payment or real order was submitted.

The GitHub workflow runs standalone unit tests and style checks on PHP 8.4 and 8.5. These are the configured CI targets; PHP 8.4 has not been executed locally. Bagisto's full integration suite requires the host application's development dependencies and seeded test database.
