# LEA import operations

## Safety model

The importer discovers the newest workbook from the official LEA page and reads
the date printed in the link label. The scheduler date is never used as the
price date. There is no hard-coded SharePoint fallback.

Before any public data changes, the importer:

1. downloads and verifies the XLSX container;
2. stores an immutable copy under `IMPORT_STORAGE_PATH/<source-date>/<sha256>.xlsx`;
3. records the source URL, source date, checksum, parser version, counts, and
   validation report in `import_runs`;
4. validates required columns, price ranges, duplicate station/fuel pairs,
   source freshness, minimum counts, and the change against the previous batch;
5. replaces that source date inside one MySQL transaction.

If parsing, validation, or publishing fails, the last known-good public prices
remain unchanged. Re-running the same checksum creates a `duplicate` ledger row
and makes no price changes. A process lock prevents overlapping cron runs.

The current official workbook is a long table: `Įmonė`, `Savivaldybė`,
`Adresas`, `Degalų tipas`, `Kaina (EUR/l)`, `Pateikimo data`. Each station/fuel
pair is a separate row. The parser aggregates those rows into one station and
cross-checks the workbook's `Pateikimo data` against the date published on the
LEA page. As of 2026-07-17 the source contains `Dyzelinas`, `95 benzinas`, and
`SND`; Pb98 remains supported by the parser but is not required or exposed as
available source data.

## Production setup

Apply `migrations/005_trusted_imports.sql` before enabling the new command. Set
`IMPORT_STORAGE_PATH` to persistent storage that survives deployments and is
included in backup policy. The PHP user needs read/write access to that folder.

Recommended daily schedule (Europe/Vilnius):

```cron
20 10 * * * cd /var/www/kuras && /usr/bin/php bin/import.php >> /var/log/kuras-import.log 2>&1
```

Running daily is intentional. On weekends and holidays LEA normally keeps the
latest working-day file, which is detected as an already published checksum and
does not create duplicate prices.

## Manual checks

Validate the latest official source without a database write:

```bash
php bin/import.php --dry-run --skip-alerts
```

Import a historical workbook only with an explicit source date:

```bash
php bin/import.php \
  --file=/secure/archive/lea-2026-07-17.xlsx \
  --source-date=2026-07-17 \
  --allow-backfill \
  --skip-alerts
```

`--allow-backfill` disables only current-source freshness and chronological
checks. Column, identity, duplicate, and price-range checks still apply.

## Statuses and recovery

- `published`: validation passed and the transaction committed.
- `duplicate`: this exact checksum was already published; no action required.
- `rejected`: source content failed validation; inspect `validation_report`.
- `failed`: parsing, database, or runtime failure; inspect `error_message` and
  application logs.
- `started` or `validated` for an unusually long time: the process was
  interrupted. Investigate the host, then rerun; checksum idempotency makes the
  retry safe.

Never change a rejected row manually in the raw source archive. Correct the
parser or validation rule in a reviewed PR, keep the fixture that reproduces the
case, and rerun the unchanged source file.
