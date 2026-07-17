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

# 3. Create DB schema + seed fuel types
ddev mysql < migrations/001_init.sql

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
# Import today
ddev exec php bin/import.php

# Import for specific date (dry run)
ddev exec php bin/import.php --date=2024-01-15 --dry-run

# Skip geocoding / alerts
ddev exec php bin/import.php --skip-geocode --skip-alerts
```

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

Prices from [ena.lt](https://www.ena.lt/degalu-kainos-degalinese/) updated daily before 10:00 AM.
