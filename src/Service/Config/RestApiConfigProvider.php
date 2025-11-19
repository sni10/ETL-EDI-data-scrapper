<?php

namespace App\Service\Config;

class RestApiConfigProvider
{
    public function __construct(private readonly string $configFile)
    {
    }

    public function forSupplier(string $supplierId): RestApiConfig
    {
        if (!is_file($this->configFile)) {
            throw new \RuntimeException('REST config file not found: ' . $this->configFile);
        }
        $all = json_decode((string)file_get_contents($this->configFile), true);
        if (!is_array($all)) {
            throw new \RuntimeException('Invalid REST config JSON: ' . $this->configFile);
        }
        $cfg = $all[$supplierId] ?? null;
        if (!is_array($cfg)) {
            throw new \RuntimeException('REST config not found for supplier: ' . $supplierId);
        }

        return new RestApiConfig(
            rtrim((string)($cfg['base_uri'] ?? ''), '/'),
            $cfg['auth'] ?? [],
            $cfg['items'] ?? [],
            (bool)($cfg['verify_ssl'] ?? true),
            $cfg['transport'] ?? [],
        );
    }
}
