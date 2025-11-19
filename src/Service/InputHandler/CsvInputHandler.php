<?php

namespace App\Service\InputHandler;

use App\Model\DataCollection;
use App\Model\DataRow;
use App\Service\Transport\HttpTransport;
use App\Service\Transport\SftpTransport;
use Psr\Log\LoggerInterface;

class CsvInputHandler implements InputHandlerInterface
{
    private LoggerInterface $logger;
    private ?HttpTransport $httpTransport;
    private ?SftpTransport $sftpTransport;

    public function __construct(LoggerInterface $logger, ?HttpTransport $httpTransport = null, ?SftpTransport $sftpTransport = null)
    {
        $this->logger = $logger;
        $this->httpTransport = $httpTransport;
        $this->sftpTransport = $sftpTransport;
    }

    public function readData(string $source, ?string $range = null): DataCollection
    {
        $dataCollection = new DataCollection();
        $csvData = null;
        $isTemporary = false;

        // Получаем данные через транспорт
        if ($this->httpTransport && $this->httpTransport->isRemoteSource($source)) {
            $content = $this->httpTransport->downloadFileContent($source);
            if ($content === null) {
                return $dataCollection;
            }
            $csvData = $content;
        } elseif ($this->sftpTransport) {
            $files = $this->sftpTransport->fetchFileFromSftp($source);
            if (!$files) {
                $this->logger->error("CSV: Нет файлов или пустой контент");
                return $dataCollection;
            }
            foreach ($files as $filename => $content) {
                $csvData = $content;
                break; // берем первый файл
            }
        } else {
            // Локальный файл
            if (!file_exists($source)) {
                $this->logger->error("CSV: File not found: {$source}");
                return $dataCollection;
            }
            $csvData = file_get_contents($source);
        }

        if (!$csvData) {
            $this->logger->error("CSV: No data to parse from: {$source}");
            return $dataCollection;
        }

        // Парсим CSV данные
        return $this->parseCsvData($csvData, $source);
    }

    private function parseCsvData(string $csvData, string $source): DataCollection
    {
        $dataCollection = new DataCollection();
        
        $lines = explode("\n", trim($csvData));
        if (count($lines) < 2) {
            $this->logger->warning("CSV: Недостаточно строк в файле {$source}");
            return $dataCollection;
        }

        $header = str_getcsv(array_shift($lines));
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $row = str_getcsv($line);
            if (count($row) !== count($header)) {
                $this->logger->info("CSV: Несовпадение колонок в {$source}");
                continue;
            }

            $assoc = @array_combine($header, $row);
            if (!$assoc) {
                $this->logger->info("CSV: Ошибка в array_combine для строки в {$source}");
                continue;
            }

            $dataCollection->add(new DataRow($assoc));
        }

        return $dataCollection;
    }
}
