-- ============================================================
-- 002_normalize_location.sql
-- Fix city and municipality fields corrupted by wrong column parsing.
--
-- The LEA "gyvenvietė, gatvė" column stores "Locality, Street".
-- ImportService was taking the LAST comma-part as city, giving streets
-- (e.g. "Savanorių pr. 12") instead of the city ("Vilnius").
-- Municipality included the seniūnija: "Vilniaus m. sav., Naujamiesčio sen."
-- ============================================================

-- 1. Trim municipality to just the "X sav." part (drop everything after first comma)
UPDATE stations
SET municipality = TRIM(SUBSTRING_INDEX(municipality, ',', 1))
WHERE municipality LIKE '%,%';

-- 2. Extract locality (first comma-part) as city for all stations whose
--    address contains a comma (i.e. "Locality, Street" format).
UPDATE stations
SET city = TRIM(SUBSTRING_INDEX(address, ',', 1))
WHERE address LIKE '%,%';

-- 3. For the remaining stations (address has no comma, city might still be
--    a raw street token from an earlier import), derive city from municipality
--    where the current city looks like a street address (contains a digit).
UPDATE stations
SET city = TRIM(REGEXP_REPLACE(municipality, '\\s*(r\\.\\s*sav\\.|m\\.\\s*sav\\.|sav\\.)\\s*$', ''))
WHERE address NOT LIKE '%,%'
  AND city REGEXP '[0-9]'
  AND municipality IS NOT NULL;
