# Changelog

## [0.1.0] - 2026-09-14

### Added

- First version on the shared core: orders mirrored to BillTo and invoiced on paid states, unpaid invoices
  for deferred payment modules settled on shipped/delivered states, credit slips as KOR corrections
  (quantities or proportional), buyer scenarios (PL / EU consumer with OSS mode / EU company / outside EU),
  VIES, gross amounts, cart-rule discounts distributed over lines, KSeF auto-send with polling.
- Configuration page with status panel, order page box with manual actions, customer PDF download,
  token-protected cron endpoint and job queue with retry backoff.
