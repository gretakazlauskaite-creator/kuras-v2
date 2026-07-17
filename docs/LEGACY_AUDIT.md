# Legacy prototype audit

## Summary

The supplied prototype is a useful product sketch, not a production-ready autonomous system. It already contains a custom PHP MVC application, MySQL schema, LEA download/import script, station and price repositories, Leaflet map, filters, rankings, price history, alerts, administration pages, localization, and deployment notes.

The prototype should be preserved as a reference and migrated behind tests. It should not be deployed unchanged.

## Reusable product work

- Core entities: fuel types, brands, stations, daily prices, alerts, ads.
- Public page concepts: city best prices, station list, map, rankings, station profile.
- API concepts: stations, viewport GeoJSON, price history, alerts.
- UX foundations: Leaflet clustering, fuel filters, mobile CSS, stale-data notice.
- Operational concepts: scheduled import, geocoding, cache invalidation, error email.
- Lithuanian, English, and Russian translation catalogues.

## Release blockers

### 1. Source dates can be falsified accidentally

`bin/import.php` labels the fetched workbook with the command date. On a weekend, holiday, delayed publication, or LEA outage, the latest older workbook could be stored as today's prices. The source date must come from LEA metadata or the workbook, and an already imported source must not be republished under another date.

### 2. Source discovery is brittle

The importer scrapes the last SharePoint link with a regular expression and falls back to one hard-coded sharing URL. It does not parse the page structurally, decode HTML entities explicitly, record the chosen source, or test redirects and changed page layouts.

### 3. Fuel-column handling is inconsistent

The Pb95 alias list also contains Pb98 aliases such as `pb98` and `98`, so a Pb98 header can overwrite the Pb95 mapping. Separately, `PriceRepository::getFuelTypes()` excludes Pb98, while the map still exposes a Pb98 button. The resulting import/UI combination can omit or mislabel Pb98 data.

### 4. Imports are not auditable or atomic

There is no import-run record, raw-source retention, checksum, schema version, validation report, expected row-count check, or publish transaction for the complete batch. Individual `INSERT IGNORE` calls can leave a run silently incomplete.

### 5. Station identity is fragile

Stations are unique by `(brand_id, address)`. Brand spelling or address formatting changes can create duplicates, while operator changes can split the same physical station. A stable source fingerprint, normalized identity, alias history, and merge/override mechanism are needed.

### 6. Geocoding is not production-safe

The recurring geocoder depends on public community endpoints. The public Nominatim service discourages recurring bulk use and applies stricter limits than the prototype's one-second delay. Provider choice, caching, retries, confidence scoring, and manual overrides need to be explicit.

### 7. Administration has security gaps

- Authentication falls back to `admin/admin` when environment variables are absent.
- Admin POST routes do not implement CSRF protection despite the PRD claiming it.
- Upload validation trusts the filename extension and does not verify content, size, or image decoding.
- Advertisement HTML is stored and rendered as trusted raw markup.
- Manual import uses `shell_exec` inside a web request with no job isolation or audit trail.
- Session hardening, login throttling, audit logs, and role permissions are absent.

### 8. Public write endpoints can be abused

Price-alert creation has no rate limit, bot protection, confirmation state, or duplicate suppression. Public APIs use unrestricted CORS and do not define caching or abuse controls.

### 9. SEO is only a placeholder

Basic title, description, and Open Graph tags exist, but canonical URLs, structured data, sitemap strategy, indexable city/fuel landing pages, hreflang rules, robots controls, and article integration are not implemented.

### 10. No automated evidence of correctness

The archive contains no unit tests, parser fixtures, integration tests, end-to-end tests, static analysis configuration, CI workflow, monitoring, backup verification, or rollback check.

## Decision

Use the prototype to preserve product intent and selected UI/business logic. Rebuild the ingestion boundary first, place all retained behavior under tests, and integrate WordPress only through a documented API.

