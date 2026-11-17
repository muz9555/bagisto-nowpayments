# Bagisto NOWPayments

Accept cryptocurrency through NOWPayments hosted checkout in **Bagisto 2.5.x**. Customers choose a supported cryptocurrency and network on NOWPayments; the store keeps its existing fiat pricing and standard Bagisto checkout.

The gateway creates a pending order, verifies signed payment notifications against the NOWPayments API, and creates one invoice and transaction only after a fully covered `finished` payment. Browser return URLs never mark an order paid.

## Compatibility

| Component | Supported |
| --- | --- |
| Bagisto | 2.5.x; tested against 2.5.x-dev |
| PHP | 8.4 or 8.5 |
| Laravel | 13.x |
| Concord | 1.18.x |

This is a Bagisto extension, not a standalone payment application. Earlier Bagisto/Laravel releases are not claimed compatible. This community package is not affiliated with NOWPayments or Webkul.

## Install with Composer

Version `v1.0.0` is published on GitHub. Until the package is submitted to Packagist, add this GitHub repository to your **existing Bagisto application's** Composer configuration:

```bash
composer config repositories.nowpayments vcs https://github.com/muz9555/bagisto-nowpayments
composer require muz9555/bagisto-nowpayments:^1.0
php artisan nowpayments:install
```

In production, use `php artisan nowpayments:install --force`. The command runs only this package's migrations and clears stale application caches. It does not enable a payment method or overwrite credentials, orders or catalog data. Run it again safely after an update.

Laravel package discovery registers the service provider, Concord model, payment method, admin settings, routes, views, translations and console commands. No changes to `bootstrap/providers.php`, `config/concord.php`, checkout templates or CSRF exclusions are required. The webhook is a stateless route outside the `web` middleware group.

If package discovery has been disabled in the host application, register `Webkul\NowPayments\Providers\NowPaymentsServiceProvider::class` manually in its provider list. The provider still registers the Concord module itself.

### Install from a downloaded ZIP

Extract the package into `packages/Webkul/NowPayments` in your Bagisto application, then run:

```bash
composer config repositories.nowpayments path packages/Webkul/NowPayments
composer require muz9555/bagisto-nowpayments:@dev
php artisan nowpayments:install
```

Composer must register the package even for a ZIP installation. Copying files alone does not enable Laravel discovery.

### Upgrading the earlier local module

If you previously copied a `Webkul/NowPayments` module directly into Bagisto, first preserve its code and database. Remove its duplicate root Composer PSR-4 entries for `Webkul\NowPayments\` and `Webkul\NowPayments\Tests\`, the explicit `NowPaymentsServiceProvider` entry in `bootstrap/providers.php`, and the explicit `ModuleServiceProvider` entry in `config/concord.php`. Move the old source folder out of the autoload path before using VCS installation. Then install this Composer package and run `composer dump-autoload` followed by `php artisan nowpayments:install`.

Keep the `nowpayments_payment_attempts` table and its migration record. This package uses the same table, method code and settings keys, so existing payment references and credentials remain available. Do not run `migrate:fresh` or rollback payment tracking data during an upgrade.

## Configure the gateway

1. Open **Admin → Configure → Sales → Payment Methods → NOWPayments** and select the appropriate channel.
2. Start in **Sandbox mode**. Enter the sandbox API key and sandbox IPN secret from your NOWPayments sandbox account. The gateway stays unavailable until it is enabled and both credentials are configured.
3. Set the displayed title, description, optional image, sort order and unpaid-order stock reservation period (default: 24 hours).
4. Use a correct public HTTPS `APP_URL` for hosted testing or production. NOWPayments must be able to reach the callback URL generated for each checkout. A localhost address cannot receive external callbacks.
5. After sandbox validation, turn sandbox mode off and enter the separate live API key and live IPN secret. Complete any merchant/account setup required by NOWPayments.

The package sends a per-attempt callback URL similar to:

```text
https://your-store.example/nowpayments/webhook/PAYMENT-REFERENCE
```

A base `/nowpayments/webhook` route is also supported; it resolves the attempt from `order_id`. The payload must include the correct order reference, invoice ID and numeric payment ID, with an `x-nowpayments-sig` signature computed using the secret for that attempt's original channel and environment.

Credentials are managed through Bagisto's existing configuration system and are never committed to this repository. Configure the production queue worker if your Bagisto installation uses an asynchronous queue.

## Run scheduled reconciliation

The provider schedules `nowpayments:reconcile` every five minutes. Keep Laravel's scheduler running, for example:

```cron
* * * * * cd /absolute/path/to/bagisto && php artisan schedule:run >> /dev/null 2>&1
```

Manual reconciliation:

```bash
php artisan nowpayments:reconcile
php artisan nowpayments:reconcile PAYMENT-REFERENCE --payment-id=123456789
```

The periodic task retries known payment IDs and expires overdue reservations. If the initial notification was never received and no payment ID is recorded, use the manual form with the ID from the NOWPayments dashboard. API failures return a retry response instead of marking the payment successful.

## Payment behavior

| State | Result |
| --- | --- |
| Waiting, confirming or sending | Order remains pending payment; no paid invoice is issued |
| Partially paid | No automatic paid invoice; customer is directed to contact the store |
| Finished and fully covered | One Bagisto invoice and payment transaction are created |
| Finished but underpaid, order cancelled or otherwise unsafe to invoice | Held for manual review |
| Wrong invoice, reference, currency or fiat quote | Rejected; no automatic settlement |
| Failed or expired | Pending order is cancelled and its stock reservation is released |
| Reservation deadline reached | Pending order is cancelled; a later completed payment requires manual review |

Payment IDs are bound to a single invoice and attempts are locked during settlement. Duplicate notifications do not create duplicate invoices. Amount comparisons avoid rounding an underpayment up. The package validates provider HTTPS checkout hosts and never follows arbitrary checkout redirects.

Refunds, payment top-ups, exchange-rate guarantees and automated recovery of unknown payment IDs are **not implemented**. Handle these cases through the merchant's existing operational process and NOWPayments dashboard. The package does not collect wallet private keys.

## Development and verification

Standalone signature and amount tests:

```bash
composer install
composer test
composer lint
```

Full checkout, settlement and package-registration tests require an installed, migrated Bagisto 2.5 checkout with its development dependencies and a seeded **test database**:

```bash
BAGISTO_PATH=/absolute/path/to/bagisto composer test:bagisto
```

The Bagisto suite uses transactions and HTTP fakes; it sends no real crypto payment. Point the host's database settings at your test database before running it. The tests depend on Bagisto's test helpers, not just its production dependencies. See [verification notes](VERIFICATION.md) for the checked environment.

## Updating and removal

```bash
composer update muz9555/bagisto-nowpayments
php artisan nowpayments:install
```

Disable the payment method before removing the package. Retain payment tracking data, order invoices and transaction records for reconciliation. Removing this package does not execute migration rollbacks automatically.

## License

[MIT](LICENSE). Copyright 2026 Sohail Muzammil.
