<?php
// src/Service/Kafka/KafkaProducer.php
namespace App\Service\Kafka;

use App\Model\DataRow;
use Exception;
use Psr\Log\LoggerInterface;
use RdKafka\Producer as RdKafkaProducer;
use RdKafka\Conf;

class KafkaProducer
{
    private RdKafkaProducer $producer;
    private string $topicName;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $conf = new Conf();
        $conf->set('metadata.broker.list', getenv('KAFKA_EDI_SERVER_HOST').':'.getenv('KAFKA_EDI_SERVER_PORT'));

        $this->producer = new RdKafkaProducer($conf);
        $this->topicName = 'edi_output';
        $this->logger = $logger;
    }

    /**
     * @throws Exception
     */
    public function produce(DataRow $dataRow): void
    {
        $topic = $this->producer->newTopic($this->topicName);
        $message = json_encode($dataRow->getFields());
        if ($message === false) {
            $this->logger->error("Kafka: Failed to encode data to JSON.");
            throw new Exception("Failed to encode data to JSON.");
        }

        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message);
        $this->producer->poll(0);
        $this->waitForDelivery();
    }

    /**
     * @throws Exception
     */
    private function waitForDelivery(): void
    {
        $retries = 5;
        $retryInterval = 500; // миллисекунды

        while ($this->producer->getOutQLen() > 0 && $retries > 0) {
            $this->producer->poll($retryInterval);
            $retries--;

            if ($retries <= 0) {
                $this->logger->error("Kafka: Message delivery failed after multiple retries.");
                throw new Exception("Failed to deliver message to Kafka after retries.");
            }
        }

        if ($this->producer->getOutQLen() > 0) {
            $this->logger->error("Kafka: Outgoing queue is not empty.");
            throw new Exception("Kafka producer failed to clear the outgoing queue.");
        }
    }
}
