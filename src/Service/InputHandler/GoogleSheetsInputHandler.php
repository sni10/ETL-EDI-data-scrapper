<?php
// src/Service/InputHandler/GoogleSheetsInputHandler.php
namespace App\Service\InputHandler;

use App\Model\DataCollection;
use App\Model\DataRow;
use Google\Service\Sheets;
use Google\Service\Exception as GoogleServiceException;

class GoogleSheetsInputHandler extends GoogleApiInputHandler
{
    protected function setupService(): void {
        $this->client->addScope(Sheets::SPREADSHEETS_READONLY);
        $this->service = new Sheets($this->client);
    }

    /**
     * @throws \Exception
     */
    public function readData(string $source, ?string $range = null): DataCollection
    {
        $this->authenticateClient();
        $dataCollection = new DataCollection();

        $response = $this->fetchWithRetries($source, $range);

        if (!$response) {
            $this->logger->error("GoogleSheets: No response from API for $source");
            return $dataCollection;
        }

        $rows = $response->getValues();

        if (empty($rows) || count($rows) < 2) {
            $this->logger->error("GoogleSheets: No data found or invalid spreadsheet ID: $source");
            return $dataCollection;
        }

        // Фильтруем пустые строки (если какие-то полностью пусты)
        $rows = array_filter($rows, fn($r) => array_filter($r));
        $header = array_map('trim', array_shift($rows));
        foreach ($rows as $row) {
            $row = array_map('trim', array_pad($row, count($header), null));
            $rowData = @array_combine($header, $row);
            if ($rowData === false) {
                $this->logger->info("GoogleSheets: Failed to combine row with header: $source");
                continue;
            }
            $dataCollection->add(new DataRow($rowData));
        }

        return $dataCollection;
    }

    private function fetchWithRetries($source, $range)
    {
        $maxAttempts = 10;
        $baseDelay = 5;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                return $this->service->spreadsheets_values->get($source, $range);
            } catch (\Google\Service\Exception $gse) {
                $code = $gse->getCode();
                if (in_array($code, [429, 500, 503]) && $attempt < $maxAttempts - 1) {
                    $sleep = $baseDelay * ($attempt + 1);
                    $this->logger->warning("GoogleSheets: Retry {$attempt} after error {$code}, sleeping {$sleep}s");
                    sleep($sleep);
                    $attempt++;
                    continue;
                }
                $this->logger->error("GoogleSheets: Google API error while reading '$source' — " . $gse->getMessage());
                break;
            } catch (\Exception $e) {
                $this->logger->error("GoogleSheets: Unexpected error reading '$source' — " . $e->getMessage());
                break;
            }
        }

        return null;
    }


}
