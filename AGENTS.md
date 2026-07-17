# Kuras v2 repository guidance

## Product goal

Build and operate a Lithuanian fuel-price comparison platform whose routine data ingestion, validation, publishing, testing, deployment, and monitoring are automated. WordPress is the public presentation, editorial, and SEO layer. Fuel-price ingestion and business logic live outside WordPress behind a versioned API.

## Source-of-truth rules

- The primary price source is the Lithuanian Energy Agency (LEA): `https://www.ena.lt/degalu-kainos-degalinese/`.
- LEA currently publishes prices every working day after collecting the 10:00 prices. Do not describe LEA-derived data as real-time.
- Always display the source date and the last successful import time.
- Never label data with the scheduler date. Derive the price date from the source page or workbook and validate it.
- Keep the downloaded source file, URL, checksum, discovered date, import result, row counts, and validation report for every run.
- Publish a batch atomically only after validation succeeds. A failed or partial batch must not replace the last known-good public data.

## Architecture guardrails

- Keep WordPress themes and blocks presentation-only. They consume the public API and must not scrape LEA or own price tables.
- Keep ingestion, station identity, price history, geocoding, rankings, and alerts in the backend service.
- Hide third-party providers behind interfaces so data, map tiles, geocoding, mail, and monitoring providers can be replaced without rewriting product logic.
- Do not use the public Nominatim endpoint for recurring bulk geocoding. Use a compliant provider or a self-hosted service and cache results.
- Do not expose secrets, raw database credentials, admin defaults, or provider tokens in the repository.

## Quality and security

- Every parser change needs a sanitized workbook fixture and automated tests for column mapping, source date, duplicate handling, and invalid values.
- Every public API endpoint needs input validation, bounded pagination, consistent JSON errors, and rate limiting where abuse is possible.
- Admin mutations require strong authentication, authorization, CSRF protection, audit logs, and content/file validation.
- Treat price anomalies, station-count changes, missing fuel columns, stale source dates, and failed downloads as blocking validation failures or explicit warnings.
- Prefer small branches and draft pull requests. Each PR must state what changed, how it was checked, and any operational impact.
- Never deploy directly from an unreviewed AI change. CI must pass and production changes must remain reversible.

## Delivery workflow

1. Work on one roadmap milestone at a time.
2. Add or update tests before changing behavior.
3. Run formatting, static analysis, unit tests, integration tests, and the source-fixture import test.
4. Open a draft PR with a concise risk and rollback note.
5. Merge only after the milestone acceptance criteria are satisfied.

