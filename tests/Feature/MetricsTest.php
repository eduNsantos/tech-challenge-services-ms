<?php

namespace Tests\Feature;

use App\Application\Outbox\OutboxPublisher;
use App\Application\Telemetry\Metrics;
use App\Infrastructure\Telemetry\MeterProviderFactory;
use App\Lambda\ServiceOrdersCreatedHandler;
use App\Lambda\ServiceOrderStatusChangedHandler;
use Aws\Sqs\SqsClient;
use Bref\Context\Context;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-with-at-least-32-bytes-long!!';

    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exporter = new InMemoryExporter(temporality: Temporality::DELTA);
        $provider = MeterProvider::builder()->addReader(new ExportingReader($this->exporter))->build();

        $this->app->instance(MeterProviderInterface::class, $provider);
        $this->app->forgetInstance(Metrics::class);
        config(['services.jwt.secret' => self::SECRET]);
    }

    /**
     * @return array<string, list<array{value: int|float, count: int|null, attributes: array}>>
     */
    private function exported(): array
    {
        app(Metrics::class)->flush();
        $points = [];

        foreach ($this->exporter->collect(true) as $metric) {
            foreach ($metric->data->dataPoints as $point) {
                $points[$metric->name][] = [
                    'value' => $point->value ?? $point->sum,
                    'count' => $point->count ?? null,
                    'attributes' => $point->attributes->toArray(),
                ];
            }
        }

        return $points;
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
            'event_id' => 'b1f1b1c0-0000-4000-8000-000000000001',
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

    public function test_consumers_record_received_orders_status_transitions_and_processing(): void
    {
        $this->receiveOrder();

        app(ServiceOrderStatusChangedHandler::class)->handle($this->sqsEvent([
            'event_id' => 'event-2', 'type' => 'ServiceOrderStatusChanged', 'version' => 1,
            'sequence' => 2, 'service_order_id' => 'order-1', 'status' => 'em_diagnostico',
        ], 'service-order-status-changed.fifo'), $this->context());

        app(ServiceOrderStatusChangedHandler::class)->handle(
            $this->sqsEvent(['type' => 'Forged-'.uniqid(), 'event_id' => 'x'], 'service-order-status-changed.fifo'),
            $this->context(),
        );

        $metrics = $this->exported();

        $this->assertSame(1, $metrics['service_order.received'][0]['value']);
        $this->assertSame(['service_order.status' => 'recebida'], $metrics['service_order.received'][0]['attributes']);

        $this->assertSame([
            'service_order.previous_status' => 'recebida',
            'service_order.status' => 'em_diagnostico',
            'service_order.event_type' => 'ServiceOrderStatusChanged',
        ], $metrics['service_order.status.transitions'][0]['attributes']);

        $processed = array_column($metrics['messaging.process.duration'], 'attributes');
        $this->assertContains([
            'messaging.system' => 'aws_sqs',
            'messaging.operation.name' => 'process',
            'messaging.destination.name' => 'service-orders-created.fifo',
            'service_order.event_type' => 'ServiceOrderCreated',
            'outcome' => 'processed',
        ], $processed);
        // Tipo vindo da mensagem não vira valor de atributo: evita explosão de cardinalidade.
        $this->assertContains([
            'messaging.system' => 'aws_sqs',
            'messaging.operation.name' => 'process',
            'messaging.destination.name' => 'service-order-status-changed.fifo',
            'service_order.event_type' => 'unknown',
            'outcome' => 'invalid',
            'error.type' => 'InvalidArgumentException',
        ], $processed);
    }

    public function test_execution_api_records_services_durations_rejections_and_http_requests(): void
    {
        $this->receiveOrder();
        DB::table('service_orders')->update(['status' => 'em_execucao', 'status_sequence' => 3]);
        $this->exported();

        $token = JWT::encode(['sub' => '42', 'exp' => time() + 600], self::SECRET, 'HS256');
        $headers = ['Authorization' => "Bearer {$token}"];
        $base = '/service-orders/order-1/services/item-1';

        $this->postJson($base.'/start', [], $headers)->assertOk();
        $this->postJson($base.'/start', [], $headers)->assertStatus(422);
        $this->travel(90)->seconds();
        $this->postJson($base.'/finish', [], $headers)->assertOk();

        $metrics = $this->exported();

        $this->assertSame(1, $metrics['service_order.service.started'][0]['value']);
        $this->assertSame(1, $metrics['service_order.service.finished'][0]['value']);
        $this->assertEqualsWithDelta(90, $metrics['service_order.service.duration'][0]['value'], 1);
        $this->assertEqualsWithDelta(90, $metrics['service_order.execution.duration'][0]['value'], 1);
        $this->assertSame(['service_order.operation' => 'start'], $metrics['service_order.execution.rejected'][0]['attributes']);

        $routes = array_column($metrics['http.server.request.duration'], 'attributes');
        $this->assertContains([
            'http.request.method' => 'POST',
            'http.route' => 'service-orders/{id}/services/{serviceId}/start',
            'http.response.status_code' => 422,
        ], $routes);
    }

    public function test_outbox_publisher_records_sent_messages_lag_and_failures(): void
    {
        config(['services.sqs.service_order_execution_url' => 'https://sqs.us-east-1.amazonaws.com/123/service-order-execution.fifo']);

        foreach (['event-a' => 'group-a', 'event-b' => 'group-b'] as $eventId => $group) {
            DB::table('outbox')->insert([
                'event_id' => $eventId, 'type' => 'ServiceOrderExecutionStarted', 'group_id' => $group,
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

        $metrics = $this->exported();
        $sent = array_column($metrics['messaging.client.sent.messages'], 'attributes');

        $this->assertContains([
            'messaging.system' => 'aws_sqs',
            'messaging.operation.name' => 'send',
            'messaging.destination.name' => 'service-order-execution.fifo',
            'service_order.event_type' => 'ServiceOrderExecutionStarted',
        ], $sent);
        $this->assertContains([
            'messaging.system' => 'aws_sqs',
            'messaging.operation.name' => 'send',
            'messaging.destination.name' => 'service-order-execution.fifo',
            'service_order.event_type' => 'ServiceOrderExecutionStarted',
            'error.type' => 'RuntimeException',
        ], $sent);
        $this->assertSame(1, $metrics['outbox.publish.lag'][0]['count']);
        $this->assertEqualsWithDelta(5, $metrics['outbox.publish.lag'][0]['value'], 1);
    }

    public function test_factory_only_exports_when_otlp_is_enabled(): void
    {
        $this->assertInstanceOf(NoopMeterProvider::class, MeterProviderFactory::create(['exporter' => 'none']));
        $this->assertInstanceOf(MeterProvider::class, MeterProviderFactory::create([
            'exporter' => 'otlp', 'endpoint' => 'http://127.0.0.1:1',
        ]));

        $resource = MeterProviderFactory::resource(['service_name' => 'services-ms', 'service_namespace' => 'tech-challenge'])
            ->getAttributes()->toArray();

        $this->assertSame('services-ms', $resource['service.name']);
        $this->assertSame('tech-challenge', $resource['service.namespace']);
        $this->assertSame(config('app.env'), $resource['deployment.environment.name']);
    }
}
