# 0005. Free job and company sources

- **Status:** accepted
- **Date:** 2026-09-30

## Context

The demo seeder filled the app with invented companies and offers. The product needs real
developer / tech internships and real companies to write to, from free sources only, without
scraping job boards (project guidelines, "Data sources").

Checked live on 2026-09-30:

| Source | Key | What it gives | Terms |
|---|---|---|---|
| The Muse `api/public/jobs` | none (500 req/h) | `level=Internship`, categories Software Engineering, Data and Analytics, Computer and IT, Design and UX — ~900 software internships, mostly US/Asia, a few in France | link to the offer page |
| Himalayas `jobs/api/search` | none | `employment_type=Intern`, remote only, ~170 software internships | link back, no rebranding |
| Arbeitnow `api/job-board-api` | none | ~300 recent EU offers per page, no level filter; a handful of Praktikum / Werkstudent / Ausbildung | "do not abuse, link back" |
| France Travail Offres d'emploi v2 | free OAuth2 client (francetravail.io) | the only volume source of French *stages* and *alternances* | official API |
| API Recherche d'entreprises (api.gouv) | none (7 req/s) | real companies by NAF code, département and headcount — SIRENE data | Licence Ouverte |

Rejected: Remotive, Jobicy (no internship filter, almost none in feed), La Bonne Alternance
(now requires an API key), LinkedIn/Indeed/WTTJ (scraping forbidden by our own rules).

## Decision

Each source is a tagged service behind `JobSourceInterface` / `CompanySourceInterface` and maps
its payload to `OfferData` / `CompanyData`. `TechFilter` keeps only developer/tech titles and
internship/apprenticeship contracts, with explicit word lists. Descriptions are stored as a
3,000-character **plain-text** excerpt (`PlainText`), never as HTML. `SourceRefresher` runs the
enabled sources, upserts through `Ingestor` (offers on `(source, externalId)`, companies matched
against *global* rows only, by SIREN → domain → normalised name), expires offers a source stopped
listing once they are 45 days old, and records a `PipelineRun`.

France Travail enables itself only when `FRANCE_TRAVAIL_CLIENT_ID` and `_SECRET` are set.
The registry source requests SMEs with 10–249 employees in the software/IT NAF codes and never
reads the `dirigeants` field. Runs are triggered by `app:jobs:refresh`, by the nightly
`RefreshSourcesMessage` in `App\Schedule`, and by the admin "refresh" button (queued, never
inline). The demo rows are removed with `app:seed:demo --purge`.

## Consequences

- Without France Travail credentials the France view is thin (a few offers from The Muse and
  Arbeitnow); the French *companies* for spontaneous applications are plentiful regardless.
  Registering a free francetravail.io application is the single step that fixes it.
- Every source is attributed in the footer and on each offer ("via …"), as their terms ask.
- Keyless APIs change without notice: each adapter has a payload-shaped unit test, and a failing
  source only turns its run "partial".
- The word-list filter will misclassify some titles; extend `TechFilter` and its data provider
  together.
