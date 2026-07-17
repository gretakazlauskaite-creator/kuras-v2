# Kuras v2 architecture

## System shape

```text
LEA page and source workbook
        |
        v
scheduled ingestion worker
        |
        +--> immutable raw source + checksum
        |
        v
staging parser and validation report
        |
        v
atomic publish to core database
        |
        +--> versioned public API --> WordPress plugin/blocks --> kuras.pricer.lt
        |
        +--> monitoring, alerts, exports, analytics
```

## Components

### Backend service

Use a maintained PHP framework and supported PHP release so scheduling, queues, validation, database migrations, structured logging, rate limiting, and tests follow established conventions. The current custom MVC code is reference material, not the long-term framework.

Responsibilities:

- Discover and download LEA sources.
- Retain raw files and import metadata.
- Parse, normalize, validate, stage, and atomically publish prices.
- Resolve stable station identities and coordinate overrides.
- Serve station, price, ranking, history, map, and health APIs.
- Run alerts and operational jobs through a queue rather than web requests.

### Database

Use MySQL 8 with a separate application schema from WordPress. Important additions beyond the legacy schema:

- `source_files` for URL, source date, checksum, storage key, and retrieval metadata.
- `import_runs` for state, timing, parser version, counts, validation, and errors.
- staging tables for a complete candidate batch.
- stable station keys, aliases, source identities, and coordinate provenance.
- price uniqueness tied to source date and source record identity.
- audit events for manual overrides and administrative actions.

### WordPress integration

A dedicated `kuras-pricer` plugin owns presentation integration:

- Server-rendered blocks or shortcodes for best prices, rankings, and tables.
- Interactive map application backed by the public API.
- Indexable city, fuel, network, and station routes with canonical metadata.
- Pricer.lt design tokens, navigation, ad slots, analytics, and consent integration.
- Pricer news queries by an agreed category/tag, with cached article cards.

WordPress must keep showing the last known-good data if the API has a temporary failure, together with the source date.

### Maps and geocoding

- Use Leaflet for the initial web map.
- Configure the tile URL and attribution instead of hard-coding a community service.
- Use a replaceable geocoding provider or self-hosted service.
- Cache normalized-address results and store provider, confidence, timestamp, and manual overrides.
- Browser geolocation is opt-in and remains on the client; the API receives coordinates only for the requested nearby search.

### Operations

- Scheduled import attempts after LEA's expected publication time, with bounded retries.
- Health endpoints distinguish API health, last successful import, source freshness, and queue health.
- Alerts fire for missing publication, download failure, schema drift, anomalous counts/prices, queue failure, and backup failure.
- Deployment is automated through CI, uses environment-specific secrets, runs migrations safely, and supports rollback.
- Backups are encrypted, retained by policy, and periodically restore-tested.

## Import state machine

1. `discovered`: identify the official source URL and declared source date.
2. `downloaded`: save immutable bytes and compute a checksum.
3. `parsed`: parse with a versioned parser into staging records.
4. `validated`: enforce required columns, types, price ranges, counts, duplicates, and source-date rules.
5. `published`: replace the public current snapshot atomically and append history.
6. `failed`: retain evidence, keep last known-good data public, and alert with an actionable reason.

## Initial public API

- `GET /api/v1/meta` — source date, import time, freshness, available fuels.
- `GET /api/v1/stations` — city, municipality, network, fuel, price and distance filters; bounded sorting/pagination.
- `GET /api/v1/stations/{id}` — profile, current prices, services and provenance.
- `GET /api/v1/stations/{id}/history` — bounded price history.
- `GET /api/v1/map/stations` — viewport-bounded GeoJSON.
- `GET /api/v1/rankings` — cheapest stations by scope and fuel.
- `GET /api/v1/statistics` — national and regional aggregates.
- `POST /api/v1/alerts` — confirmed, rate-limited alert subscription.
- `GET /health/live` and `GET /health/ready` — runtime and dependency health.

## Automation boundary

Routine work can be automated: imports, validation, tests, dependency PRs, deploys, monitoring, backups, and incident diagnostics. Human approval remains necessary for legal/data-use decisions, production credentials, provider contracts, major product changes, visual acceptance, and risky production operations.

