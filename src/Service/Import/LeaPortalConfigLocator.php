<?php

declare(strict_types=1);

namespace App\Service\Import;

final class LeaPortalConfigLocator
{
    public const PORTAL_URL = 'https://degalukainos.ena.lt/';

    /**
     * @param callable(string,?string):string $fetch
     */
    public function locate(string $portalHtml, callable $fetch): LeaPortalConfig
    {
        if (trim($portalHtml) === '') {
            throw new \RuntimeException('LEA kainų portalas yra tuščias.');
        }

        $queue = $this->discoverAssets($portalHtml);
        $seen = [];

        while ($queue !== [] && count($seen) < 30) {
            $assetUrl = array_shift($queue);
            if ($assetUrl === null || isset($seen[$assetUrl])) {
                continue;
            }

            $seen[$assetUrl] = true;
            $javascript = $fetch($assetUrl, self::PORTAL_URL);

            if (preg_match('/apiBase:"([^"]+)",token:"([^"]+)"/', $javascript, $match)) {
                $this->assertAllowedApiBase($match[1]);

                return new LeaPortalConfig($match[1], $match[2]);
            }

            foreach ($this->discoverAssets($javascript) as $discoveredUrl) {
                if (!isset($seen[$discoveredUrl]) && !in_array($discoveredUrl, $queue, true)) {
                    $queue[] = $discoveredUrl;
                }
            }
        }

        throw new \RuntimeException('LEA kainų portale nerasta viešo API konfigūracija.');
    }

    /** @return list<string> */
    private function discoverAssets(string $content): array
    {
        preg_match_all(
            '~(?:(?:https://degalukainos\.ena\.lt)?/assets/|\./)([A-Za-z0-9._-]+\.js)~',
            $content,
            $matches,
        );

        return array_values(array_unique(array_map(
            static fn (string $file): string => self::PORTAL_URL . 'assets/' . $file,
            $matches[1] ?? [],
        )));
    }

    private function assertAllowedApiBase(string $url): void
    {
        $parts = parse_url($url);
        if (
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'api-degalukainos.ena.lt'
            || !str_starts_with((string) ($parts['path'] ?? ''), '/api/')
        ) {
            throw new \RuntimeException('LEA portalas nurodė neleistiną API adresą.');
        }
    }
}
