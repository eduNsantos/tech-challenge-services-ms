<?php

namespace Tests\Feature;

use App\Application\Outbox\OutboxPublisher;
use App\Application\Telemetry\Metrics;
use App\Application\Telemetry\ServiceOrderJourney;
use App\Lambda\PublishOutboxHandler;
use App\Lambda\ServiceOrdersCreatedHandler;
use App\Lambda\ServiceOrderStatusChangedHandler;
use Aws\Sqs\SqsClient;
use Bref\Context\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LambdaHandlersTest extends TestCase
{
    use RefreshDatabase;

    private function sqsEvent(array $bodies): array
    {
        $records = [];

        foreach ($bodies as $i => $body) {
            $records[] = [
                'messageId' => "msg-{$i}",
                'receiptHandle' => "handle-{$i}",
                'body' => is_string($body) ? $body : json_encode($body),
                'attributes' => ['ApproximateReceiveCount' => '1', 'MessageGroupId' => 'order-1'],
                'messageAttributes' => [],
                'eventSource' => 'aws:sqs',
                'eventSourceARN' => 'arn:aws:sqs:us-east-1:123456789012:services-ms-test.fifo',
                'awsRegion' => 'us-east-1',
            ];
        }

        return ['Records' => $records];
    }

    private function context(int $remainingMs = 60_000): Context
    {
        return new Context('request-id', (int) (microtime(true) * 1000) + $remainingMs, 'arn:test', '');
    }

    private function createdPayload(): array
    {
        return [
            'event_id' => 'b1f1b1c0-0000-4000-8000-000000000001',
            'type' => 'ServiceOrderCreated',
            'version' => 1,
            'sequence' => 1,
            'occurred_at' => '2026-10-29T10:00:00-03:00',
            'service_order' => [
                'id' => 'order-1',
                'customer_id' => 'customer',
                'vehicle_id' => 'vehicle',
                'status' => 'recebida',
                'services_total' => 150.0,
            ],
            'services' => [
                ['id' => 'item-1', 'service_id' => 'service-1', 'name' => 'Troca de óleo', 'price' => 150.0, 'quantity' => 1],
            ],
        ];
    }

    private function statusPayload(int $sequence, string $eventId): array
    {
        return [
            'event_id' => $eventId,
            'type' => 'ServiceOrderStatusChanged',
            'version' => 1,
            'sequence' => $sequence,
            'service_order_id' => 'order-1',
            'status' => 'em_execucao',
        ];
    }

    public function test_created_handler_processes_the_batch(): void
    {
        $result = app(ServiceOrdersCreatedHandler::class)
            ->handle($this->sqsEvent([$this->createdPayload()]), $this->context());

        $this->assertNull($result);
        $this->assertDatabaseHas('service_orders', ['id' => 'order-1', 'status' => 'recebida', 'status_sequence' => 1]);
        $this->assertDatabaseHas('service_order_services', ['id' => 'item-1']);
    }

    public function test_created_handler_stops_at_first_failure_and_returns_the_rest(): void
    {
        $result = app(ServiceOrdersCreatedHandler::class)->handle($this->sqsEvent([
            'not-json',
            $this->createdPayload(),
        ]), $this->context());

        $this->assertSame(['batchItemFailures' => [
            ['itemIdentifier' => 'msg-0'],
            ['itemIdentifier' => 'msg-1'],
        ]], $result);
        $this->assertDatabaseMissing('service_orders', ['id' => 'order-1']);
    }

    public function test_created_handler_rejects_unknown_event_types(): void
    {
        $result = app(ServiceOrdersCreatedHandler::class)
            ->handle($this->sqsEvent([['type' => 'Unknown', 'event_id' => 'x']]), $this->context());

        $this->assertSame(['batchItemFailures' => [['itemIdentifier' => 'msg-0']]], $result);
    }

    public function test_status_handler_applies_events_and_retries_missing_orders(): void
    {
        $handler = app(ServiceOrderStatusChangedHandler::class);

        // O.S. ainda não importada: a mensagem volta para a fila.
        $this->assertSame(
            ['batchItemFailures' => [['itemIdentifier' => 'msg-0']]],
            $handler->handle($this->sqsEvent([$this->statusPayload(2, 'event-1')]), $this->context()),
        );

        app(ServiceOrdersCreatedHandler::class)
            ->handle($this->sqsEvent([$this->createdPayload()]), $this->context());

        $this->assertNull($handler->handle($this->sqsEvent([
            $this->statusPayload(2, 'event-1'),
            $this->statusPayload(2, 'event-1'),
        ]), $this->context()));
        $this->assertDatabaseHas('service_orders', ['id' => 'order-1', 'status' => 'em_execucao', 'status_sequence' => 2]);
    }

    private function publisher(SqsClient $client): OutboxPublisher
    {
        return app()->make(OutboxPublisher::class, ['client' => $client]);
    }

    private function outboxEvent(string $eventId, string $group, string $type = 'ServiceOrderExecutionStarted'): void
    {
        DB::table('outbox')->insert([
            'event_id' => $eventId,
            'type' => $type,
            'group_id' => $group,
            'payload' => json_encode(['event_id' => $eventId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_publisher_sends_pending_events_and_blocks_failed_groups(): void
    {
        config(['services.sqs.service_order_execution_url' => 'https://sqs.test/execution.fifo']);
        $this->outboxEvent('event-a1', 'group-a');
        $this->outboxEvent('event-b1', 'group-b');
        $this->outboxEvent('event-a2', 'group-a', 'ServiceOrderExecutionFinished');

        $client = Mockery::mock(SqsClient::class);
        $client->shouldReceive('sendMessage')->times(3)->andReturnUsing(function (array $args) {
            if ($args['MessageDeduplicationId'] === 'event-b1') {
                throw new RuntimeException('SQS unavailable');
            }

            $this->assertSame('https://sqs.test/execution.fifo', $args['QueueUrl']);
            $this->assertSame('group-a', $args['MessageGroupId']);

            return null;
        });

        $this->assertSame(2, $this->publisher($client)->publishPending());
        $this->assertSame(
            ['event-b1'],
            DB::table('outbox')->whereNull('published_at')->pluck('event_id')->all(),
        );
    }

    public function test_outbox_handler_stops_before_the_timeout(): void
    {
        config(['services.sqs.service_order_execution_url' => 'https://sqs.test/execution.fifo']);
        $this->outboxEvent('event-a1', 'group-a');

        $client = Mockery::mock(SqsClient::class);
        $client->shouldReceive('sendMessage')->once();

        $handler = new PublishOutboxHandler($this->publisher($client), app(Metrics::class), app(ServiceOrderJourney::class), sleepSeconds: 0);

        // 10,1s restantes: um único ciclo cabe antes da margem de segurança de 10s.
        $this->assertSame(['published' => 1], $handler->handle([], $this->context(10_100)));
        $this->assertSame(0, DB::table('outbox')->whereNull('published_at')->count());
    }
}
