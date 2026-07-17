-- ============================================================
-- migrations/001_init.sql
-- Full schema for kuras.pricer.lt fuel price platform
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- Fuel types reference
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fuel_types (
    id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(10)  NOT NULL UNIQUE,
    name VARCHAR(30)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO fuel_types (slug, name) VALUES
    ('pb95',   'Pb 95'),
    ('pb98',   'Pb 98'),
    ('diesel', 'Dyzelinas'),
    ('lpg',    'LPG');

-- ------------------------------------------------------------
-- Gas station brands
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS brands (
    id   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,
    logo VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Gas stations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id      SMALLINT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    address       VARCHAR(255) NOT NULL,
    city          VARCHAR(80)  NOT NULL,
    municipality  VARCHAR(80)  DEFAULT NULL,
    lat           DECIMAL(9,6) DEFAULT NULL,
    lng           DECIMAL(9,6) DEFAULT NULL,
    geocoded_at   DATETIME     DEFAULT NULL,
    -- profile / monetization fields
    has_coffee    TINYINT(1) NOT NULL DEFAULT 0,
    has_carwash   TINYINT(1) NOT NULL DEFAULT 0,
    has_shop      TINYINT(1) NOT NULL DEFAULT 0,
    has_loyalty   TINYINT(1) NOT NULL DEFAULT 0,
    profile_text  TEXT         DEFAULT NULL,
    promo_banner  VARCHAR(255) DEFAULT NULL,
    is_sponsored  TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_station (brand_id, address),
    CONSTRAINT fk_station_brand FOREIGN KEY (brand_id) REFERENCES brands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Daily fuel prices
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS prices (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id    INT UNSIGNED NOT NULL,
    fuel_type_id  TINYINT UNSIGNED NOT NULL,
    price         DECIMAL(5,3) NOT NULL,
    price_date    DATE NOT NULL,
    imported_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_price_day (station_id, fuel_type_id, price_date),
    INDEX idx_date_fuel (price_date, fuel_type_id),
    INDEX idx_station   (station_id),
    CONSTRAINT fk_price_station   FOREIGN KEY (station_id)   REFERENCES stations(id),
    CONSTRAINT fk_price_fuel_type FOREIGN KEY (fuel_type_id) REFERENCES fuel_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Price alerts
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(180) NOT NULL,
    fuel_type_id  TINYINT UNSIGNED NOT NULL,
    city          VARCHAR(80)  DEFAULT NULL,
    target_price  DECIMAL(5,3) NOT NULL,
    token         VARCHAR(64)  NOT NULL UNIQUE,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at  DATETIME DEFAULT NULL,
    INDEX idx_active_fuel (is_active, fuel_type_id),
    CONSTRAINT fk_alert_fuel_type FOREIGN KEY (fuel_type_id) REFERENCES fuel_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Admin sessions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_sessions (
    token      VARCHAR(64) PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Advertisement slots
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ads (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slot      VARCHAR(40) NOT NULL,
    html      TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATE DEFAULT NULL,
    ends_at   DATE DEFAULT NULL,
    INDEX idx_slot_active (slot, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
