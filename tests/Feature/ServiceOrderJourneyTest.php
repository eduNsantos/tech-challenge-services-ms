<?php

namespace Tests\Feature;

use App\Application\Messaging\DeadLetterRedriver;
use App\Application\Outbox\OutboxPublisher;
use App\Application\Telemetry\ServiceOrderJourney;
use App\Infrastructure\Telemetry\LoggerProviderFactory;
use App\Lambda\ServiceOrdersCreatedHandler;
use App\Lambda\ServiceOrderStatusChangedHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Bref\Context\Context;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use Tests\TestCase;

class ServiceOrderJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-with-at-least-32-bytes-long!!';

    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exporter = new InMemoryExporter;
        $provider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($this->exporter))
            ->build();

        $this->app->instance(LoggerProviderInterface::class, $provider);
        $this->app->forgetInstance(ServiceOrderJourney::class);
        config(['services.jwt.secret' => self::SECRET]);
    }

    /**
     * @return list<array<string, mixed>> atributos de cada passo, na ordem em que foram registrados
     */
    private function steps(): array
    {
        $steps = array_map(
            function (ReadableLogRecord $record) {
                $attributes = $record->getAttributes()->toArray();
                $this->assertSame($attributes['level'], $record->getSeverityText());
                unset($attributes['level']);

                return $attributes + ['severity' => $record->getSeverityText()];
            },
            iterator_to_array($this->exporter->getStorage()),
        );
        $this->exporter->getStorage()->exchangeArray([]);

        return $steps;
    }

    private function sqsEvent(array $body, string $queue): array
    {
        return ['Records' => [[
            'messageId' => 'msg-0',
            'receiptHandle' => 'handle-0',
            'body' => json_encode($body),
            'attributes' => ['ApproximateReceiveCount' => '1', 'MessageGroupId' => 'order-1'],
            'messageAttributes' => [],
            'eventSource' => 'aws:sqs',
            'eventSourceARN' => "arn:aws:sqs:us-east-1:123456789012:{$queue}",
            'awsRegion' => 'us-east-1',
        ]]];
    }

    private function context(): Context
    {
        return new Context('request-id', (int) (microtime(true) * 1000) + 60_000, 'arn:test', '');
    }

    private function receiveOrder(): void
    {
        app(ServiceOrdersCreatedHandler::class)->handle($this->sqsEvent([
            'event_id' => 'event-1',
            'type' => 'ServiceOrderCreated',
            'version' => 1,
            'sequence' => 1,
            'occurred_at' => '2026-10-29T10:00:00-03:00',
            'service_order' => [
                'id' => 'order-1', 'customer_id' => 'c', 'vehicle_id' => 'v',
                'status' => 'recebida', 'services_total' => 100.0,
            ],
            'services' => [['id' => 'item-1', 'service_id' => 's', 'name' => 'Óleo', 'price' => 100.0, 'quantity' => 1]],
        ], 'service-orders-created.fifo'), $this->context());
    }

    public function test_consumers_record_received_order_and_synchronized_status_with_the_order_id(): void
    {
        $this->receiveOrder();
        // Reentrega do mesmo evento: idempotente, não vira um segundo passo.
        $this->receiveOrder();

        app(ServiceOrderStatusChangedHandler::class)->handle($this->sqsEvent([
            'event_id' => 'event-2', 'type' => 'ServiceOrderStatusChanged', 'version' => 1,
            'sequence' => 2, 'service_order_id' => 'order-1', 'status' => 'em_diagnostico',
        ], 'service-order-status-changed.fifo'), $this->context());

        $this->assertSame([
            [
                'event' => 'service_order_received',
                'service_order_id' => 'order-1',
                'journey.source' => 'services-ms',
                'status' => 'recebida',
                'services_count' => 1,
                'event_id' => 'event-1',
                'severity' => 'INFO',
            ],
            [
                'event' => 'status_synchronized',
                'service_order_id' => 'order-1',
                'journey.source' => 'services-ms',
                'previous_status' => 'recebida',
                'status' => 'em_diagnostico',
                'sequence' => 2,
                'event_id' => 'event-2',
                'severity' => 'INFO',
            ],
        ], $this->steps());
    }

    public function test_execution_api_records_started_finished_and_rejected_steps(): void
    {
        $this->receiveOrder();
        DB::table('service_orders')->update(['status' => 'em_execucao', 'status_sequence' => 3]);
        $this->steps();

        $token = JWT::encode(['sub' => '42', 'exp' => time() + 600], self::SECRET, 'HS256');
        $headers = ['Authorization' => "Bearer {$token}"];
        $base = '/service-orders/order-1/services/item-1';

        $this->postJson($base.'/start', [], $headers)->assertOk();
        $this->postJson($base.'/start', [], $headers)->assertStatus(422);
        $this->travel(90)->seconds();
        $this->postJson($base.'/finish', [], $headers)->assertOk();

        $steps = $this->steps();

        $this->assertSame(
            ['service_started', 'execution_started', 'execution_rejected', 'service_finished', 'execution_finished'],
            array_column($steps, 'event'),
        );
        $this->assertSame(['order-1'], array_values(array_unique(array_column($steps, 'service_order_id'))));
        $this->assertSame('item-1', $steps[0]['service_order_service_id']);
        $this->assertSame('start', $steps[2]['operation']);
        $this->assertSame('The service was already started.', $steps[2]['reason']);
        $this->assertSame('WARN', $steps[2]['severity']);
        $this->assertEqualsWithDelta(90, $steps[3]['duration_seconds'], 1);
        $this->assertEqualsWithDelta(90, $steps[4]['duration_seconds'], 1);
    }

    public function test_outbox_publisher_records_published_and_failed_events(): void
    {
        config(['services.sqs.service_order_execution_url' => 'https://sqs.us-east-1.amazonaws.com/123/service-order-execution.fifo']);

        foreach (['event-a' => 'order-a', 'event-b' => 'order-b'] as $eventId => $group) {
            DB::table('outbox')->insert([
                'event_id' => $eventId, 'type' => 'ServiceOrderExecutionFinished', 'group_id' => $group,
                'payload' => '{}', 'created_at' => now()->subSeconds(5), 'updated_at' => now(),
            ]);
        }

        $client = Mockery::mock(SqsClient::class);
        $client->shouldReceive('sendMessage')->twice()->andReturnUsing(function (array $args) {
            if ($args['MessageDeduplicationId'] === 'event-b') {
                throw new \RuntimeException('SQS unavailable');
            }
        });

        app()->make(OutboxPublisher::class, ['client' => $client])->publishPending();

        $steps = $this->steps();

        $this->assertSame(['event_published', 'event_publish_failed'], array_column($steps, 'event'));
        $this->assertSame(['order-a', 'order-b'], array_column($steps, 'service_order_id'));
        $this->assertSame('ServiceOrderExecutionFinished', $steps[0]['event_type']);
        $this->assertSame('service-order-execution.fifo', $steps[0]['queue']);
        $this->assertEqualsWithDelta(5, $steps[0]['lag_seconds'], 1);
        $this->assertSame('RuntimeException', $steps[1]['error.type']);
        $this->assertSame('WARN', $steps[1]['severity']);
    }

    public function test_dead_letter_redriver_records_redriven_and_exhausted_messages(): void
    {
        $message = fn (string $id, string $group, int $attempt) => [
            'MessageId' => $id,
            'ReceiptHandle' => "handle-{$id}",
            'Body' => json_encode(['event_id' => "event-{$id}", 'type' => 'ServiceOrderExecutionFinished']),
            'Attributes' => ['MessageGroupId' => $group, 'MessageDeduplicationId' => "event-{$id}"],
            'MessageAttributes' => [
                DeadLetterRedriver::ATTEMPT_ATTRIBUTE => ['DataType' => 'Number', 'StringValue' => (string) $attempt],
            ],
        ];

        $client = Mockery::mock(SqsClient::class);
        $client->shouldReceive('receiveMessage')->andReturn(
            new Result(['Messages' => [$message('m1', 'order-1', 1), $message('m2', 'order-2', 4)]]),
            new Result(['Messages' => []]),
        );
        $client->shouldReceive('sendMessage')->once();
        $client->shouldReceive('deleteMessage')->once();
        $this->app->instance(SqsClient::class, $client);

        app(DeadLetterRedriver::class)->redrive(
            'https://sqs.us-east-1.amazonaws.com/1/services-ms-service-order-execution-dlq.fifo',
            'https://sqs.us-east-1.amazonaws.com/1/services-ms-service-order-execution.fifo',
            4,
            fn () => true,
        );

        $this->assertSame([
            [
                'event' => 'dead_letter_redriven',
                'service_order_id' => 'order-1',
                'journey.source' => 'services-ms',
                'queue' => 'services-ms-service-order-execution.fifo',
                'redrive_attempt' => 2,
                'event_type' => 'ServiceOrderExecutionFinished',
                'event_id' => 'event-m1',
                'severity' => 'WARN',
            ],
            [
                'event' => 'dead_letter_exhausted',
                'service_order_id' => 'order-2',
                'journey.source' => 'services-ms',
                'queue' => 'services-ms-service-order-execution.fifo',
                'redrive_attempt' => 4,
                'event_type' => 'ServiceOrderExecutionFinished',
                'event_id' => 'event-m2',
                'severity' => 'WARN',
            ],
        ], $this->steps());
    }

    public function test_factory_only_exports_logs_when_otlp_is_enabled(): void
    {
        $this->assertInstanceOf(NoopLoggerProvider::class, LoggerProviderFactory::create(['logs_exporter' => 'none']));
        $this->assertInstanceOf(NoopLoggerProvider::class, LoggerProviderFactory::create([]));
        $this->assertInstanceOf(LoggerProvider::class, LoggerProviderFactory::create([
            'logs_exporter' => 'otlp', 'endpoint' => 'http://127.0.0.1:1',
        ]));
    }

    public function test_export_failures_never_break_the_business_flow(): void
    {
        $logger = Mockery::mock(\OpenTelemetry\API\Logs\LoggerInterface::class);
        $logger->shouldReceive('emit')->once()->andThrow(new \RuntimeException('emit failed'));

        $provider = Mockery::mock(LoggerProviderInterface::class);
        $provider->shouldReceive('getLogger')->andReturn($logger);
        $provider->shouldReceive('forceFlush')->once()->andThrow(new \RuntimeException('collector down'));

        $journey = new ServiceOrderJourney($provider);
        $journey->record('service_started', 'order-1');
        $journey->flush();

        $this->addToAssertionCount(1);
    }
}
