-- ============================================================
-- migrations/003_add_indexes.sql
-- Performance indexes for homepage queries
-- ============================================================

-- Covering index: fuel_type_id first (week/month queries filter by fuel, then date range)
-- Includes price for MIN() lookups and station_id for joins — no row back-lookup needed.
CREATE INDEX idx_prices_fuel_date_price
    ON prices (fuel_type_id, price_date, price, station_id);

-- City index for getBestPriceByCities CTE join and stations filter
CREATE INDEX idx_stations_city
    ON stations (city);

-- Municipality index for available_brands and station filters
CREATE INDEX idx_stations_municipality
    ON stations (municipality);
