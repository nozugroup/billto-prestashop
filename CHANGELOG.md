# Changelog

## [1.0.0] - 2026-09-16

First stable release, and the baseline every later version builds on.

### Added

- Orders mirrored to BillTo and invoiced on paid states; unpaid invoices for deferred payment modules,
  settled on shipped/delivered states.
- Credit slips turned into KOR corrections (by quantity or proportional).
- Buyer scenarios: PL, EU consumer with OSS mode, EU company, outside the EU - with VIES validation,
  gross amounts and cart-rule discounts distributed over lines.
- KSeF submission with status polling.
- Configuration page with a status panel, an order-page box with manual actions, customer PDF download,
  a token-protected cron endpoint and a job queue with retry backoff.
- Update notice on the configuration page: the module asks BillTo for the latest published release and
  links to the ZIP and the changelog. PrestaShop never lists modules installed outside Addons as
  updatable, so this page is the only place it can be shown.
- `upgrade/` scripts backed by an idempotent `Installer::syncSchema()`, because PrestaShop calls
  `install()` only on a fresh install.
- OAuth 2.0 as the only way to connect: "Connect to BillTo" yields a token scoped to a single company,
  with permissions the owner approves on a consent screen and can withdraw in BillTo without touching
  the shop. There is no pasted API token - that was a long-lived credential for the merchant's whole
  company, sitting in the shop's database, impossible to narrow and impossible to withdraw without
  breaking whatever else used it.
- Production, sandbox and a custom API address are separate BillTo instances, so changing the
  environment disconnects the shop instead of keeping credentials that answer 401 to every request.
- The module version comes from a single constant, and is reported in the status panel and the API
  `User-Agent`.
