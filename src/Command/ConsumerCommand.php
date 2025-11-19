<?php
// src/Command/ConsumerCommand.php
namespace App\Command;

use App\Service\Aggregator\Aggregator;
use App\Service\Kafka\KafkaConsumer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConsumerCommand extends Command
{
    private KafkaConsumer $kafkaConsumer;
    private Aggregator $aggregator;

    public function __construct(KafkaConsumer $kafkaConsumer, Aggregator $aggregator)
    {
        parent::__construct();
        $this->kafkaConsumer = $kafkaConsumer;
        $this->aggregator = $aggregator;
    }

    public static function getDefaultName(): string
    {
        return 'app:consume';
    }

    protected function configure(): void
    {
        $this->setDescription('Consumes ONE message from Kafka and processes it.');
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $startDateTime = date('Y-m-d H:i:s');

        try {
            // Получаем сообщение
            $messageData = $this->kafkaConsumer->consume();

            if ($messageData === null) {
                $output->writeln('INFO: No messages to process');
                $this->outputStats($output, 'No messages', $startTime, $startMemory);
                return Command::SUCCESS;
            }

            // Извлекаем информацию о поставщике из сообщения
            $supplierName = $this->getSupplierName($messageData);
            $supplierId = $this->getSupplierId($messageData);
            $messageSize = is_string($messageData) ? strlen($messageData) : (is_array($messageData) ? count($messageData) : 'unknown');
            $messageSizeFormatted = is_numeric($messageSize) ? $messageSize . ' B' : $messageSize;

            $output->writeln("INFO: Supplier ({$supplierName}) ({$supplierId}) parsing started - {$startDateTime}");

            // Обрабатываем через агрегатор
            $this->aggregator->aggregate($messageData);

            $this->outputStats($output, "Message processed (size: {$messageSizeFormatted})", $startTime, $startMemory);
            return Command::SUCCESS;

        } catch (\RuntimeException $e) {
            $this->outputStats($output, "Runtime error: {$e->getMessage()}", $startTime, $startMemory, true);
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->outputStats($output, "Error: {$e->getMessage()}", $startTime, $startMemory, true);
            return Command::FAILURE;
        }
    }

    private function getSupplierName($messageData): string
    {
        return $this->getFieldFromMessage($messageData, 'name');
    }

    private function getSupplierId($messageData): string
    {
        return (string)$this->getFieldFromMessage($messageData, 'supplier_id');
    }

    private function getFieldFromMessage($messageData, string $field)
    {
        if (is_array($messageData) && isset($messageData[$field])) {
            return $messageData[$field];
        }

        if (is_string($messageData)) {
            $decoded = json_decode($messageData, true);
            if ($decoded && isset($decoded[$field])) {
                return $decoded[$field];
            }
        }

        return 'unknown';
    }

    private function outputStats(OutputInterface $output, string $message, float $startTime, int $startMemory, bool $isError = false): void
    {
        $executionTime = round(microtime(true) - $startTime, 3);
        $memoryUsed = $this->formatBytes(memory_get_usage(true) - $startMemory);

        $status = $isError ? 'ERROR' : 'SUCCESS';
        $style = $isError ? 'error' : 'info';

        $output->writeln("{$status}: <{$style}>{$message}</{$style}> | Time: {$executionTime}s | Memory: {$memoryUsed}");
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 1) . ' ' . $units[$pow];
    }

}