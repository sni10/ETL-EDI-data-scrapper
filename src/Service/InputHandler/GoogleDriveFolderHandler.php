<?php

namespace App\Service\InputHandler;

use Exception;
use Google\Service\Drive;
use Google\Service\Exception as GoogleServiceException;
use Psr\Log\LoggerInterface;
use App\Model\DataCollection;

class GoogleDriveFolderHandler extends GoogleApiInputHandler
{
    public function __construct(
        LoggerInterface $logger,
        string $credentialsPath,
        string $tokenPath
    ) {
        parent::__construct($logger, $credentialsPath, $tokenPath);
    }

    protected function setupService(): void {
        $this->client->addScope(Drive::DRIVE_READONLY);
        $this->service = new Drive($this->client);
    }

    /**
     * @throws Exception
     */
    public function readData(string $source, ?string $range = null): DataCollection
    {
        $this->authenticateClient();
        $dataCollection = new DataCollection();
        try {
            $files = $this->service->files->listFiles([
                'q' => "'{$source}' in parents and trashed=false",
                'fields' => 'files(id, name, mimeType)'
            ])->getFiles();

            if (empty($files)) {
                $this->logger->error("Google Drive: No files found in folder {$source}");
                return $dataCollection;
            }

            $file = reset($files);
            $tempPath = $this->downloadFile($file->getId());
            $fileType = $this->getFileType($file->getName(), $file->getMimeType());

            switch ($fileType) {
                case 'csv':
                    $csvHandler = new CsvInputHandler($this->logger);
                    return $csvHandler->readData($tempPath, $range);

                case 'excel':
                    $excelHandler = new ExcelInputHandler($this->logger);
                    return $excelHandler->readData($tempPath, $range);

                default:
                    $this->logger->error("Google Drive: Unsupported file type: {$file->getName()}");
                    return $dataCollection;
            }
        } catch (GoogleServiceException $gse) {
            $this->logger->error("Google Drive Service: Google API error while reading '$source' — " . $gse->getMessage());
        } catch (\Exception $e) {
            $this->logger->error("Unexpected: Unexpected error reading '$source' — " . $e->getMessage());
        }

        return $dataCollection;
    }

    /**
     * @throws GoogleServiceException
     */
    private function downloadFile(string $fileId): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'gdrive_' . $fileId);
        $response = $this->service->files->get($fileId, ['alt' => 'media']);
        @file_put_contents($tempPath, $response->getBody()->getContents());
        return $tempPath;
    }

    private function getFileType(string $fileName, string $mimeType): string
    {
        return $this->isCsv($fileName, $mimeType) ? 'csv' :
            ($this->isExcel($fileName, $mimeType) ? 'excel' : 'unsupported');
    }

    private function isCsv(string $fileName, string $mimeType): bool
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return $ext === 'csv' || str_contains($mimeType, 'text/csv');
    }

    private function isExcel(string $fileName, string $mimeType): bool
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($ext, ['xls', 'xlsx']) ||
            in_array($mimeType, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
