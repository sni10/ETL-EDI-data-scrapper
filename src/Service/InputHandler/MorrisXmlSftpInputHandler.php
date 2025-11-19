<?php

namespace App\Service\InputHandler;

use App\Model\DataCollection;
use App\Model\DataRow;
use App\Service\Transport\SftpTransport;
use Psr\Log\LoggerInterface;

class MorrisXmlSftpInputHandler implements InputHandlerInterface
{
    private LoggerInterface $logger;
    private SftpTransport $sftpTransport;

    public function __construct(LoggerInterface $logger, string $configPath)
    {
        $this->logger = $logger;
        $this->sftpTransport = new SftpTransport($logger, $configPath, '19');
    }

    public function parseDataToCollection(string $xmlContent): DataCollection
    {
        $dataCollection = new DataCollection();

        $xml = simplexml_load_string($xmlContent);
        if (!$xml || !isset($xml->available)) {
            $this->logger->warning("Некорректный или пустой XML-файл");
            return $dataCollection;
        }

        foreach ($xml->available as $item) {
            $rowData = [
                'price'   => (float) $item->detail->price,
                'gtin'    => (string) $item->gtin,
                'qty'     => (int) $item->qty,
            ];

            $dataCollection->add(new DataRow($rowData));
        }

        return $dataCollection;
    }

    public function readData(string $source = null, ?string $range = null): DataCollection
    {
        $collection = new DataCollection();

        $files = $this->sftpTransport->fetchFileFromSftp($source);
        if (!$files) {
            $this->logger->error("Morris XML SFTP: Нет файлов или пустой контент");
            return $collection;
        }

        foreach ($files as $filename => $content) {
            $parsed = $this->parseDataToCollection($content);
            foreach ($parsed->getRows() as $row) {
                $collection->add($row);
            }
        }

        return $collection;
    }

}
