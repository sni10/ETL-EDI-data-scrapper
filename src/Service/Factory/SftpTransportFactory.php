<?php

namespace App\Service\Factory;

use App\Service\Transport\SftpTransport;
use Psr\Log\LoggerInterface;

class SftpTransportFactory
{
    private LoggerInterface $logger;
    private string $configPath;

    public function __construct(LoggerInterface $logger, string $configPath)
    {
        $this->logger = $logger;
        $this->configPath = $configPath;
    }

    public function create(string $supplierId): SftpTransport
    {
        return new SftpTransport($this->logger, $this->configPath, $supplierId);
    }
}