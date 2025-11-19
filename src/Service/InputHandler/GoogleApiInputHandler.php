<?php
namespace App\Service\InputHandler;

use Google\Client;
use Google\Exception;
use Psr\Log\LoggerInterface;
use App\Model\DataCollection;

abstract class GoogleApiInputHandler implements InputHandlerInterface
{
    protected LoggerInterface $logger;
    protected Client $client;
    protected $service;
    protected string $credentialsPath;
    protected string $tokenPath;

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function __construct(
        LoggerInterface $logger,
        string $credentialsPath,
        string $tokenPath
    ) {
        $this->logger = $logger;
        $this->credentialsPath = $credentialsPath;
        $this->tokenPath = $tokenPath;

        $this->client = new Client();
        $this->client->setAuthConfig($this->credentialsPath);
        $this->initializeClient();
    }

    /**
     * @throws \Exception
     */
    protected function initializeClient(): void {
        if (!file_exists($this->tokenPath)) {
            throw new \Exception("Token file not found: " . $this->tokenPath);
        }

        $accessToken = json_decode(file_get_contents($this->tokenPath), true);
        $this->client->setAccessToken($accessToken);

        if ($this->client->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }
        $this->setupService();
    }

    protected function refreshAccessToken(): void {
        $this->logger->info("Access token is expired. Trying to refresh...");
        $refreshToken = $this->client->getRefreshToken();

        if ($refreshToken) {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
            file_put_contents($this->tokenPath, json_encode($newToken));
            $this->logger->info("Token has been refreshed and saved.");
            $this->client->setAccessToken($newToken);
        } else {
            throw new \Exception("No refresh token available.");
        }
    }

    /**
     * @throws \Exception
     */
    protected function authenticateClient(): void {
        if ($this->client->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }
    }

    abstract protected function setupService(): void;
    abstract public function readData(string $source, ?string $range = null): DataCollection;
}
