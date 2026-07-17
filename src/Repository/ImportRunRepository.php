<?php

declare(strict_types=1);

namespace App\Repository;

final class ImportRunRepository
{
    public function __construct(private readonly \PDO $db)
    {
    }

    public function wasPublished(string $checksum): bool
    {
        $statement = $this->db->prepare(
            "SELECT 1 FROM import_runs WHERE checksum_sha256 = :checksum AND status = 'published' LIMIT 1",
        );
        $statement->execute([':checksum' => $checksum]);
        return (bool) $statement->fetchColumn();
    }

    public function create(
        string $sourcePageUrl,
        string $sourceUrl,
        string $sourceDate,
        string $checksum,
        string $filePath,
        string $parserVersion,
        string $status = 'started',
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO import_runs (
                source_page_url, source_url, source_date, checksum_sha256,
                stored_file_path, parser_version, status
             ) VALUES (
                :source_page_url, :source_url, :source_date, :checksum,
                :stored_file_path, :parser_version, :status
             )',
        );
        $statement->execute([
            ':source_page_url' => $sourcePageUrl,
            ':source_url' => $sourceUrl,
            ':source_date' => $sourceDate,
            ':checksum' => $checksum,
            ':stored_file_path' => $filePath,
            ':parser_version' => $parserVersion,
            ':status' => $status,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function recordValidation(int $runId, ValidationPayload $payload): void
    {
        $statement = $this->db->prepare(
            'UPDATE import_runs
             SET status = :status,
                 raw_row_count = :raw_rows,
                 station_count = :stations,
                 price_count = :prices,
                 validation_report = :validation_report,
                 completed_at = IF(:is_valid = 1, NULL, UTC_TIMESTAMP())
             WHERE id = :id',
        );
        $statement->execute([
            ':status' => $payload->valid ? 'validated' : 'rejected',
            ':raw_rows' => $payload->rawRows,
            ':stations' => $payload->stations,
            ':prices' => $payload->prices,
            ':validation_report' => json_encode(
                $payload->report,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            ':is_valid' => $payload->valid ? 1 : 0,
            ':id' => $runId,
        ]);
    }

    public function markPublished(int $runId, int $newStationCount): void
    {
        $statement = $this->db->prepare(
            "UPDATE import_runs
             SET status = 'published', new_station_count = :new_stations,
                 published_at = UTC_TIMESTAMP(), completed_at = UTC_TIMESTAMP()
             WHERE id = :id",
        );
        $statement->execute([':new_stations' => $newStationCount, ':id' => $runId]);
    }

    public function markFailed(int $runId, string $message): void
    {
        $statement = $this->db->prepare(
            "UPDATE import_runs
             SET status = 'failed', error_message = :message, completed_at = UTC_TIMESTAMP()
             WHERE id = :id",
        );
        $statement->execute([
            ':message' => mb_substr($message, 0, 2000),
            ':id' => $runId,
        ]);
    }

    public function markDuplicate(int $runId): void
    {
        $statement = $this->db->prepare(
            "UPDATE import_runs SET status = 'duplicate', completed_at = UTC_TIMESTAMP() WHERE id = :id",
        );
        $statement->execute([':id' => $runId]);
    }

    /** @return array{source_date:?string,price_count:?int} */
    public function latestPublishedSummary(): array
    {
        $row = $this->db->query(
            "SELECT source_date, price_count
             FROM import_runs
             WHERE status = 'published'
             ORDER BY source_date DESC, id DESC
             LIMIT 1",
        )->fetch();

        return [
            'source_date' => $row !== false ? (string) $row['source_date'] : null,
            'price_count' => $row !== false ? (int) $row['price_count'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    public function latestPublished(): ?array
    {
        $row = $this->db->query(
            "SELECT id, source_page_url, source_url, source_date, checksum_sha256,
                    raw_row_count, station_count, price_count, published_at
             FROM import_runs
             WHERE status = 'published'
             ORDER BY source_date DESC, id DESC
             LIMIT 1",
        )->fetch();

        if ($row === false) {
            return null;
        }

        foreach (['id', 'raw_row_count', 'station_count', 'price_count'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        return $row;
    }
}
