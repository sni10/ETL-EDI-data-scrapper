<?php

namespace App\Service\Auth;

use Eljam\GuzzleJwt\JwtToken;
use Eljam\GuzzleJwt\Manager\JwtManager;
use Eljam\GuzzleJwt\Persistence\TokenPersistenceInterface;
use Eljam\GuzzleJwt\Strategy\Auth\AuthStrategyInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class SafeJwtManagerWrapper extends JwtManager
{
    public function __construct(
        private readonly LoggerInterface $logger,
        ClientInterface $client,
        AuthStrategyInterface $strategy,
        ?TokenPersistenceInterface $persistence = null,
        array $options = []
    ) {
        parent::__construct($client, $strategy, $persistence, $options);
    }

    public function getJwtToken()
    {
        try {
            return parent::getJwtToken();
        } catch (GuzzleException $e) {
            $this->logger->error('JWT token request failed: network error', [
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to obtain JWT token: server unavailable', 0, $e);
        }
    }
}