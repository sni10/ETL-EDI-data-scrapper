<?php

namespace App\Service\Factory;

use App\Service\Auth\FileTokenPersistence;
use App\Service\Auth\PlainStringJwtManager;
use App\Service\Auth\SafeJwtManagerWrapper;
use App\Service\Config\RestApiConfigProvider;
use App\Service\InputHandler\RestApiInputHandler;
use Eljam\GuzzleJwt\JwtMiddleware;
use Eljam\GuzzleJwt\Manager\JwtManager;
use Eljam\GuzzleJwt\Strategy\Auth\JsonAuthStrategy;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

class RestApiHandlerFactory
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KernelInterface $kernel,
    ) {}

    public function create(string $supplierId): RestApiInputHandler
    {
        $projectDir = $this->kernel->getProjectDir();
        $configFile = Path::join($projectDir, 'config', 'rest.json');
        $tokensFile = Path::join($projectDir, 'config', 'rest.tokens.json');

        try {
            $configProvider = new RestApiConfigProvider($configFile);
            $cfg = $configProvider->forSupplier($supplierId);
        } catch (\RuntimeException $e) {
            $this->logger->error('REST API config error', [
                'supplier_id' => $supplierId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        $baseUri = rtrim($cfg->baseUri, '/');

        // Валидация базового URI
        if (empty($baseUri) || !filter_var($baseUri, FILTER_VALIDATE_URL)) {
            $this->logger->error('REST API invalid base URI', [
                'supplier_id' => $supplierId,
                'base_uri' => $baseUri
            ]);
            throw new \InvalidArgumentException("Invalid base URI: {$baseUri}");
        }

        $authHeaders = [];
        if (isset($cfg->auth['company_id'])) {
            $authHeaders['Company'] = (string)$cfg->auth['company_id'];
        }
        $authClient = new HttpClient([
            'base_uri' => $baseUri,
            'verify' => true,
            'timeout' => 30,
            'connect_timeout' => 5,
            'headers' => $authHeaders,
        ]);

        $authStrategy = new JsonAuthStrategy([
            'username' => $cfg->auth['username'] ?? '',
            'password' => $cfg->auth['password'] ?? '',
            'json_fields' => ['username', 'password'],
        ]);

        $persistence = new FileTokenPersistence($tokensFile, $supplierId);

        $jwtOptions = [
            'token_url' => (string)($cfg->auth['token_uri'] ?? '/api/v1/auth/init'),
            'timeout' => 30,
        ];
        if (!empty($cfg->auth['token_key'])) {
            $jwtOptions['token_key'] = (string) $cfg->auth['token_key'];
        }
        if (!empty($cfg->auth['expire_key'])) {
            $jwtOptions['expire_key'] = (string) $cfg->auth['expire_key'];
        }

        if (empty($jwtOptions['token_key'])) {
            $jwtManager = new PlainStringJwtManager(
                $authClient,
                $authStrategy,
                $persistence,
                $jwtOptions
            );
        } else {
            $jwtManager = new SafeJwtManagerWrapper(
                $this->logger,
                $authClient,
                $authStrategy,
                $persistence,
                $jwtOptions
            );
        }

        $stack = HandlerStack::create();
        $stack->push(new JwtMiddleware($jwtManager, 'Bearer'));

        $defaultHeaders = ['Accept' => 'application/json'];
        if (isset($cfg->auth['company_id'])) {
            $defaultHeaders['Company'] = (string)$cfg->auth['company_id'];
        }

        $client = new HttpClient([
            'handler' => $stack,
            'base_uri' => $baseUri,
            'verify' => true,
            'timeout' => 30,
            'connect_timeout' => 5,
            'headers' => $defaultHeaders,
        ]);

        return new RestApiInputHandler($this->logger, $cfg, $client);
    }
}