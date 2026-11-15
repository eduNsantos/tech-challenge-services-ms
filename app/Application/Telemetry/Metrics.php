<?php

namespace App\Application\Telemetry;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use Throwable;

/**
 * Métricas de negócio e de integração do Services MS (OpenTelemetry).
 *
 * Equivalem às custom metrics/events que o monólito enviava ao New Relic pelo
 * agente PHP, agora como instrumentos OTel com atributos de baixa cardinalidade
 * (sem IDs de O.S., cliente ou veículo: esses ficam nos logs).
 */
class Metrics
{
    /** Buckets (s) para latência de request/mensagem. */
    private const LATENCY_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];

    /** Buckets (s) para execução de serviços: de 1 min a 1 dia. */
    private const EXECUTION_BUCKETS = [60, 300, 600, 900, 1800, 3600, 7200, 14400, 28800, 86400];

    /** Buckets (s) para o atraso da outbox até a publicação. */
    private const LAG_BUCKETS = [0.5, 1, 2, 5, 10, 30, 60, 120, 300, 600];

    private CounterInterface $ordersReceived;

    private CounterInterface $statusTransitions;

    private CounterInterface $servicesStarted;

    private CounterInterface $servicesFinished;

    private HistogramInterface $serviceDuration;

    private HistogramInterface $executionDuration;

    private CounterInterface $executionRejected;

    private HistogramInterface $messageProcessDuration;

    private CounterInterface $messagesSent;

    private HistogramInterface $outboxLag;

    private HistogramInterface $httpRequestDuration;

    public function __construct(private readonly MeterProviderInterface $provider)
    {
        $meter = $provider->getMeter('services-ms');

        $this->ordersReceived = $meter->createCounter(
            'service_order.received', '{service_order}', 'O.S. importadas do monólito (ServiceOrderCreated).',
        );
        $this->statusTransitions = $meter->createCounter(
            'service_order.status.transitions', '{transition}', 'Mudanças de status aplicadas no MS.',
        );
        $this->servicesStarted = $meter->createCounter(
            'service_order.service.started', '{service}', 'Serviços de uma O.S. iniciados pelo mecânico.',
        );
        $this->servicesFinished = $meter->createCounter(
            'service_order.service.finished', '{service}', 'Serviços de uma O.S. finalizados pelo mecânico.',
        );
        $this->serviceDuration = $meter->createHistogram(
            'service_order.service.duration', 's', 'Tempo entre o início e o fim de um serviço.',
            ['ExplicitBucketBoundaries' => self::EXECUTION_BUCKETS],
        );
        $this->executionDuration = $meter->createHistogram(
            'service_order.execution.duration', 's', 'Tempo entre o primeiro início e o último fim de serviço da O.S.',
            ['ExplicitBucketBoundaries' => self::EXECUTION_BUCKETS],
        );
        $this->executionRejected = $meter->createCounter(
            'service_order.execution.rejected', '{request}', 'Início/fim de serviço recusado por regra de negócio.',
        );
        $this->messageProcessDuration = $meter->createHistogram(
            'messaging.process.duration', 's', 'Duração do processamento de cada mensagem SQS consumida.',
            ['ExplicitBucketBoundaries' => self::LATENCY_BUCKETS],
        );
        $this->messagesSent = $meter->createCounter(
            'messaging.client.sent.messages', '{message}', 'Mensagens da outbox enviadas (ou com falha) ao SQS.',
        );
        $this->outboxLag = $meter->createHistogram(
            'outbox.publish.lag', 's', 'Tempo entre a gravação na outbox e a publicação no SQS.',
            ['ExplicitBucketBoundaries' => self::LAG_BUCKETS],
        );
        $this->httpRequestDuration = $meter->createHistogram(
            'http.server.request.duration', 's', 'Duração das requests HTTP da API.',
            ['ExplicitBucketBoundaries' => self::LATENCY_BUCKETS],
        );
    }

    public function orderReceived(string $status): void
    {
        $this->ordersReceived->add(1, ['service_order.status' => $status]);
    }

    public function statusChanged(string $previousStatus, string $status, string $eventType): void
    {
        $this->statusTransitions->add(1, [
            'service_order.previous_status' => $previousStatus,
            'service_order.status' => $status,
            'service_order.event_type' => $eventType,
        ]);
    }

    public function serviceStarted(): void
    {
        $this->servicesStarted->add(1);
    }

    public function serviceFinished(float $durationSeconds): void
    {
        $this->servicesFinished->add(1);
        $this->serviceDuration->record(max(0, $durationSeconds));
    }

    public function executionFinished(float $durationSeconds): void
    {
        $this->executionDuration->record(max(0, $durationSeconds));
    }

    public function executionRejected(string $operation): void
    {
        $this->executionRejected->add(1, ['service_order.operation' => $operation]);
    }

    /**
     * @param  string  $outcome  processed | skipped | invalid | failed
     */
    public function messageProcessed(string $queue, string $eventType, string $outcome, float $seconds, ?string $errorType = null): void
    {
        $this->messageProcessDuration->record($seconds, array_filter([
            'messaging.system' => 'aws_sqs',
            'messaging.operation.name' => 'process',
            'messaging.destination.name' => $queue,
            'service_order.event_type' => $eventType,
            'outcome' => $outcome,
            'error.type' => $errorType,
        ]));
    }

    public function messageSent(string $queue, string $eventType, ?string $errorType = null, ?float $lagSeconds = null): void
    {
        $attributes = array_filter([
            'messaging.system' => 'aws_sqs',
            'messaging.operation.name' => 'send',
            'messaging.destination.name' => $queue,
            'service_order.event_type' => $eventType,
            'error.type' => $errorType,
        ]);

        $this->messagesSent->add(1, $attributes);

        if ($errorType === null && $lagSeconds !== null) {
            $this->outboxLag->record(max(0, $lagSeconds), $attributes);
        }
    }

    public function httpRequest(string $method, ?string $route, int $statusCode, float $seconds): void
    {
        $this->httpRequestDuration->record($seconds, array_filter([
            'http.request.method' => $method,
            'http.route' => $route,
            'http.response.status_code' => $statusCode,
            'error.type' => $statusCode >= 500 ? (string) $statusCode : null,
        ]));
    }

    /**
     * Exporta o que foi medido. Chamado no fim da request (terminating) e de cada
     * invocação Lambda; falha de exportação nunca derruba o fluxo de negócio.
     */
    public function flush(): void
    {
        try {
            $this->provider->forceFlush();
        } catch (Throwable $e) {
            logger()->warning('OpenTelemetry metrics flush failed', ['error' => $e->getMessage()]);
        }
    }
}
