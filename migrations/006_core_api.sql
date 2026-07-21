-- Stable station identity, source aliases and coordinate provenance for API v1.

ALTER TABLE stations
    ADD COLUMN public_id VARCHAR(24) DEFAULT NULL AFTER id,
    ADD COLUMN source_key CHAR(64) DEFAULT NULL AFTER public_id,
    ADD COLUMN normalized_address VARCHAR(255) DEFAULT NULL AFTER address,
    ADD COLUMN coordinate_source VARCHAR(40) DEFAULT NULL AFTER lng,
    ADD COLUMN coordinate_confidence DECIMAL(4,3) DEFAULT NULL AFTER coordinate_source,
    ADD COLUMN coordinates_updated_at DATETIME DEFAULT NULL AFTER coordinate_confidence;

UPDATE stations s
JOIN brands b ON b.id = s.brand_id
SET s.source_key = SHA2(CONCAT(
        'lea|',
        LOWER(TRIM(REGEXP_REPLACE(REPLACE(b.name, CHAR(160), ' '), '[[:space:]]+', ' '))),
        '|',
        LOWER(TRIM(REGEXP_REPLACE(REPLACE(s.address, CHAR(160), ' '), '[[:space:]]+', ' ')))
    ), 256),
    s.normalized_address = LOWER(TRIM(REGEXP_REPLACE(REPLACE(s.address, CHAR(160), ' '), '[[:space:]]+', ' ')))
WHERE s.source_key IS NULL;

UPDATE stations
SET public_id = CONCAT('st_', LEFT(source_key, 20))
WHERE public_id IS NULL;

ALTER TABLE stations
    MODIFY public_id VARCHAR(24) NOT NULL,
    MODIFY source_key CHAR(64) NOT NULL,
    ADD UNIQUE KEY uq_station_public_id (public_id),
    ADD UNIQUE KEY uq_station_source_key (source_key),
    ADD INDEX idx_station_coordinates (lat, lng);

CREATE TABLE station_aliases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id INT UNSIGNED NOT NULL,
    source_name VARCHAR(40) NOT NULL,
    alias_key CHAR(64) NOT NULL,
    source_brand VARCHAR(120) NOT NULL,
    source_address VARCHAR(255) NOT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_station_alias (source_name, alias_key),
    INDEX idx_station_alias_station (station_id),
    CONSTRAINT fk_station_alias_station FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO station_aliases (
    station_id, source_name, alias_key, source_brand, source_address
)
SELECT s.id, 'lea', s.source_key, b.name, s.address
FROM stations s
JOIN brands b ON b.id = s.brand_id;

CREATE TABLE station_coordinate_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    station_id INT UNSIGNED NOT NULL,
    previous_lat DECIMAL(9,6) DEFAULT NULL,
    previous_lng DECIMAL(9,6) DEFAULT NULL,
    new_lat DECIMAL(9,6) NOT NULL,
    new_lng DECIMAL(9,6) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    changed_by VARCHAR(120) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_coordinate_override_station (station_id, changed_at),
    CONSTRAINT fk_coordinate_override_station FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_prices_date_station_fuel
    ON prices (price_date, station_id, fuel_type_id, price);
