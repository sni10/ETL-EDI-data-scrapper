<?php

namespace App\Service\Auth;

use Eljam\GuzzleJwt\JwtToken;
use Eljam\GuzzleJwt\Manager\JwtManager as BaseJwtManager;
use Eljam\GuzzleJwt\Persistence\TokenPersistenceInterface;
use Eljam\GuzzleJwt\Strategy\Auth\AuthStrategyInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

class PlainStringJwtManager extends BaseJwtManager
{
    public function __construct(
        ClientInterface $client,
        AuthStrategyInterface $strategy,
        ?TokenPersistenceInterface $persistence = null,
        array $options = []
    ) {
        parent::__construct($client, $strategy, $persistence, $options);
    }

    public function getJwtToken()
    {
        if ($this->token === null) {
            $this->token = $this->tokenPersistence->restoreToken();
        }
        if ($this->token !== null && $this->token->isValid()) {
            return $this->token;
        }

        $this->tokenPersistence->deleteToken();

        $url = $this->options['token_url'];
        $requestOptions = $this->auth->getRequestOptions() + [
            RequestOptions::TIMEOUT => (float)($this->options['timeout'] ?? 30),
            RequestOptions::CONNECT_TIMEOUT => (float)($this->options['connect_timeout'] ?? 5),
            RequestOptions::HTTP_ERRORS => false,
        ];

        $response = $this->client->request('POST', $url, $requestOptions);
        $rawToken = trim((string) $response->getBody());

        $expiration = new \DateTime('+24 hours');

        $this->token = new JwtToken($rawToken, $expiration);
        $this->tokenPersistence->saveToken($this->token);

        return $this->token;
    }
}
