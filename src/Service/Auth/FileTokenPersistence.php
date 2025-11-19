<?php

namespace App\Service\Auth;

use Eljam\GuzzleJwt\JwtToken;
use Eljam\GuzzleJwt\Persistence\TokenPersistenceInterface;

class FileTokenPersistence implements TokenPersistenceInterface
{
    public function __construct(
        private readonly string $file,
        private readonly string $supplierId
    ) {}

    public function saveToken(JwtToken $token)
    {
        $all = [];
        if (is_file($this->file)) {
            $tmp = json_decode((string)file_get_contents($this->file), true);
            if (is_array($tmp)) {
                $all = $tmp;
            }
        }

        $expiresAt = null;
        if (method_exists($token, 'getExpiration')) {
            $exp = $token->getExpiration();
            if ($exp instanceof \DateTimeInterface) {
                $expiresAt = $exp->getTimestamp();
            }
        }

        $all[$this->supplierId] = [
            'token' => $token->getToken(),
            'expiresAt' => $expiresAt,
        ];

        @file_put_contents($this->file, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function restoreToken(): ?JwtToken
    {
        if (!is_file($this->file)) {
            return null;
        }
        
        $content = @file_get_contents($this->file);
        if ($content === false) {
            return null;
        }
        
        $all = json_decode($content, true);
        if (!is_array($all)) {
            return null;
        }
        
        $entry = $all[$this->supplierId] ?? null;
        if (!is_array($entry) || empty($entry['token']) || !is_string($entry['token'])) {
            return null;
        }
        
        $expiration = null;
        if (!empty($entry['expiresAt']) && is_numeric($entry['expiresAt'])) {
            try {
                $expiration = new \DateTime('@' . (int)$entry['expiresAt']);
            } catch (\Exception) {
                // Игнорируем невалидную дату
            }
        }
        
        return new JwtToken((string)$entry['token'], $expiration);
    }

    public function deleteToken()
    {
        if (!is_file($this->file)) {
            return;
        }
        $all = json_decode((string)file_get_contents($this->file), true);
        if (!is_array($all)) {
            return;
        }
        unset($all[$this->supplierId]);
        @file_put_contents($this->file, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function hasToken(): bool
    {
        if (!is_file($this->file)) {
            return false;
        }
        $all = json_decode((string)file_get_contents($this->file), true);
        return is_array($all) && !empty($all[$this->supplierId]['token']);
    }
}
