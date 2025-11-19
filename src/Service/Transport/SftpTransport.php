<?php

namespace App\Service\Transport;

use phpseclib3\Net\SFTP;
use Psr\Log\LoggerInterface;

class SftpTransport
{
    private LoggerInterface $logger;
    private array $sftpConfig;
    private array $proxyConfig;

    public function __construct(LoggerInterface $logger, string $configPath, string $configId)
    {
        $this->logger = $logger;
        $config = json_decode(file_get_contents($configPath), true);
        $this->sftpConfig = $config[$configId]['sftp'];
        $this->proxyConfig = $config[$configId]['proxy'];
    }

    public function fetchFileFromSftp(string $source = null): ?array
    {
        $sftp = $this->connectThroughProxySocks();
        if (!$sftp) {
            $this->logger->error('SFTP Transport: Не удалось подключиться к SFTP через прокси.');
            return null;
        }

        if (!$this->login($sftp)) {
            return null;
        }

        // Определяем директорию и префикс из source
        $directory = dirname($source);
        $prefix = basename($source, '.' . pathinfo($source, PATHINFO_EXTENSION));
        
        // Если директория не корневая, переходим в неё
        if ($directory !== '.' && $directory !== '') {
            if (!$sftp->chdir($directory)) {
                $this->logger->error("SFTP Transport: Не удалось перейти в директорию: {$directory}");
                return null;
            }
        }
        
        $files = $this->findAllFilesWithPrefix($sftp, $prefix);
        if (empty($files)) {
            $this->logger->error("SFTP Transport: Нет файлов с префиксом '{$prefix}'");
            return null;
        }

        usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
        $this->ensureHistoryFolder($sftp, 'history');
        $result = $this->downloadAndMove($sftp, $files, 'history');
        $sftp->disconnect();

        return $result;
    }

    private function connectThroughProxySocks(): ?SFTP
    {
        // Если прокси не задан, подключаемся напрямую
        if (empty($this->proxyConfig)) {
            // Прямое подключение без прокси
            $sftp = new SFTP($this->sftpConfig['host'], $this->sftpConfig['port']);
            $sftp->setTimeout(120);
            return $sftp;
        }

        // Иначе подключаемся через SOCKS5-прокси
        $proxy = $this->proxyConfig;
        $sftp = $this->sftpConfig;

        $socket = fsockopen($proxy['host'], $proxy['port'], $errno, $errstr, 30);
        if (!$socket) {
            $this->logger->error("SFTP Transport: Ошибка подключения к SOCKS5-прокси: $errstr ($errno)");
            return null;
        }

        // 1. Приветствие (1 метод авторизации — логин/пароль)
        fwrite($socket, chr(0x05) . chr(0x01) . chr(0x02));
        $response = fread($socket, 2);
        if ($response !== "\x05\x02") {
            $this->logger->error("SFTP Transport: SOCKS5 не принял авторизацию логин/пароль");
            fclose($socket);
            return null;
        }

        // 2. Аутентификация
        $username = $proxy['username'];
        $password = $proxy['password'];
        $ulen = strlen($username);
        $plen = strlen($password);

        fwrite($socket, chr(0x01) . chr($ulen) . $username . chr($plen) . $password);
        $authStatus = fread($socket, 2);
        if ($authStatus !== "\x01\x00") {
            $this->logger->error("SFTP Transport: SOCKS5 неверные учётные данные");
            fclose($socket);
            return null;
        }

        // 3. Запрос на соединение с SFTP
        $addr = gethostbyname($sftp['host']);
        $port = (int)$sftp['port'];

        $addrBytes = array_map('intval', explode('.', $addr));
        $portBytes = [($port >> 8) & 0xFF, $port & 0xFF];

        $request = chr(0x05) . chr(0x01) . chr(0x00) . chr(0x01)
            . chr($addrBytes[0]) . chr($addrBytes[1]) . chr($addrBytes[2]) . chr($addrBytes[3])
            . chr($portBytes[0]) . chr($portBytes[1]);

        fwrite($socket, $request);
        $response = fread($socket, 10);
        if (strlen($response) < 2 || ord($response[1]) !== 0x00) {
            $this->logger->error("SFTP Transport: SOCKS5 отказал в соединении с {$sftp['host']}:{$sftp['port']}");
            fclose($socket);
            return null;
        }

        // Успех: передаём готовый socket в phpseclib
        $sftpClient = new SFTP($sftp['host'], $sftp['port']);
        $sftpClient->fsock = $socket;
        $sftpClient->setTimeout(120);

        return $sftpClient;
    }

    private function login(SFTP $sftp): bool
    {
        if (!$sftp->login($this->sftpConfig['username'], $this->sftpConfig['password'])) {
            $this->logger->error('SFTP Transport: Ошибка аутентификации на SFTP-сервере.');
            return false;
        }
        return true;
    }

    private function findAllFilesWithPrefix(SFTP $sftp, string $prefix): array
    {
        $rawList = $sftp->rawlist();
        $files = [];

        foreach ($rawList as $file => $info) {
            if (in_array($file, ['.', '..'])) {
                continue;
            }
            // Проверяем только обычные файлы (type = 1)
            if (($info['type'] ?? null) !== 1) {
                continue;
            }
            if (str_starts_with($file, $prefix)) {
                $files[] = [
                    'filename' => $file,
                    'mtime'    => $info['mtime'] ?? 0,
                ];
            }
        }

        return $files;
    }

    private function ensureHistoryFolder(SFTP $sftp, string $folder): void
    {
        if (!$sftp->file_exists($folder)) {
            $this->logger->warning("SFTP Transport: Папка history НЕ найдена, создаю...");
            $sftp->mkdir($folder);
        }
    }

    private function downloadAndMove(SFTP $sftp, array $files, string $historyDir): array
    {
        $this->ensureHistoryFolder($sftp, $historyDir);

        $latest = array_pop($files);
        foreach ($files as $file) {
            $this->moveFileToHistory($sftp, $file['filename'], $historyDir);
        }

        $result = [];
        if ($latest) {
            $content = $this->downloadFile($sftp, $latest['filename']);
            if ($content !== false) {
                $result[$latest['filename']] = $content;
            }
        }

        return $result;
    }

    private function moveFileToHistory(SFTP $sftp, string $filename, string $historyDir): void
    {
        $content = $this->downloadFile($sftp, $filename, true);
        if ($content === false) {
            return;
        }

        $dst = "{$historyDir}/{$filename}";
        if (!$sftp->put($dst, $content)) {
            $this->logger->warning("SFTP Transport: Не удалось записать файл в history: {$dst}");
            return;
        }

        if (!$sftp->delete($filename)) {
            $this->logger->warning("SFTP Transport: Не удалось удалить оригинал: {$filename}");
        }
    }

    private function downloadFile(SFTP $sftp, string $filename, bool $forMove = false): string|false
    {
        $content = $sftp->get($filename);
        if ($content === false) {
            $msg = $forMove ? "Ошибка скачивания при перемещении" : "Ошибка скачивания";
            $this->logger->error("SFTP Transport: {$msg}: {$filename}");
        }
        return $content;
    }
}