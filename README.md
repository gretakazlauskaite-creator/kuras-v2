# Kuras Pricer — kuras.pricer.lt

Fuel price comparison platform for Lithuania. Aggregates daily data from the [Lithuanian Energy Agency](https://www.ena.lt/degalu-kainos-degalinese/).

## Stack

- **PHP 8.2** — custom MVC, no framework
- **MySQL 8.0** — price/station data
- **JavaScript** — Leaflet.js map, Chart.js history, Vanilla JS
- **DDEV** — local development

## Quick Start

```bash
# 1. Install DDEV (https://ddev.readthedocs.io/)
ddev start

# 2. Install dependencies
ddev composer install

# 3. Create DB schema and apply migrations in numeric order
ddev mysql < migrations/001_init.sql
ddev mysql < migrations/002_normalize_location.sql
ddev mysql < migrations/003_add_indexes.sql
ddev mysql < migrations/004_fix_city_names.sql
ddev mysql < migrations/005_trusted_imports.sql
ddev mysql < migrations/006_core_api.sql

# 4. Copy env config
cp .env.example .env
# Edit .env — DB credentials are pre-filled for DDEV

# 5. First data import
ddev exec php bin/import.php

# 6. Open in browser
ddev launch
```

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

## Cron (production)

```cron
15 10 * * * /usr/bin/php /var/www/html/bin/import.php >> /var/log/kuras-import.log 2>&1
```

## Admin Panel

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

## Public API v1 and review screen

The versioned API starts at `/api/v1`. Its source-of-truth contract is documented
in [`docs/openapi.yaml`](docs/openapi.yaml). Every price response includes the
actual source date and last successful import time. Public collection endpoints
have bounded pagination, validation, short-lived caching, deterministic sorting,
and consistent JSON errors.

Open `/preview` for the temporary product-review screen. It consumes only API v1
and is intentionally separate from the final WordPress/​Pricer.lt presentation.
