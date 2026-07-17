<?php

declare(strict_types=1);

namespace App\Service\Import;

final class LeaSourceLocator
{
    public const PAGE_URL = 'https://www.ena.lt/degalu-kainos-degalinese/';

    public function locate(string $html, string $pageUrl = self::PAGE_URL): LeaSource
    {
        if (trim($html) === '') {
            throw new \RuntimeException('LEA puslapis yra tuščias.');
        }

        preg_match_all(
            '/<a\b[^>]*href\s*=\s*(["\'])(?<href>.*?)\1[^>]*>(?<label>.*?)<\/a>/isu',
            $html,
            $links,
            PREG_SET_ORDER,
        );

        foreach (array_reverse($links) as $link) {
            $label = $this->normalizeText(strip_tags((string) $link['label']));
            if (!preg_match('/naujausios\s+degalų\s+kainos.*?(?<date>\d{4}-\d{2}-\d{2})/iu', $label, $match)) {
                continue;
            }

            $sourceDate = $this->validateDate($match['date']);
            $downloadUrl = html_entity_decode((string) $link['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertAllowedDownloadUrl($downloadUrl);

            return new LeaSource(
                pageUrl: $pageUrl,
                downloadUrl: $this->withDownloadFlag($downloadUrl),
                sourceDate: $sourceDate,
            );
        }

        throw new \RuntimeException(
            'LEA puslapyje nerasta nuoroda „Naujausios degalų kainos (YYYY-MM-DD)“. Importas sustabdytas.',
        );
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
