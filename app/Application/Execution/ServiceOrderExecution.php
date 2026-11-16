<?php

namespace App\Application\Execution;

use App\Application\Telemetry\Metrics;
use App\Application\Telemetry\ServiceOrderJourney;
use App\Domain\ServiceOrder\ServiceOrderStatus;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceOrderExecution
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly ServiceOrderJourney $journey,
    ) {}

    public function startService(string $orderId, string $serviceId, string $userId): ServiceOrder
    {
        $firstStart = false;

        $order = DB::transaction(function () use ($orderId, $serviceId, $userId, &$firstStart) {
            $order = $this->lockOrder($orderId);
            $service = $this->findService($order, $serviceId);

            if ($service->started_at !== null) {
                throw new DomainException('The service was already started.');
            }

            $status = ServiceOrderStatus::from($order->status);

            if ($status !== ServiceOrderStatus::InProgress) {
                throw new DomainException("Cannot start a service while the order is {$status->value}.");
            }

            $firstStart = ! $order->services()->whereNotNull('started_at')->exists();
            $startedAt = now();
            $service->update(['started_at' => $startedAt, 'started_user_id' => $userId]);

            // Todo início vai para a fila; a aplicação atualiza o serviço do lado dela.
            $this->recordEvent($order, 'ServiceOrderServiceStarted', [
                'service_order_service_id' => $service->id,
                'started_at' => $startedAt->toIso8601String(),
                'user_id' => $userId,
            ]);

            if ($firstStart) {
                $this->recordEvent($order, 'ServiceOrderExecutionStarted');
            }

            return $order->fresh('services');
        });

        // Só depois do commit: um rollback não pode virar métrica nem passo da jornada.
        $this->metrics->serviceStarted();
        $this->journey->record('service_started', $orderId, ['service_order_service_id' => $serviceId]);

        if ($firstStart) {
            $this->journey->record('execution_started', $orderId);
        }

        return $order;
    }

    public function finishService(string $orderId, string $serviceId, string $userId): ServiceOrder
    {
        $serviceSeconds = 0.0;
        $executionSeconds = null;

        $order = DB::transaction(function () use ($orderId, $serviceId, $userId, &$serviceSeconds, &$executionSeconds) {
            $order = $this->lockOrder($orderId);
            $service = $this->findService($order, $serviceId);

            if ($service->started_at === null) {
                throw new DomainException('The service was not started.');
            }

            if ($service->finished_at !== null) {
                throw new DomainException('The service was already finished.');
            }

            $finishedAt = now();
            $service->update(['finished_at' => $finishedAt, 'finished_user_id' => $userId]);
            $serviceSeconds = (float) $service->started_at->diffInSeconds($finishedAt);

            $this->recordEvent($order, 'ServiceOrderServiceFinished', [
                'service_order_service_id' => $service->id,
                'finished_at' => $finishedAt->toIso8601String(),
                'user_id' => $userId,
            ]);

            if (! $order->services()->whereNull('finished_at')->exists()) {
                $this->recordEvent($order, 'ServiceOrderExecutionFinished');

                $firstStartedAt = $order->services()->min('started_at');
                $executionSeconds = (float) Carbon::parse($firstStartedAt)->diffInSeconds($finishedAt);
            }

            return $order->fresh('services');
        });

        $this->metrics->serviceFinished($serviceSeconds);
        $this->journey->record('service_finished', $orderId, [
            'service_order_service_id' => $serviceId,
            'duration_seconds' => $serviceSeconds,
        ]);

        if ($executionSeconds !== null) {
            $this->metrics->executionFinished($executionSeconds);
            $this->journey->record('execution_finished', $orderId, ['duration_seconds' => $executionSeconds]);
        }

        return $order;
    }

    private function lockOrder(string $orderId): ServiceOrder
    {
        return ServiceOrder::query()->lockForUpdate()->findOrFail($orderId);
    }

    private function findService(ServiceOrder $order, string $serviceId): ServiceOrderService
    {
        $service = $order->services()->where('id', $serviceId)->first();

        if ($service === null) {
            throw (new ModelNotFoundException())->setModel(ServiceOrderService::class, [$serviceId]);
        }

        return $service;
    }

    /**
     * Grava o evento no outbox na mesma transação da mudança. Todos usam o id da O.S. como grupo FIFO,
     * então a aplicação recebe início/fim de serviço e de execução na ordem em que aconteceram.
     */
    private function recordEvent(ServiceOrder $order, string $type, array $data = []): void
    {
        if ($order->status_sequence === null) {
            throw new DomainException('Service order status has not been synchronized from the application.');
        }

        $eventId = Str::uuid()->toString();

        DB::table('outbox')->insert([
            'event_id' => $eventId,
            'type' => $type,
            'group_id' => $order->id,
            'payload' => json_encode([
                'event_id' => $eventId,
                'type' => $type,
                'version' => 1,
                'occurred_at' => now()->toIso8601String(),
                'service_order_id' => $order->id,
                'status_sequence' => (int) $order->status_sequence,
            ] + $data, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
