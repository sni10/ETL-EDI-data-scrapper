<?php

namespace App\Service\Config;

class RestApiConfig
{
    public function __construct(
        public readonly string $baseUri,
        public readonly array $auth,
        public readonly array $items,
        public readonly bool $verifySsl,
        public readonly array $transport = [],
    ) {}
}
