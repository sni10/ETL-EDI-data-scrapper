<?php
// src/Service/Kafka/KafkaConsumer.php
namespace App\Service\Kafka;

use Exception;
use Psr\Log\LoggerInterface;
use RdKafka\KafkaConsumer as RdKafkaConsumer;
use RdKafka\Conf;
use RuntimeException;

class KafkaConsumer
{
    private RdKafkaConsumer $consumer;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $conf = new Conf();
        $conf->set('group.id', 'edi_input_scraper_consumer_group');
        $conf->set('metadata.broker.list', getenv('KAFKA_EDI_SERVER_HOST').':'.getenv('KAFKA_EDI_SERVER_PORT'));
        $conf->set('enable.auto.commit', 'true');
        $conf->set('auto.offset.reset', 'earliest');
        $this->consumer = new RdKafkaConsumer($conf);
        $this->consumer->subscribe(['edi_input']);
    }

    /**
     * Gets exactly ONE message from Kafka
     * @return array|null
     * @throws Exception
     */
    public function consume(): ?array
    {
        $waitTime = (int)(getenv('KAFKA_WAIT_MESSAGE_TIME') ?: 10000); // 10 секунд максимум
        $message = $this->consumer->consume($waitTime);

        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            $this->handleKafkaError($message);
            return null;
        }

        $data = json_decode($message->payload, true);
        if (!$data) {
            $this->logger->error('Invalid JSON payload');
            return null;
        }

        return $data;
    }

    private function handleKafkaError($message): void
    {
        switch ($message->err) {
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                break;

            case RD_KAFKA_RESP_ERR__UNKNOWN_PARTITION:
            case RD_KAFKA_RESP_ERR__UNKNOWN_TOPIC:
            case RD_KAFKA_RESP_ERR_BROKER_NOT_AVAILABLE:
                $this->logger->error("Kafka critical error: {$message->errstr()}. Service will exit.");
                throw new RuntimeException("Critical Kafka error: {$message->errstr()}", $message->err);

            default:
                $this->logger->error("Kafka error: {$message->errstr()}");
                break;
        }
    }
}