# Changelog

## [0.2.0] - 2026-09-15

### Added

- Update notice on the configuration page: the module asks BillTo for the latest published release and
  links to the ZIP and the changelog. PrestaShop never lists modules installed outside Addons as
  updatable, so this page was the only place it could be shown.
- `upgrade/` scripts backed by an idempotent `Installer::syncSchema()`. Without them a module update ran
  no schema change at all, because PrestaShop calls `install()` only on a fresh install.
- Module version reported in the status panel and in the API `User-Agent`, from a single constant
  (`Support\Version::MODULE`) instead of a literal that could drift.

## [0.1.0] - 2026-09-14

### Added

- First version on the shared core: orders mirrored to BillTo and invoiced on paid states, unpaid invoices
  for deferred payment modules settled on shipped/delivered states, credit slips as KOR corrections
  (quantities or proportional), buyer scenarios (PL / EU consumer with OSS mode / EU company / outside EU),
  VIES, gross amounts, cart-rule discounts distributed over lines, KSeF auto-send with polling.
- Configuration page with status panel, order page box with manual actions, customer PDF download,
  token-protected cron endpoint and job queue with retry backoff.
