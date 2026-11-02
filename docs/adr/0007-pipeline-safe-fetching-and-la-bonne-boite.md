# 0007. Pipeline orchestrator, safe fetching, and no guessed La Bonne Boîte adapter

- **Status:** accepted
- **Date:** 2026-10-31

## Context

`app:jobs:refresh` already discovered and deduplicated offers and companies (ADR 0005), but the
automatic mode stopped there: nothing ranked every profile, nothing prepared briefs for the top
companies, and nothing alerted an admin when a night went wrong. Enriching a company also means
fetching URLs typed by users and admins, which the project guidelines treat as the critical SSRF surface.

La Bonne Boîte v2 is listed in the project guidelines. Checked on 2026-10-31: the francetravail.io catalogue
and documentation pages are client-rendered and return no content without a browser session, the
data.gouv.fr page only confirms "2 appels / seconde" and that access needs a francetravail.io
account, and the public GitHub repository is archived and does not describe the v2 contract.

## Decision

- **`app:pipeline:run` / `RunPipelineMessage` (nightly via `App\Schedule`)** run five steps into a
  single `PipelineRun`: discover (`SourceRefresher`), dedupe (inside `Ingestor`), rank every
  active profile (`CompanyRanker`, fingerprint-gated), queue an `EnrichCompanyMessage` for each
  top-N company without a fresh (< 30 days) brief, report. A failing step makes the run
  "partial" and sends a Notifier email to `ADMIN_ALERT_ADDRESS`.
- **`SafeHttpFetcher`** is the only way to read a user- or admin-provided URL: http/https only,
  no credentials, literal private/reserved IPs refused upfront, resolved hosts checked by
  `NoPrivateNetworkHttpClient` (service `app.safe_http_client`), robots.txt honoured (cached a
  day, an hour when unreachable), per-host rate limiter, 3 redirects, 1 MB, 15 s.
- **`EnrichCompanyHandler`** (Phase 1) creates or reuses the pending `CompanyBrief`, records its
  source URLs and checks the career page through `CareerPageChecker`. Content generation is the
  Phase 2 seam `BriefGeneratorInterface`.
- **No La Bonne Boîte adapter yet.** Shipping one built on guessed endpoint, parameter and field
  names would fail silently in production. The guidelines' own fallback — SIRENE plus sector
  heuristics — is what `RechercheEntreprisesSource` already does, and it was verified live
  (350 companies on 2026-10-31).

## Consequences

- In the test environment `app.safe_http_client` is aliased to the mocked `http_client`, because
  the guard resolves hostnames itself (a DNS lookup) and tests must stay offline. Literal-IP and
  scheme refusals are unit-tested; DNS-time refusal is trusted to the Symfony component.
- Adding La Bonne Boîte takes one afternoon once a francetravail.io application exists and its
  documentation can be read: a new `CompanySourceInterface` reusing the France Travail token.
