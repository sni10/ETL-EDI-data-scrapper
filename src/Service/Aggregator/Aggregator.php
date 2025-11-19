<?php

namespace App\Service\Aggregator;

use App\Model\DataCollection;
use App\Model\DataSetCollection;
use App\Service\Config\InputConfig;
use App\Service\InputHandler\CsvInputHandler;
use App\Service\InputHandler\ExcelInputHandler;
use App\Service\InputHandler\GoogleDriveFolderHandler;
use App\Service\InputHandler\GoogleSheetsInputHandler;
use App\Service\InputHandler\InputHandlerInterface;
use App\Service\InputHandler\MorrisXmlSftpInputHandler;
use App\Service\Transport\HttpTransport;
use App\Service\Factory\SftpTransportFactory;
use App\Service\Factory\RestApiHandlerFactory;
use App\Service\Mapper\Mapper;
use App\Service\Kafka\KafkaProducer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use Psr\Log\LoggerInterface;

class Aggregator
{
    private Mapper $mapper;
    private KafkaProducer $producer;
    private LoggerInterface $logger;
    private HttpTransport $httpTransport;
    private SftpTransportFactory $sftpTransportFactory;
    private GoogleSheetsInputHandler $googleSheetsInputHandler;
    private GoogleDriveFolderHandler $googleDriveFolderHandler;
    private MorrisXmlSftpInputHandler $morrisXmlSftpInputHandler;
    private RestApiHandlerFactory $restApiHandlerFactory;

    public function __construct(
        LoggerInterface $logger,
        Mapper $mapper,
        KafkaProducer $producer,
        HttpTransport $httpTransport,
        SftpTransportFactory $sftpTransportFactory,
        GoogleSheetsInputHandler $googleSheetsInputHandler,
        GoogleDriveFolderHandler $googleDriveFolderHandler,
        MorrisXmlSftpInputHandler $morrisXmlSftpInputHandler,
        RestApiHandlerFactory $restApiHandlerFactory,
    ) {
        $this->mapper = $mapper;
        $this->logger = $logger;
        $this->producer = $producer;
        $this->httpTransport = $httpTransport;
        $this->sftpTransportFactory = $sftpTransportFactory;
        $this->googleSheetsInputHandler = $googleSheetsInputHandler;
        $this->googleDriveFolderHandler = $googleDriveFolderHandler;
        $this->morrisXmlSftpInputHandler = $morrisXmlSftpInputHandler;
        $this->restApiHandlerFactory = $restApiHandlerFactory;
    }

    /**
     * @throws Exception
     */
    public function aggregate(array $inputData): void
    {
        // Build and validate config (allows type_id=null in multi-source mode)
        try {
            $config = new InputConfig($inputData);
        } catch (\Throwable $e) {
            $this->logger->error('Aggregation failed: invalid config', ['error' => $e->getMessage(), 'input' => $inputData]);
            throw $e;
        }

        if ($config->isMultiSource()) {
            $collection = $this->arraySourceProcessing($config);
        } else {
            $handler = $this->getHandlerByType($config->getTypeId(), $config->getSupplierId());
            if (!$handler) {
                $this->logger->error('Aggregation failed: No handler found.', ['type_id' => $config->getTypeId()]);
                throw new \InvalidArgumentException(sprintf('No handler found for type "%s".', $config->getTypeId()));
            }

            $collection = $handler->readData($config->getSource(), $config->getRange());
        }

        $finalCollection = $this->mapper->mapColumns($collection, $config->getColumnMapRules(), $config->getSupplierId(), $config->getVersion());
        foreach ($finalCollection->getRows() as $dataRow) {
            $this->producer->produce($dataRow);
        }
    }

    private function getHandlerByType(int $type_id, $supplier_id): ?InputHandlerInterface
    {
        // todo: add type for multi-source
        return match ($type_id) {
            1 => $this->googleSheetsInputHandler,
            2 => new CsvInputHandler($this->logger, $this->httpTransport), // HTTP + CSV
            3 => $this->googleDriveFolderHandler,
            4 => new ExcelInputHandler($this->logger, $this->httpTransport), // HTTP + Excel
            5 => $this->morrisXmlSftpInputHandler,
            6 => new ExcelInputHandler($this->logger, null, $this->sftpTransportFactory->create((string)$supplier_id)), // SFTP + Excel
            7 => new CsvInputHandler($this->logger, null, $this->sftpTransportFactory->create((string)$supplier_id)), // SFTP + CSV
            8 => $this->restApiHandlerFactory->create((string)$supplier_id),
            default => null
        };
    }

    /**
     * @throws Exception
     */
    private function arraySourceProcessing(InputConfig $config): DataSetCollection
    {
        $merged = null; // DataSetCollection
        $index = 0;

        foreach ($config->getSubSources() as $sub) {

            $handler = $this->getHandlerByType($sub->getTypeId(), $config->getSupplierId());
            if (!$handler) {
                $this->logger->error('Aggregation failed: No handler found for sub-source.', ['type_id' => $sub->getTypeId(), 'filename' => $sub->getFilename()]);
                throw new \InvalidArgumentException(sprintf('No handler found for type "%s".', $sub->getTypeId()));
            }

            $loaded = $handler->readData($sub->getFilename(), $sub->getRange() ?? $config->getRange());
            if ($index === 0) {
                $merged = DataSetCollection::createFromCollection($loaded, $sub->getKey());
            } else {
                $merged->addFieldsFromCollection($loaded, $sub->getKey(), $sub->getFields());
            }

            $index++;
        }

        if ($merged === null) {
            $merged = new DataSetCollection();
        }

        return $merged;
    }


}