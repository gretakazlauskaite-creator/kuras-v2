<?php

declare(strict_types=1);

final class Kuras_Pricer_Query
{
    private const FUELS = ['pb95', 'pb98', 'diesel', 'lpg'];
    private const SORTS = ['price_asc', 'price_desc', 'name_asc', 'distance_asc'];

    /** @param array<string,mixed> $input @return array<string,string|int|float> */
    public static function sanitize(string $endpoint, array $input): array
    {
        $allowed = match ($endpoint) {
            'stations' => ['fuel', 'date', 'city', 'municipality', 'brand', 'q', 'min_price', 'max_price', 'lat', 'lng', 'sort', 'page', 'per_page'],
            'rankings' => ['fuel', 'date', 'city', 'municipality', 'brand', 'limit'],
            'statistics' => ['fuel', 'date'],
            'map/stations' => ['fuel', 'date', 'city', 'municipality', 'brand', 'bounds', 'limit'],
            'nearby' => ['fuel', 'date', 'lat', 'lng', 'radius_km', 'limit'],
            'history' => ['fuel', 'days', 'to'],
            'meta', 'filters', 'station' => [],
            default => throw new InvalidArgumentException('Neleistinas API kelias.'),
        };

        $query = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
                continue;
            }
            if (is_array($input[$key]) || is_object($input[$key])) {
                throw new InvalidArgumentException("Parametras „{$key}“ turi būti viena reikšmė.");
            }
            $query[$key] = self::value($key, (string) $input[$key]);
        }

        if (isset($query['min_price'], $query['max_price']) && $query['min_price'] > $query['max_price']) {
            throw new InvalidArgumentException('Mažiausia kaina negali būti didesnė už didžiausią.');
        }
        if ((isset($query['lat']) xor isset($query['lng'])) && $endpoint !== 'map/stations') {
            throw new InvalidArgumentException('Vietai reikia pateikti ir platumą, ir ilgumą.');
        }
        if (isset($query['limit'])) {
            $maximum = match ($endpoint) {
                'nearby' => 50,
                'rankings' => 100,
                default => 1000,
            };
            if ($query['limit'] > $maximum) {
                throw new InvalidArgumentException('Parametras „limit“ nepatenka į leistinas ribas.');
            }
        }

        return $query;
    }

    /** @return string|int|float */
    private static function value(string $key, string $raw): string|int|float
    {
        $value = trim(strip_tags($raw));
        return match ($key) {
            'fuel' => self::enum($key, $value, self::FUELS),
            'sort' => self::enum($key, $value, self::SORTS),
            'date', 'to' => self::date($key, $value),
            'page' => self::integer($key, $value, 1, 10000),
            'per_page' => self::integer($key, $value, 1, 100),
            'limit' => self::integer($key, $value, 1, 1000),
            'days' => self::integer($key, $value, 2, 366),
            'lat' => self::number($key, $value, -90, 90),
            'lng' => self::number($key, $value, -180, 180),
            'min_price', 'max_price' => self::number($key, $value, 0.1, 10),
            'radius_km' => self::number($key, $value, 0.5, 100),
            'bounds' => self::bounds($value),
            default => mb_substr($value, 0, 120),
        };
    }

    /** @param list<string> $allowed */
    private static function enum(string $key, string $value, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Neleistina parametro „{$key}“ reikšmė.");
        }
        return $value;
    }

    private static function date(string $key, string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Parametras „{$key}“ turi būti YYYY-MM-DD formato.");
        }
        return $value;
    }

    private static function integer(string $key, string $value, int $minimum, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("Parametras „{$key}“ turi būti sveikasis skaičius.");
        }
        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException("Parametras „{$key}“ nepatenka į leistinas ribas.");
        }
        return $number;
    }

    private static function number(string $key, string $value, float $minimum, float $maximum): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Parametras „{$key}“ turi būti skaičius.");
        }
        $number = (float) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException("Parametras „{$key}“ nepatenka į leistinas ribas.");
        }
        return $number;
    }

    private static function bounds(string $value): string
    {
        $parts = explode(',', $value);
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            throw new InvalidArgumentException('Žemėlapio ribos turi būti minLng,minLat,maxLng,maxLat formato.');
        }
        [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', $parts);
        if ($minLng < -180 || $maxLng > 180 || $minLat < -90 || $maxLat > 90 || $minLng >= $maxLng || $minLat >= $maxLat) {
            throw new InvalidArgumentException('Žemėlapio ribos yra neteisingos.');
        }
        return implode(',', array_map(static fn (float $part): string => (string) $part, [$minLng, $minLat, $maxLng, $maxLat]));
    }
}
