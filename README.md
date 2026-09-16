# BillTo for PrestaShop (`billtoinvoices`)

PrestaShop module that issues VAT invoices in [BillTo](https://billto.pl) (Polish invoicing SaaS with KSeF)
for shop orders. PrestaShop 1.7.6+, 8.x and 9.x; PHP 7.2+ (the module has no runtime Composer dependencies).

User-facing strings are Polish (the module targets Polish merchants); code and docs are English.

## How it works

1. **Order placed** (`actionValidateOrder`) -> a BillTo order is created (`source=prestashop`, `external_id` = order id).
2. **Order paid** (`actionOrderStatusPostUpdate` into a state flagged "paid", or configured states) ->
   `POST /orders/{id}/mark-paid` issues the invoice. Deferred payment modules (cash on delivery, wire
   transfer by default) get an **unpaid** invoice; the payment is recorded when the order reaches a
   "shipped/delivered" (or configured) state.
3. **Invoice issued** -> PDF stored in `pdf/` (direct access blocked), shown in the order page box and on the
   customer's order detail page; optional automatic KSeF submission with status polling.
4. **Credit slip** (`actionOrderSlipAdd`) -> correcting order + KOR invoice: remaining quantities per line,
   or a proportional price reduction for amount-only slips.
5. **Order cancelled** before invoicing -> BillTo order cancelled. **Order edited** before invoicing -> re-synced.

Business rules (buyer scenarios PL/EU/non-EU, VAT rate mapping incl. `0 WDT` / `np I` / `0 EX` / `np II`,
OSS, gross/net amounts, cart-rule discounts as distributed negative lines, VIES, refund lines) come from
[billto/shop-integration-core](https://github.com/nozugroup/billto-shop-integration-core), vendored in
`lib/shop-integration-core/` and shared with the WooCommerce plugin.

## Sync modes

PrestaShop has no job scheduler. **Immediate** mode (default) calls the API inside the hook, wrapped so a
BillTo outage never breaks the checkout; transient failures are queued for retry. **Cron** mode queues
everything and the cron URL shown on the configuration page (token-protected) runs the queue; call it every
5 minutes. In both modes the cron also drives retries and KSeF status polling.

## Configuration

Modules -> BillTo -> Configure. Token (BillTo: Settings -> API tokens, scopes `orders:*`, `invoices:*`, plus
`ksef:send`), environment (production / sandbox), invoice mode (all orders / only with a VAT number in the
address), which address field holds the tax id (`vat_number` or `dni`), paid / settle states, deferred payment
modules, KSeF auto-send, refunds, amount mode, negative lines, VAT fallbacks, EU consumer (OSS) mode, EU/non-EU
switches, VIES, delivery mode, series ids, sync mode. The page starts with a status panel (connection, default
series, queued/failed jobs, cron URL).

## Development

```bash
composer install
composer check    # PHPCompatibility 7.2-, Pest
composer sync-core  # refresh lib/shop-integration-core from a sibling checkout of the core
```

Unit tests run without a shop against small PrestaShop stand-ins (`tests/Stubs`). Static analysis against
PrestaShop's classes is not set up yet (it needs a PrestaShop checkout); CI runs `php -l` on 7.2/7.4/8.1,
PHPCompatibility and the tests, and builds `billtoinvoices.zip` for installation through the back office.

Limitations of this first version: no e-mail attachment mode (delivery is BillTo e-mail or link only), goods vs
service is decided by the product's *virtual* flag (override `OrderAdapter::isService()`), the order page box
requires PrestaShop 1.7.7+ (`displayAdminOrderSide`).

## License

AFL-3.0 (PrestaShop Addons requirement). See [LICENSE](LICENSE).
