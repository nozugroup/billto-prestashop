# Changelog

## [Unreleased]

### Removed

- The pasted API token. The shop connects through OAuth only: "Connect to BillTo" yields a token scoped to one
  company, with permissions the owner approves on the consent screen and can withdraw in BillTo without touching
  the shop. A pasted token was a long-lived credential for the merchant's whole company, sitting in the shop's
  database, impossible to narrow and impossible to withdraw without deleting a token something else may use.

### Changed

- Production, sandbox and a custom API address are separate BillTo instances, so changing the environment now
  disconnects the shop instead of keeping credentials that answer 401 to every request.
- The status panel reports the credentials actually in use and the API address it talks to.

## [0.1.0] - 2026-09-14

### Added

- First version on the shared core: orders mirrored to BillTo and invoiced on paid states, unpaid invoices
  for deferred payment modules settled on shipped/delivered states, credit slips as KOR corrections
  (quantities or proportional), buyer scenarios (PL / EU consumer with OSS mode / EU company / outside EU),
  VIES, gross amounts, cart-rule discounts distributed over lines, KSeF auto-send with polling.
- Configuration page with status panel, order page box with manual actions, customer PDF download,
  token-protected cron endpoint and job queue with retry backoff.
