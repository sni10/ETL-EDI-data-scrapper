<?php

namespace App\Service\InputHandler;

use App\Model\DataCollection;
use App\Model\DataRow;
use App\Service\Config\RestApiConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class RestApiInputHandler implements InputHandlerInterface
{
    private Client $client;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RestApiConfig $cfg,
        Client $client
    ) {
        $this->client = $client;
    }

    public function readData(string $source, ?string $range = null): DataCollection
    {
        $dataCollection = new DataCollection();

        $baseItemsUrl = $this->buildItemsUrl($source);
        $defaultHeaders = $this->buildDefaultHeaders();

        $pageSize = (int)($this->cfg->items['page_size'] ?? 100);
        $pageParamName = (string)($this->cfg->items['page_param'] ?? 'page');
        $sizeParamName = (string)($this->cfg->items['size_param'] ?? 'per_page');

        foreach ($this->paginate($baseItemsUrl, $defaultHeaders, $pageSize, $pageParamName, $sizeParamName) as $rows) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $dataCollection->add(new DataRow($row));
                }
            }
        }

        return $dataCollection;
    }

    private function paginate(string $baseUrl, array $headers, int $pageSize, string $pageParamName, string $sizeParamName): iterable
    {
        $currentPage = 1;
        $hasNextPage = true;
        $lastPageNumber = null;

        while ($hasNextPage && ($lastPageNumber === null || $currentPage <= $lastPageNumber)) {
            $requestUrl = $this->withQuery($baseUrl, [$pageParamName => $currentPage, $sizeParamName => $pageSize]);
            $responseJson = $this->fetchJson($requestUrl, $headers);
            if ($responseJson === null) {
                break;
            }

            yield $this->extractRows($responseJson);

            if (isset($responseJson['meta']['last_page']) && is_numeric($responseJson['meta']['last_page'])) {
                $lastPageNumber = (int)$responseJson['meta']['last_page'];
            }
            $hasNextPage = !empty($responseJson['links']['next']) || ($lastPageNumber !== null && $currentPage < $lastPageNumber);
            $currentPage++;
        }
    }

    private function fetchJson(string $url, array $headers): ?array
    {
        $requestOptions = [
            'headers' => $headers,
            'verify' => true,
            'timeout' => 30,
            'connect_timeout' => 5,
            'http_errors' => false,
        ];

        try {
            $response = $this->client->get($url, $requestOptions);
        } catch (GuzzleException $exception) {
            $this->logger->error('REST API network error: ' . $exception->getMessage(), ['url' => $url]);
            return null;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode === 404) {
            return null;
        }
        if ($statusCode >= 400) {
            $this->logger->error('REST API error status', ['status' => $statusCode, 'url' => $url]);
            return null;
        }

        $responseBody = (string)$response->getBody();
        try {
            $decodedJson = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->error('REST API invalid JSON', [
                'url' => $url,
                'body_preview' => mb_strimwidth($responseBody, 0, 512, '...'),
            ]);
            return null;
        }

        return is_array($decodedJson) ? $decodedJson : null;
    }

    private function extractRows(array $json): array
    {
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }
        $this->logger->warning('REST API: key "data" missed or not array. Available keys:', [
            'keys' => array_keys($json),
        ]);
        return [];

    }

    private function buildItemsUrl(string $source): string
    {
        if ($this->looksLikeUrl($source)) {
            return $source;
        }
        $base = rtrim($this->cfg->baseUri, '/');
        $path = '/' . ltrim((string)($this->cfg->items['uri'] ?? ''), '/');
        return $base . $path;
    }

    private function looksLikeUrl(string $value): bool
    {
        return (bool)preg_match('#^https?://#i', $value);
    }

    private function withQuery(string $url, array $params): string
    {
        $urlParts = parse_url($url) ?: [];
        $queryParams = [];
        if (!empty($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
        }
        $queryParams = array_merge($queryParams, $params);

        $scheme = $urlParts['scheme'] ?? 'https';
        $host = $urlParts['host'] ?? '';
        $port = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $path = $urlParts['path'] ?? '/';

        $userInfo = '';
        if (!empty($urlParts['user'])) {
            $userInfo = $urlParts['user'] . (!empty($urlParts['pass']) ? ':' . $urlParts['pass'] : '') . '@';
        }

        $base = $scheme . '://' . $userInfo . $host . $port . $path;
        $queryString = http_build_query($queryParams);
        $fragment = !empty($urlParts['fragment']) ? '#' . $urlParts['fragment'] : '';

        return $base . ($queryString ? ('?' . $queryString) : '') . $fragment;
    }

    private function buildDefaultHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];
        if (isset($this->cfg->auth['company_id'])) {
            $headers['Company'] = (string)$this->cfg->auth['company_id'];
        }
        return $headers;
    }
}
