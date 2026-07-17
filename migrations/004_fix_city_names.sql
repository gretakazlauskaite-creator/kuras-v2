-- ============================================================
-- migrations/004_fix_city_names.sql
-- Set canonical nominative city names for stations in city municipalities.
--
-- Root cause: ImportService derived city by stripping "m. sav." from the
-- municipality string, producing genitive forms like "Vilniaus" instead of
-- the correct nominative "Vilnius". Stations with these wrong city values
-- were excluded from the homepage best-price query.
--
-- "m. sav." = miesto savivaldybė (city municipality) — fully urban, so
-- every station inside genuinely belongs to that city.
-- ============================================================

UPDATE stations SET city = 'Vilnius'     WHERE municipality = 'Vilniaus m. sav.';
UPDATE stations SET city = 'Kaunas'      WHERE municipality = 'Kauno m. sav.';
UPDATE stations SET city = 'Klaipėda'    WHERE municipality = 'Klaipėdos m. sav.';
UPDATE stations SET city = 'Šiauliai'    WHERE municipality = 'Šiaulių m. sav.';
UPDATE stations SET city = 'Panevėžys'   WHERE municipality = 'Panevėžio m. sav.';
UPDATE stations SET city = 'Alytus'      WHERE municipality = 'Alytaus m. sav.';
UPDATE stations SET city = 'Marijampolė' WHERE municipality = 'Marijampolės sav.';
