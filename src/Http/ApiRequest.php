<?php

declare(strict_types=1);

namespace App\Http;

final class ApiRequest
{
    /** @param array<string,mixed> $query */
    public function __construct(private readonly array $query)
    {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET);
    }

    public function string(string $name, string $default = '', int $maxLength = 120): string
    {
        $value = trim((string) ($this->query[$name] ?? $default));
        if (mb_strlen($value) > $maxLength) {
            throw new ApiException('invalid_parameter', "Parametras „{$name}“ yra per ilgas.", 422, ['parameter' => $name]);
        }

        return $value;
    }

    /** @param list<string> $allowed */
    public function enum(string $name, array $allowed, string $default): string
    {
        $value = $this->string($name, $default, 40);
        if (!in_array($value, $allowed, true)) {
            throw new ApiException('invalid_parameter', "Neleistina parametro „{$name}“ reikšmė.", 422, [
                'parameter' => $name,
                'allowed' => $allowed,
            ]);
        }

        return $value;
    }

    public function integer(string $name, int $default, int $minimum, int $maximum): int
    {
        $raw = $this->query[$name] ?? $default;
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('invalid_parameter', "Parametras „{$name}“ turi būti sveikasis skaičius.", 422, ['parameter' => $name]);
        }

        $value = (int) $raw;
        if ($value < $minimum || $value > $maximum) {
            throw new ApiException('invalid_parameter', "Parametras „{$name}“ turi būti tarp {$minimum} ir {$maximum}.", 422, ['parameter' => $name]);
        }

        return $value;
    }

    public function number(string $name, ?float $default = null, ?float $minimum = null, ?float $maximum = null): ?float
    {
        $raw = $this->query[$name] ?? $default;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            throw new ApiException('invalid_parameter', "Parametras „{$name}“ turi būti skaičius.", 422, ['parameter' => $name]);
        }

        $value = (float) $raw;
        if (($minimum !== null && $value < $minimum) || ($maximum !== null && $value > $maximum)) {
            throw new ApiException('invalid_parameter', "Parametras „{$name}“ nepatenka į leistinas ribas.", 422, ['parameter' => $name]);
        }

        return $value;
    }

    public function date(string $name, string $default): string
    {
        $value = $this->string($name, $default, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ApiException('invalid_parameter', "Parametras „{$name}“ turi būti YYYY-MM-DD formato.", 422, ['parameter' => $name]);
        }

        return $value;
    }

    /** @return array{min_lng:float,min_lat:float,max_lng:float,max_lat:float} */
    public function bounds(string $name = 'bounds'): array
    {
        $parts = explode(',', $this->string($name, '', 100));
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            throw new ApiException('invalid_bounds', 'Žemėlapio ribos turi būti minLng,minLat,maxLng,maxLat formato.', 422);
        }

        [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', $parts);
        if ($minLng < -180 || $maxLng > 180 || $minLat < -90 || $maxLat > 90 || $minLng >= $maxLng || $minLat >= $maxLat) {
            throw new ApiException('invalid_bounds', 'Žemėlapio ribos yra neteisingos.', 422);
        }

        return ['min_lng' => $minLng, 'min_lat' => $minLat, 'max_lng' => $maxLng, 'max_lat' => $maxLat];
    }
}
