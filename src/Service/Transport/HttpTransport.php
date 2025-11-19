<?php

namespace App\Service\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class HttpTransport
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function isRemoteSource(string $source): bool
    {
        return preg_match('#^https?://#i', $source) === 1;
    }

    public function downloadFileContent(string $url): ?string
    {
        $client = new Client();

        try {
            $response = $client->get($url);
        } catch (GuzzleException $e) {
            $this->logger->error("HTTP Transport: Network error while downloading {$url}: " . $e->getMessage());
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error(
                "HTTP Transport: Could not download file or received error response. ".
                "Status: {$response->getStatusCode()}, URL: {$url}"
            );
            return null;
        }

        $content = $response->getBody()->getContents();
        if (!$content) {
            $this->logger->error("HTTP Transport: Empty body received from {$url}");
            return null;
        }

        return $content;
    }
}