<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class LeaPortalConfig
{
    public function __construct(
        public string $apiBase,
        public string $token,
    ) {
    }
}
