# Kuras Pricer — kuras.pricer.lt

Fuel price comparison platform for Lithuania. Aggregates daily data from the [Lithuanian Energy Agency](https://www.ena.lt/degalu-kainos-degalinese/).

## Current GitHub-first MVP

- **Static HTML/CSS/JavaScript** — filters, rankings, table and Leaflet map
- **Generated JSON** — the browser needs no PHP, MySQL or WordPress plugin
- **GitHub Actions** — downloads, validates and publishes LEA data on working days
- **GitHub Pages** — temporary review hosting before the production static host is selected

The source application is in `static/`. `bin/build-static.php` discovers the
official LEA workbook, reuses the tested parser, blocks suspicious batches and
produces `dist/`. A failed job does not replace the last successful deployment.

## Static preview

```bash
python3 -m http.server 8080 --directory static
# Open http://localhost:8080
```

The committed JSON is an explicitly labelled demo until the first successful
scheduled workflow replaces it with a validated official snapshot.

## Build the latest official static site

```bash
composer install
php bin/build-static.php --output=dist --previous-data=static/data/current.json
```

The previous PHP/MySQL API remains as reference code. The WordPress prototype
is preserved separately in draft PR #4; neither is required by this MVP.

## Import Script

```bash
# Discover the latest official LEA workbook and its real source date
ddev exec php bin/import.php

# Validate the latest source without changing the database
ddev exec php bin/import.php --dry-run

# Import an archived file. The date must be supplied explicitly.
ddev exec php bin/import.php \
  --file=/var/www/html/archive/lea-2026-07-17.xlsx \
  --source-date=2026-07-17 \
  --allow-backfill \
  --skip-alerts

# Geocoding remains opt-in
ddev exec php bin/import.php --geocode-new --skip-alerts
```

Each downloaded workbook is stored under `IMPORT_STORAGE_PATH` by source date
and SHA-256 checksum. Every attempt is recorded in `import_runs`. A batch is
published only after its date, required columns, station identities, prices,
duplicates, row counts, and change against the previous batch pass validation.
Publishing runs in one database transaction, so a rejected or failed import
leaves the last known-good public prices unchanged.

## Legacy server cron (not used by the static MVP)

```cron
15 10 * * * /usr/bin/php /var/www/html/bin/import.php >> /var/log/kuras-import.log 2>&1
```

## Legacy admin panel

Visit `/admin` — uses HTTP Basic Auth (credentials in `.env`: `ADMIN_USER` / `ADMIN_PASS`).

Features:
- Dashboard with stats
- Station list + profile editor (services, promo banner, sponsored flag)
- Ads/banners manager
- Manual import trigger

## Project Structure

```
public/         Web root (index.php front controller)
src/
  Controller/   Page + API controllers
  Service/      Business logic (import, geocoding, alerts)
  Repository/   PDO DB queries
templates/      PHP HTML templates
  admin/        Admin panel templates
bin/import.php  CLI import script
migrations/     SQL schema
```

## Data Source

Prices come from the [official LEA page](https://www.ena.lt/degalu-kainos-degalinese/).
LEA collects and publishes the 10:00 prices on working days; this source is
daily official data, not real-time data.

## Legacy API v1 and review screen

The versioned API starts at `/api/v1`. Its source-of-truth contract is documented
in [`docs/openapi.yaml`](docs/openapi.yaml). Every price response includes the
actual source date and last successful import time. Public collection endpoints
have bounded pagination, validation, short-lived caching, deterministic sorting,
and consistent JSON errors.

Open `/preview` only when reviewing the legacy server application. The current
public experience is generated from `static/` and does not depend on this API.
