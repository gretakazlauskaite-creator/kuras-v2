# PRD: kuras.pricer.lt — Fuel Price Comparison Platform (Lithuania)

## 1. Project Overview

A web platform for tracking and comparing fuel prices at Lithuanian gas stations. Lithuanian law requires gas stations to update prices **once per day before 10:00 AM** and report them to the Lithuanian Energy Agency (LEA). The LEA publishes aggregated data at [ena.lt](https://www.ena.lt/degalu-kainos-degalinese/).

**Goal:** Build a user-facing price comparison tool with map visualization, best-price rankings, price alerts, and monetization surfaces (station profiles, ads, sponsorships).

**Domain:** kuras.pricer.lt  
**Stack:** PHP 8.2, MySQL 8.0, JavaScript (Vanilla + Leaflet.js), DDEV for local development

---

## 2. Data Source

### Primary source
- **URL:** https://www.ena.lt/degalu-kainos-degalinese/
- **Excel file (direct link):** https://ltenergagen.sharepoint.com/:x:/s/intra/doc/IQDRHKinqYi1S4QH6WeTSxLUATgT_VjMdvpnTH3cNxePBFA?e=mZhpxb

The Excel file is updated daily (before 10:00 AM). Columns expected:
- Station name / brand
- Address
- City / municipality
- Fuel types and prices (€/L): `Pb95`, `Pb98`, `Diesel`, `LPG` — exact column names must be confirmed on first parse

### Data ingestion strategy
- A PHP CLI script (`bin/import.php`) runs via **cron job at 10:15 AM daily** (after stations update)
- Downloads the Excel file, parses it (using `phpoffice/phpspreadsheet`), and upserts records into MySQL
- Stations are geocoded on first insert (latitude/longitude) using [Nominatim/OpenStreetMap Geocoding API](https://nominatim.openstreetmap.org/) — free, no key required
- Geocoding results are cached permanently in the DB; only new stations trigger geocoding

---

## 3. System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        DDEV / Production                     │
│                                                             │
│  ┌──────────────┐   cron 10:15    ┌──────────────────────┐ │
│  │  LEA Excel   │ ─────────────►  │  bin/import.php      │ │
│  │  (external)  │                 │  (CLI importer)      │ │
│  └──────────────┘                 └──────────┬───────────┘ │
│                                              │ upsert       │
│  ┌──────────────────────────────────────────▼───────────┐  │
│  │                   MySQL 8.0 Database                  │  │
│  │  stations | prices | fuel_types | alerts | users ...  │  │
│  └──────────────────────────────┬────────────────────────┘  │
│                                 │                            │
│  ┌──────────────────────────────▼────────────────────────┐  │
│  │              PHP 8.2 Application (Apache/Nginx)        │  │
│  │                                                        │  │
│  │  public/          – web root (index.php router)        │  │
│  │  src/             – application classes                 │  │
│  │   ├── Controller/ – page & API controllers             │  │
│  │   ├── Service/    – business logic                     │  │
│  │   ├── Repository/ – DB queries (PDO)                   │  │
│  │   └── Model/      – data models                        │  │
│  │  bin/             – CLI scripts (import)               │  │
│  │  templates/       – HTML templates (PHP)               │  │
│  └──────────────────────────────┬────────────────────────┘  │
│                                 │ JSON API                   │
│  ┌──────────────────────────────▼────────────────────────┐  │
│  │            Frontend (HTML + CSS + Vanilla JS)          │  │
│  │                                                        │  │
│  │  assets/js/map.js     – Leaflet.js map                 │  │
│  │  assets/js/filters.js – filter bar                     │  │
│  │  assets/js/alerts.js  – price alert UI                 │  │
│  └────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### No framework — lightweight custom MVC
- Front controller: `public/index.php` reads `$_SERVER['REQUEST_URI']` and dispatches to controllers
- Controllers return JSON (for `/api/*`) or render PHP templates (for page routes)
- PDO with prepared statements for all DB access

---

## 4. Database Schema

```sql
-- Fuel types reference table
CREATE TABLE fuel_types (
    id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(10)  NOT NULL UNIQUE,  -- 'pb95', 'pb98', 'diesel', 'lpg'
    name VARCHAR(30)  NOT NULL          -- 'Pb 95', 'Pb 98', 'Dyzelinas', 'LPG'
);

-- Gas station brands
CREATE TABLE brands (
    id   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,   -- 'Circle K', 'Viada', 'Neste', ...
    logo VARCHAR(255)                   -- path to uploaded logo (for profiles)
);

-- Gas stations (one row per physical station)
CREATE TABLE stations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id      SMALLINT UNSIGNED NOT NULL REFERENCES brands(id),
    name          VARCHAR(120) NOT NULL,          -- full name from LEA
    address       VARCHAR(255) NOT NULL,
    city          VARCHAR(80)  NOT NULL,
    municipality  VARCHAR(80),                    -- rajonas
    lat           DECIMAL(9,6),
    lng           DECIMAL(9,6),
    geocoded_at   DATETIME,
    -- profile fields (monetization)
    has_coffee    TINYINT(1) DEFAULT 0,
    has_carwash   TINYINT(1) DEFAULT 0,
    has_shop      TINYINT(1) DEFAULT 0,
    has_loyalty   TINYINT(1) DEFAULT 0,
    profile_text  TEXT,
    promo_banner  VARCHAR(255),                   -- image path
    is_sponsored  TINYINT(1) DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_station (brand_id, address)
);

-- Daily prices (one row per station+fuel+date)
CREATE TABLE prices (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id    INT UNSIGNED NOT NULL REFERENCES stations(id),
    fuel_type_id  TINYINT UNSIGNED NOT NULL REFERENCES fuel_types(id),
    price         DECIMAL(5,3) NOT NULL,   -- EUR/L, e.g. 1.549
    price_date    DATE NOT NULL,
    imported_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_price_day (station_id, fuel_type_id, price_date),
    INDEX idx_date_fuel (price_date, fuel_type_id),
    INDEX idx_station   (station_id)
);

-- Price alerts
CREATE TABLE alerts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(180) NOT NULL,
    fuel_type_id  TINYINT UNSIGNED NOT NULL REFERENCES fuel_types(id),
    city          VARCHAR(80),              -- NULL = all Lithuania
    target_price  DECIMAL(5,3) NOT NULL,   -- alert when price <= this
    token         VARCHAR(64) NOT NULL UNIQUE,  -- for unsubscribe link
    is_active     TINYINT(1) DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_sent_at  DATETIME,
    INDEX idx_active_fuel (is_active, fuel_type_id)
);

-- Sessions for simple admin panel
CREATE TABLE admin_sessions (
    token      VARCHAR(64) PRIMARY KEY,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 5. File / Directory Structure

```
kuras.pricer.lt/
├── .ddev/
│   └── config.yaml             # DDEV config: php 8.2, mysql 8.0, name: kuras-pricer
├── public/                     # web root
│   ├── index.php               # front controller
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.css
│   │   └── js/
│   │       ├── map.js          # Leaflet map logic
│   │       ├── filters.js      # filter bar (city, fuel type, brand)
│   │       └── alerts.js       # price alert subscribe form
├── src/
│   ├── Bootstrap.php           # registers autoloader, DB, config
│   ├── Database.php            # PDO singleton
│   ├── Router.php              # simple URI → controller dispatcher
│   ├── Controller/
│   │   ├── HomeController.php
│   │   ├── MapController.php
│   │   ├── AlertController.php
│   │   └── Api/
│   │       ├── StationsApiController.php   # GET /api/stations
│   │       ├── PricesApiController.php     # GET /api/prices
│   │       └── AlertsApiController.php     # POST /api/alerts
│   ├── Service/
│   │   ├── ImportService.php    # orchestrates Excel parsing + DB upsert
│   │   ├── GeocodingService.php # Nominatim calls
│   │   ├── BestPriceService.php # ranking logic
│   │   └── AlertService.php     # check alerts, send emails
│   └── Repository/
│       ├── StationRepository.php
│       ├── PriceRepository.php
│       └── AlertRepository.php
├── templates/
│   ├── layout.php
│   ├── home.php                # best prices per city widget
│   ├── map.php                 # full-screen map page
│   └── station.php             # station profile page
├── bin/
│   └── import.php              # CLI: download + import + trigger alerts
├── migrations/
│   └── 001_init.sql            # full schema CREATE statements
├── composer.json               # phpoffice/phpspreadsheet, +mailer
└── .env.example                # DB_DSN, DB_USER, DB_PASS, MAIL_DSN
```

---

## 6. Feature Specifications

### 6.1 Homepage — Best Prices Widget

**Route:** `GET /`

**UI:**
- Header with fuel type tabs: `Pb95 | Pb98 | Diesel | LPG`
- For the selected fuel type, show a card per each of the **7 major cities**:
  `Vilnius, Kaunas, Klaipėda, Šiauliai, Panevėžys, Alytus, Marijampolė`
- Each city card shows:
  - Cheapest price today (€/L)
  - Station name + brand logo
  - "See all in city →" link → filter page
- Below: **"Best Station of the Day"** block for selected fuel type (see §6.4)

**Backend:**
```php
// PriceRepository::getBestPriceByCities(int $fuelTypeId, string $date): array
// Returns: [ city => ['price'=>1.399, 'station_name'=>'...', 'brand'=>'...', 'station_id'=>42], ... ]
SELECT s.city, MIN(p.price) AS min_price, s.id, s.name, b.name AS brand
FROM prices p
JOIN stations s ON s.id = p.station_id
JOIN brands b   ON b.id = s.brand_id
WHERE p.price_date = :date
  AND p.fuel_type_id = :fuel_type_id
  AND s.city IN ('Vilnius','Kaunas','Klaipėda','Šiauliai','Panevėžys','Alytus','Marijampolė')
GROUP BY s.city
ORDER BY s.city
```

---

### 6.2 Station List / Filter Page

**Route:** `GET /stations?city=&municipality=&brand=&fuel=&sort=price`

**UI:**
- Filter bar: City dropdown → District (municipality) dropdown (dynamic, populated via JS after city selected) → Brand multi-select → Fuel type radio → Sort by (price asc/desc, distance if geolocation granted)
- Results table / card list showing: station name, brand, address, price for selected fuel, Δ vs city average
- Pagination: 20 per page
- "Show on map" button → opens map centered on filtered results

**API endpoint:**
```
GET /api/stations?city=Vilnius&fuel=pb95&brand=circle-k&page=1
```
Response:
```json
{
  "data": [
    {
      "id": 42,
      "name": "Circle K Ukmergės g.",
      "brand": "Circle K",
      "address": "Ukmergės g. 369, Vilnius",
      "city": "Vilnius",
      "lat": 54.7231,
      "lng": 25.2841,
      "price": 1.399,
      "fuel": "pb95"
    }
  ],
  "meta": { "total": 87, "page": 1, "per_page": 20 }
}
```

---

### 6.3 Interactive Map

**Route:** `GET /map`

**Libraries:** [Leaflet.js](https://leafletjs.com/) (free, OSM tiles)

**UI:**
- Full-page map of Lithuania
- Fuel type selector + optional city/district focus
- Station markers:
  - Color-coded by price tier relative to the day's average for selected fuel:
    - 🟢 Green: ≤ avg − 2 ct
    - 🟡 Yellow: within ±2 ct of avg
    - 🔴 Red: ≥ avg + 2 ct
  - Sponsored stations get a star icon overlay
- Click on marker → popup with: station name, address, current price for selected fuel, "View profile" link
- "Best prices in view" sidebar: lists top-5 cheapest stations visible in current map bounds (updates on map move)

**JS data loading:**
```js
// On fuel type change or map move:
fetch(`/api/stations?fuel=${fuelSlug}&bounds=${map.getBounds().toBBoxString()}`)
  .then(r => r.json())
  .then(data => renderMarkers(data));
```

**API:**
```
GET /api/stations?fuel=pb95&bounds=23.5,53.8,26.5,56.5
```
Returns GeoJSON FeatureCollection for Leaflet.

---

### 6.4 Best Station Rankings

**Route:** rendered as a section on Homepage and as `GET /rankings`

**Periods:**
1. **Day** — cheapest station for selected fuel type today
2. **Week** — station that had the lowest price on the most days in last 7 days
3. **Month** — same logic over last 30 days

**Query — weekly champion:**
```sql
SELECT station_id, COUNT(*) AS days_cheapest
FROM (
    SELECT price_date, station_id,
           RANK() OVER (PARTITION BY price_date ORDER BY price ASC) AS rnk
    FROM prices
    WHERE fuel_type_id = :fuel AND price_date >= CURDATE() - INTERVAL 7 DAY
) ranked
WHERE rnk = 1
GROUP BY station_id
ORDER BY days_cheapest DESC
LIMIT 1
```

---

### 6.5 Price Alerts

**Flow:**
1. User fills form: email + fuel type + optional city + target price
2. `POST /api/alerts` stores alert with random `token` (hex 32)
3. Confirmation email sent with unsubscribe link: `GET /alerts/unsubscribe?token=XXX`
4. Daily, after import (`bin/import.php`), `AlertService::checkAndSend()`:
   - For each active alert, query today's best price matching criteria
   - If `best_price <= alert.target_price` → send email (throttle: max 1 per alert per day via `last_sent_at`)
5. `GET /alerts/unsubscribe?token=XXX` sets `is_active = 0`

**Email sending:** PHP `mail()` or simple SMTP via `symfony/mailer` (added to composer.json)

---

### 6.6 Station Profile Page (Monetization)

**Route:** `GET /station/{id}`

**UI:**
- Brand logo, station name, full address, map snippet (Leaflet mini-map)
- Today's prices for all fuel types (table)
- Price history chart last 30 days (Chart.js line chart, data via `/api/prices?station_id=X`)
- Station services: icons for coffee ☕, car wash 🚗, shop 🛒, loyalty card 💳
- Profile text (HTML, admin-editable)
- **Promo banner** (shown only if `is_sponsored = 1` and `promo_banner` set)
- "Best deal nearby" — 3 cheapest stations within ~5 km (uses Haversine in SQL)

---

### 6.7 Admin Panel (minimal)

**Route:** `GET /admin` (HTTP Basic Auth or session token from `.env`)

**Features:**
- Trigger manual import
- List stations; edit profile fields (services, profile_text, promo_banner)
- Manage sponsors / banners
- View alert subscribers count

No public registration — admin credentials stored in `.env` as `ADMIN_USER` / `ADMIN_PASS`.

---

## 7. Data Import Script (`bin/import.php`)

```
php bin/import.php [--date=YYYY-MM-DD] [--dry-run]
```

**Steps:**
1. Download Excel from LEA URL (via `file_get_contents` + context with User-Agent, or `curl`)
2. Parse with `PhpOffice\PhpSpreadsheet\IOFactory::load()`
3. For each row: normalize brand name, station address, city, fuel prices
4. **Upsert station**: `INSERT INTO stations ... ON DUPLICATE KEY UPDATE ...`
5. **Insert price**: `INSERT IGNORE INTO prices ...` (skip if date already imported)
6. **Geocode new stations**: call Nominatim for stations where `lat IS NULL`; respect rate limit (1 req/sec)
7. **Run alert checks**: instantiate `AlertService`, call `checkAndSend()`
8. Log summary to stdout: `Imported 312 prices, 2 new stations, 5 alerts sent`

---

## 8. DDEV Configuration

**`.ddev/config.yaml`:**
```yaml
name: kuras-pricer
type: php
docroot: public
php_version: "8.2"
webserver_type: nginx-fpm
database:
  type: mysql
  version: "8.0"
```

**Setup commands (after `ddev start`):**
```bash
ddev composer install
ddev mysql < migrations/001_init.sql
cp .env.example .env   # fill in credentials
ddev exec php bin/import.php  # first import
```

---

## 9. Routing Table

| Method | URI | Handler | Auth |
|--------|-----|---------|------|
| GET | `/` | `HomeController::index` | — |
| GET | `/stations` | `HomeController::stations` | — |
| GET | `/map` | `MapController::index` | — |
| GET | `/rankings` | `HomeController::rankings` | — |
| GET | `/station/{id}` | `HomeController::stationProfile` | — |
| GET | `/alerts/unsubscribe` | `AlertController::unsubscribe` | token |
| POST | `/api/alerts` | `AlertsApiController::create` | — |
| GET | `/api/stations` | `StationsApiController::index` | — |
| GET | `/api/prices` | `PricesApiController::index` | — |
| GET | `/admin` | `AdminController::dashboard` | admin |
| POST | `/admin/import` | `AdminController::triggerImport` | admin |
| POST | `/admin/station/{id}` | `AdminController::updateStation` | admin |

---

## 10. Monetization Implementation Notes

| Surface | Implementation |
|---------|---------------|
| **Station profiles** | `stations.profile_text` (HTML), `stations.promo_banner` image; editable in admin panel; shown on `/station/{id}` |
| **Sponsored badge** | `stations.is_sponsored = 1` → star icon on map, highlighted card in lists, appears first in city ranking tie-breaks |
| **Real estate ads** | Static `<div class="ad-slot" data-slot="realestate">` placeholders in templates; content injected via JS ad tag or admin-managed HTML snippet stored in a simple `ads` table |
| **Banner ads** | `<div class="ad-slot" data-slot="header">` in `layout.php` — supports Google AdSense tag or custom image/link from admin panel |
| **Project sponsor** | Dedicated slot in header/footer from admin panel |

**`ads` table (add to migrations):**
```sql
CREATE TABLE ads (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slot        VARCHAR(40) NOT NULL,    -- 'header', 'sidebar', 'realestate'
    html        TEXT NOT NULL,
    is_active   TINYINT(1) DEFAULT 1,
    starts_at   DATE,
    ends_at     DATE
);
```

---

## 11. Non-Functional Requirements

- **Performance:** Homepage must load in < 2s. Price queries use covering indexes on `(price_date, fuel_type_id)`. Map API limits markers to 500 per viewport request; use marker clustering (`Leaflet.markercluster`) for dense areas.
- **Caching:** PHP `apcu_store()` for best-price-per-city result, TTL = 1 hour. Invalidated after each import.
- **Responsive design:** CSS Grid, mobile-first. Map page: map takes full viewport height on mobile.
- **SEO:** Server-side rendered HTML for all public pages. Meta tags: title, description, OG tags in `layout.php`.
- **Security:** All DB queries use PDO prepared statements. Admin protected by session token. CSRF token on all POST forms. Email in alerts validated and sanitized.
- **Error handling:** Import script sends error email to `ADMIN_EMAIL` on failure. API returns `{"error": "..."}` with appropriate HTTP status codes.

---

## 12. Third-Party Dependencies

| Package | Purpose | Install |
|---------|---------|---------|
| `phpoffice/phpspreadsheet` | Parse `.xlsx` from LEA | `composer require phpoffice/phpspreadsheet` |
| `symfony/mailer` | SMTP email for alerts | `composer require symfony/mailer` |
| Leaflet.js 1.9 | Interactive map | CDN or `npm` |
| Chart.js 4 | Price history chart on station profile | CDN |
| Leaflet.markercluster | Marker clustering on map | CDN |

---

## 13. Implementation Order (suggested for AI agent)

1. **DDEV setup** — `.ddev/config.yaml`, `composer.json`, `composer install`
2. **Database** — `migrations/001_init.sql` with all tables + seed `fuel_types`
3. **Import script** — `bin/import.php` + `ImportService` + `GeocodingService` — get data into DB first
4. **Core backend** — `Router`, `Database`, `Bootstrap`, base controllers
5. **Homepage** — `PriceRepository::getBestPriceByCities`, `HomeController`, `templates/home.php`
6. **Station list + filter API** — `StationsApiController`, filter logic, pagination
7. **Map page** — Leaflet integration, GeoJSON API, color-coded markers
8. **Station profile page** — price history chart, services, promo banner
9. **Rankings** — weekly/monthly champion queries
10. **Price alerts** — form, `AlertService`, confirmation email, unsubscribe
11. **Admin panel** — import trigger, station editor, ads manager
12. **Monetization slots** — `ads` table, slot rendering in templates, sponsored markers

---

*Document version: 1.0 — prepared for AI agent implementation*
