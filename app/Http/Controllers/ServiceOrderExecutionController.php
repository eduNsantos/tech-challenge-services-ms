<?php

namespace App\Http\Controllers;

use App\Application\Execution\ServiceOrderExecution;
use App\Application\Telemetry\Metrics;
use App\Application\Telemetry\ServiceOrderJourney;
use App\Models\ServiceOrder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceOrderExecutionController extends Controller
{
    public function __construct(
        private readonly ServiceOrderExecution $execution,
        private readonly Metrics $metrics,
        private readonly ServiceOrderJourney $journey,
    ) {
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond(ServiceOrder::query()->with('services')->findOrFail($id));
    }

    public function start(Request $request, string $id, string $serviceId): JsonResponse
    {
        return $this->guard('start', $id, fn () => $this->execution->startService($id, $serviceId, $request->attributes->get('user_id')));
    }

    public function finish(Request $request, string $id, string $serviceId): JsonResponse
    {
        return $this->guard('finish', $id, fn () => $this->execution->finishService($id, $serviceId, $request->attributes->get('user_id')));
    }

    private function guard(string $operation, string $orderId, callable $action): JsonResponse
    {
        try {
            return $this->respond($action());
        } catch (DomainException $e) {
            $this->metrics->executionRejected($operation);
            $this->journey->record('execution_rejected', $orderId, [
                'operation' => $operation,
                'reason' => $e->getMessage(),
            ], warning: true);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function respond(ServiceOrder $order): JsonResponse
    {
        return response()->json(['data' => $order->toArray() + ['services' => $order->services->toArray()]]);
    }
}
