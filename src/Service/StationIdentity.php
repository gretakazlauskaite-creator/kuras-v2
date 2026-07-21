<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Creates a deterministic LEA station identity from the source company and address.
 * The hash is stored separately from the human-facing public id so aliases and
 * manual corrections can be added without changing URLs.
 */
final class StationIdentity
{
    /** @return array{source_key:string,public_id:string,normalized_brand:string,normalized_address:string} */
    public function fromSource(string $brand, string $address): array
    {
        $normalizedBrand = $this->normalize($brand);
        $normalizedAddress = $this->normalize($address);

        if ($normalizedBrand === '' || $normalizedAddress === '') {
            throw new \InvalidArgumentException('Degalinės įmonė ir adresas negali būti tušti.');
        }

        $sourceKey = hash('sha256', 'lea|' . $normalizedBrand . '|' . $normalizedAddress);

        return [
            'source_key' => $sourceKey,
            'public_id' => 'st_' . substr($sourceKey, 0, 20),
            'normalized_brand' => $normalizedBrand,
            'normalized_address' => $normalizedAddress,
        ];
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim(str_replace("\u{00A0}", ' ', $value)));
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
