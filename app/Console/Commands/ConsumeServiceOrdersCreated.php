<?php

namespace App\Console\Commands;

use App\Application\ServiceOrder\ProcessServiceOrderCreated;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

class ConsumeServiceOrdersCreated extends Command
{
    protected $signature = 'sqs:consume-service-orders {--once : Process a single poll and exit}';

    protected $description = 'Consume ServiceOrderCreated events from the SQS FIFO queue';

    public function handle(ProcessServiceOrderCreated $processor): int
    {
        $url = config('services.sqs.service_orders_created_url');

        if (! $url) {
            $this->error('SQS_SERVICE_ORDERS_CREATED_URL is not configured.');

            return self::FAILURE;
        }

        $client = $this->client();

        do {
            $result = $client->receiveMessage([
                'QueueUrl' => $url,
                'MaxNumberOfMessages' => 10,
                'WaitTimeSeconds' => 20,
            ]);

            foreach ($result->get('Messages') ?? [] as $message) {
                $this->process($client, $url, $processor, $message);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function process(SqsClient $client, string $url, ProcessServiceOrderCreated $processor, array $message): void
    {
        try {
            $payload = json_decode($message['Body'], true, 512, JSON_THROW_ON_ERROR);
            $processed = $processor->handle($payload);
            $this->info(($processed ? 'Processed ' : 'Duplicate ').($payload['event_id'] ?? ''));
        } catch (JsonException|InvalidArgumentException $e) {
            // Mensagem inválida nunca terá sucesso: não apaga, e o redrive a envia para a DLQ.
            logger()->error('Invalid ServiceOrderCreated message', ['error' => $e->getMessage(), 'message_id' => $message['MessageId'] ?? null]);

            return;
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $client->deleteMessage([
            'QueueUrl' => $url,
            'ReceiptHandle' => $message['ReceiptHandle'],
        ]);
    }

    private function client(): SqsClient
    {
        $config = [
            'version' => 'latest',
            'region' => config('services.sqs.region'),
        ];

        if ($endpoint = config('services.sqs.endpoint')) {
            $config['endpoint'] = $endpoint;
        }

        return new SqsClient($config);
    }
}
