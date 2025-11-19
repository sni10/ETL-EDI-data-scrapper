<?php

namespace App\Service\InputHandler;

use App\Model\DataCollection;
use App\Model\DataRow;
use App\Service\Transport\HttpTransport;
use App\Service\Transport\SftpTransport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Log\LoggerInterface;

class ExcelInputHandler implements InputHandlerInterface
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
        $content = null;

        // Получаем данные через транспорт
        if ($this->httpTransport && $this->httpTransport->isRemoteSource($source)) {
            $content = $this->httpTransport->downloadFileContent($source);
            if ($content === null) {
                return $dataCollection;
            }
        } elseif ($this->sftpTransport) {
            $files = $this->sftpTransport->fetchFileFromSftp($source);
            if (!$files) {
                $this->logger->error("Excel: Нет файлов или пустой контент");
                return $dataCollection;
            }
            foreach ($files as $filename => $fileContent) {
                $parsed = $this->parseExcelContent($fileContent, $filename, $range);
                foreach ($parsed->getRows() as $row) {
                    $dataCollection->add($row);
                }
            }
            return $dataCollection;
        } else {
            // Локальный файл
            if (!file_exists($source)) {
                $this->logger->error("Excel: File not found: {$source}");
                return $dataCollection;
            }
            $content = file_get_contents($source);
        }

        if (!$content) {
            $this->logger->error("Excel: No data to parse from: {$source}");
            return $dataCollection;
        }

        return $this->parseExcelContent($content, $source, $range);
    }

    public function parseExcelContent(string $content, string $filename, ?string $range = null): DataCollection
    {
        $dataCollection = new DataCollection();

        $tempFile = $this->createTempFile($content, $filename);
        if ($tempFile === null) {
            return $dataCollection;
        }

        try {
            $spreadsheet = $this->loadSpreadsheet($tempFile, $filename);
            if ($spreadsheet === null) {
                return $dataCollection;
            }

            [$worksheet, $address] = $this->resolveWorksheetAndAddress($spreadsheet, $range, $filename);

            $rows = $this->readRows($worksheet, $address, $filename);
            return $this->buildCollectionFromRows($rows, $filename);
        } finally {
            $this->cleanupTemp($tempFile);
        }
    }

    private function createTempFile(string $content, string $filename): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
        if ($tempFile === false) {
            $this->logger->error("Excel: Не удалось создать временный файл для {$filename}");
            return null;
        }
        if (file_put_contents($tempFile, $content) === false) {
            $this->logger->error("Excel: Не удалось записать временный файл для {$filename}");
            @unlink($tempFile);
            return null;
        }
        return $tempFile;
    }

    private function loadSpreadsheet(string $tempFile, string $filename): ?Spreadsheet
    {
        try {
            return IOFactory::load($tempFile);
        } catch (\Exception $e) {
            $this->logger->error("Excel: Ошибка при чтении файла {$filename}: " . $e->getMessage());
            return null;
        }
    }

    private function resolveWorksheetAndAddress(Spreadsheet $spreadsheet, ?string $range, string $filename): array
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $address = null;

        if ($range !== null) {
            $address = trim($range);
            $sheetName = null;

            if (str_contains($address, '!')) {
                [$sheetName, $address] = explode('!', $address, 2);
                $sheetName = trim($sheetName);
                $address = trim($address);
            }

            if ($sheetName !== null && $sheetName !== '') {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                if ($sheet) {
                    $worksheet = $sheet;
                } else {
                    $this->logger->warning("Excel: Лист '{$sheetName}' не найден в {$filename}, используем активный лист");
                }
            }
        }

        return [$worksheet, $address];
    }

    private function readRows(Worksheet $worksheet, ?string $address, string $filename): array
    {
        try {
            if ($address === null || $address === '') {
                return $worksheet->toArray();
            }
            $normalizedAddress = $this->normalizeRange($worksheet, $address);
            return $worksheet->rangeToArray($normalizedAddress);
        } catch (\Throwable $e) {
            $this->logger->error("Excel: Ошибка чтения диапазона '{$normalizedAddress}' в {$filename}: " . $e->getMessage());
            return $worksheet->toArray();
        }
    }

    private function buildCollectionFromRows(array $rows, string $filename): DataCollection
    {
        $dataCollection = new DataCollection();

        if (empty($rows) || count($rows) < 2) {
            $this->logger->warning("Excel: Недостаточно данных в файле {$filename}");
            return $dataCollection;
        }

        $header = array_shift($rows);
        $counter = 1;

        foreach ($rows as $row) {
            $row = array_pad($row, count($header), null);

            $rowData = @array_combine($header, $row);
            if ($rowData === false) {
                $this->logger->warning("Excel: Не удалось объединить строку с заголовком в файле {$filename}");
                continue;
            }

            $dataCollection->add(new DataRow($rowData, $counter++));
        }

        return $dataCollection;
    }

    private function cleanupTemp(string $tempFile): void
    {
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }

    /**
     *
     * Нормализует строку диапазона Excel к валидному прямоугольнику.
     * Примеры:
     *  - "A:I"   -> "A1:I{highestRow}"
     *  - "A1:I"  -> "A1:I{highestRow}"
     *  - "I"     -> "I1:I{highestRow}"
     * Если диапазон уже валиден (например, "A1:I500" или "B2:D10") — возвращается как есть.
     * В случае неизвестного формата — возвращает исходную строку.
     */
    private function normalizeRange(Worksheet $worksheet, string $range): string
    {
        $range = trim($range);
        if ($range === '') {
            return $range;
        }

        // Уже прямоугольник вида A1:I500
        if (preg_match('/^[A-Z]+[0-9]+:[A-Z]+[0-9]+$/i', $range)) {
            return $range;
        }

        $highestRow = max(1, $worksheet->getHighestRow());

        // Формат "A:I" -> "A1:I{highestRow}"
        if (preg_match('/^([A-Z]+):([A-Z]+)$/i', $range, $m)) {
            return sprintf('%s1:%s%d', strtoupper($m[1]), strtoupper($m[2]), $highestRow);
        }

        // Формат "A1:I" -> "A1:I{highestRow}"
        if (preg_match('/^([A-Z]+[0-9]+):([A-Z]+)$/i', $range, $m)) {
            return sprintf('%s:%s%d', strtoupper($m[1]), strtoupper($m[2]), $highestRow);
        }

        // Формат "I" -> "I1:I{highestRow}"
        if (preg_match('/^([A-Z]+)$/i', $range, $m)) {
            return sprintf('%s1:%s%d', strtoupper($m[1]), strtoupper($m[1]), $highestRow);
        }

        // Одна ячейка, оставляем как есть (например, "C3")
        if (preg_match('/^[A-Z]+[0-9]+$/i', $range)) {
            return $range;
        }

        // Неизвестный формат — логируем и возвращаем как есть
        $this->logger->warning("Excel: Неподдерживаемый формат диапазона '{$range}', попытка использовать как есть");
        return $range;
    }

}
