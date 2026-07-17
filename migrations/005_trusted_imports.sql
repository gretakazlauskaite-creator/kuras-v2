-- Trusted LEA import ledger and price provenance.

CREATE TABLE import_runs (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_page_url      VARCHAR(500) NOT NULL,
    source_url           VARCHAR(1000) NOT NULL,
    source_date          DATE NOT NULL,
    checksum_sha256      CHAR(64) NOT NULL,
    stored_file_path     VARCHAR(500) NOT NULL,
    parser_version       VARCHAR(40) NOT NULL,
    status               ENUM('started','validated','published','rejected','failed','duplicate') NOT NULL,
    raw_row_count        INT UNSIGNED DEFAULT NULL,
    station_count        INT UNSIGNED DEFAULT NULL,
    price_count          INT UNSIGNED DEFAULT NULL,
    new_station_count    INT UNSIGNED DEFAULT NULL,
    validation_report    JSON DEFAULT NULL,
    error_message        TEXT DEFAULT NULL,
    started_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at         DATETIME DEFAULT NULL,
    published_at         DATETIME DEFAULT NULL,
    INDEX idx_import_source_date (source_date),
    INDEX idx_import_checksum_status (checksum_sha256, status),
    INDEX idx_import_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE prices
    ADD COLUMN import_run_id BIGINT UNSIGNED DEFAULT NULL AFTER imported_at,
    ADD INDEX idx_price_import_run (import_run_id),
    ADD CONSTRAINT fk_price_import_run
        FOREIGN KEY (import_run_id) REFERENCES import_runs(id);
