<?php

declare(strict_types=1);

namespace App\Service\Import;

final class LeaSourceLocator
{
    public const PAGE_URL = 'https://www.ena.lt/degalu-kainos-degalinese/';
    public const ARCHIVE_URL = 'https://www.ena.lt/dk-visa-informacija/';

    public function locate(string $html, string $pageUrl = self::PAGE_URL): LeaSource
    {
        if (trim($html) === '') {
            throw new \RuntimeException('LEA puslapis yra tuščias.');
        }

        preg_match_all(
            '/<a\b(?<attributes>[^>]*)>(?<label>.*?)<\/a>/isu',
            $html,
            $links,
            PREG_SET_ORDER,
        );

        $candidates = [];
        foreach ($links as $link) {
            $attributes = (string) $link['attributes'];
            $href = $this->attributeValue($attributes, 'href');
            if ($href === null) {
                continue;
            }

            $label = $this->normalizeText(strip_tags((string) $link['label']));
            $title = $this->normalizeText((string) ($this->attributeValue($attributes, 'title') ?? ''));
            $date = $this->extractSourceDate($title, $label);
            if ($date === null) {
                continue;
            }

            $sourceDate = $this->validateDate($date);
            $downloadUrl = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertAllowedDownloadUrl($downloadUrl);

            $candidates[] = [
                'sourceDate' => $sourceDate,
                'downloadUrl' => $this->withDownloadFlag($downloadUrl),
            ];
        }

        if ($candidates === []) {
            throw new \RuntimeException(
                'LEA puslapyje nerasta datuota degalų kainų failo nuoroda. Importas sustabdytas.',
            );
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['sourceDate'] <=> $left['sourceDate'],
        );
        $latest = $candidates[0];

        return new LeaSource(
            pageUrl: $pageUrl,
            downloadUrl: $latest['downloadUrl'],
            sourceDate: $latest['sourceDate'],
        );
    }

    private function attributeValue(string $attributes, string $name): ?string
    {
        $quotedName = preg_quote($name, '/');
        if (!preg_match(
            "/\\b{$quotedName}\\s*=\\s*(?:\"(?<double>[^\"]*)\"|'(?<single>[^']*)')/isu",
            $attributes,
            $match,
            PREG_UNMATCHED_AS_NULL,
        )) {
            return null;
        }

        return $match['double'] ?? $match['single'];
    }

    private function extractSourceDate(string $title, string $label): ?string
    {
        if (preg_match('/^degalų\s+kainos\s+(?<date>\d{4}-\d{2}-\d{2})$/iu', $title, $match)) {
            return $match['date'];
        }

        if (preg_match('/naujausios\s+degalų\s+kainos.*?(?<date>\d{4}-\d{2}-\d{2})/iu', $label, $match)) {
            return $match['date'];
        }

        return null;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function validateDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new \RuntimeException("LEA puslapyje nurodyta netinkama šaltinio data: {$date}");
        }

        return $date;
    }

    private function assertAllowedDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host !== 'ltenergagen.sharepoint.com') {
            throw new \RuntimeException('LEA duomenų nuoroda nukreipia į neleistiną adresą. Importas sustabdytas.');
        }
    }

    private function withDownloadFlag(string $url): string
    {
        if (preg_match('/(?:^|[?&])download=1(?:&|$)/', $url)) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'download=1';
    }
}
