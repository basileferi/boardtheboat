# Ingestion

Fetches real tech internships and companies from free sources and writes them idempotently.

- **Entry points:** `app:jobs:refresh` (console), `RefreshSourcesMessage` (nightly schedule, admin button) → `SourceRefresher`.
- **Sources:** `Source/*Source.php`, tagged `app.job_source` / `app.company_source`. Each maps a payload to `Dto\OfferData` / `Dto\CompanyData` and never touches the database.
- **Invariants:** offers upsert on `(source, externalId)`; imports only merge into *global* companies (SIREN → domain → normalised name); descriptions are plain text (`PlainText`), never HTML; no named person is ever read from the registry.
- **Gotchas:** paginated feeds repeat items across pages (the refresher dedupes per run); France Travail answers 204 for "no result"; The Muse wants repeated `category=` keys, not `category[]`.
- Why these sources and not others: `docs/adr/0005-free-job-and-company-sources.md`.
