# Development

Generic notes for working on the module. Machine-specific details (local shop URL, credentials, paths) belong
in an untracked `DEVELOPMENT.local.md` (ignored via `*.local.md`), never in this file.

## Local shop

Any PrestaShop 1.7.6+ / 8 / 9 install works. Copy this repository into `modules/billtoinvoices` **without the
dev `vendor/`, `tests/` and tooling files** (the same exclusions as the CI ZIP job), then install it from
Modules -> Module Manager. Do not symlink the checkout as is: PrestaShop autoloads `modules/*/vendor/autoload.php`
when present, and the dev dependencies (Pest, Symfony 6/7 components) clash with the shop's Symfony 4.4 and
break the whole back office with a `ServiceLocator::get()` declaration error. A one-liner for a refresh on
Windows: `robocopy . <shop>\modules\billtoinvoices /MIR /XD vendor tests .git .github bin /XF composer.json composer.lock phpunit.xml`.

Point the module at a BillTo sandbox team (Environment: Sandbox) with an API token holding `orders:read`,
`orders:write`, `invoices:read`, `invoices:write` and, for KSeF auto-send, `ksef:send`.

Smoke path: place an order with a payment module that validates into a paid state (e.g. check/bank wire with a
paid state configured) -> the order page shows the BillTo box with the invoice number and PDF; add a credit
slip -> a KOR appears under "Korekty"; cancel an uninvoiced order -> the BillTo order is cancelled.

## Tooling

```bash
composer install
composer test        # Pest, PrestaShop stand-ins in tests/Stubs
composer compat      # PHPCompatibility, testVersion 7.2-
composer sync-core   # copy ../billto-shop-integration-core/src into lib/shop-integration-core
```

The module bundles no Composer dependencies at runtime; `vendor/` is dev-only and excluded from the ZIP built
by CI. Keep the code PHP 7.2 compatible: no typed properties, arrow functions, `match`, `str_*` helpers.

## Release

1. Bump `Support\Version::MODULE` (the module reads `$this->version` from it) and `config.xml`, update `CHANGELOG.md`.
2. Add `upgrade/upgrade-X.Y.Z.php` for the new version - copy the previous one and rename the function.
   The release workflow refuses to publish a version without it.
3. Push to `main` (CI builds the ZIP), then tag `vX.Y.Z` and push the tag: `.github/workflows/release.yml`
   rebuilds `billtoinvoices.zip` from the tag and publishes it as a release asset.

## Schema changes and upgrades - read before touching the tables

PrestaShop runs **only** the scripts in `upgrade/` when a module is updated; `install()` is never called
again. A column added to the install DDL alone gives a module that works on a fresh shop and throws an SQL
error on every updated one - which is exactly the state this module was in before `upgrade/` had any script
(both tables were created in `install()` only).

So: the DDL lives in `Install\Installer::syncSchema()`, it is idempotent (`CREATE TABLE IF NOT EXISTS` plus
adding columns missing from `SHOW COLUMNS`), and every release ships an `upgrade/upgrade-X.Y.Z.php` that
calls it. Adding a column means one edit in `syncSchema()` (both the `CREATE TABLE` body and the
`syncColumns()` map) - never a hand-written `ALTER` in an upgrade script.

## How installed shops learn about an update

The module is not on PrestaShop Addons, so the shop's update list will never mention it. `Support\UpdateChecker`
asks `https://billto.pl/api/v1/plugins/prestashop/latest` (BillTo relays the latest published GitHub release;
see `config/shop_plugins.php` and `App\Services\ShopPlugins\PluginReleaseService` in the BillTo repo) and the
module configuration page shows a notice with a download link. The answer is cached in Configuration for 12 h,
failures included. Installing is still a manual ZIP upload - PrestaShop gives a module no supported way to
replace its own files.
