# Development

Generic notes for working on the module. Machine-specific details (local shop URL, credentials, paths) belong
in an untracked `DEVELOPMENT.local.md` (ignored via `*.local.md`), never in this file.

## Local shop

Any PrestaShop 1.7.6+ / 8 / 9 install works. Symlink (or copy) this repository into `modules/billtoinvoices`
so the directory name equals the module name, then install it from Modules -> Module Manager. Point the module
at a BillTo sandbox team (Environment: Sandbox) with an API token holding `orders:read`, `orders:write`,
`invoices:read`, `invoices:write` and, for KSeF auto-send, `ksef:send`.

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

Bump `$this->version` in `billtoinvoices.php`, `config.xml` and `CHANGELOG.md`; the CI `build-zip` job on
`main` produces `billtoinvoices.zip` ready for upload through the back office.
