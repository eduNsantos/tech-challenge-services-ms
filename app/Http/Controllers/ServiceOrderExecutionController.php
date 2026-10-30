<?php

namespace App\Http\Controllers;

use App\Application\Execution\ServiceOrderExecution;
use App\Domain\ServiceOrder\ServiceOrderStatus;
use App\Models\ServiceOrder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceOrderExecutionController extends Controller
{
    public function __construct(private readonly ServiceOrderExecution $execution)
    {
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond(ServiceOrder::query()->with('services')->findOrFail($id));
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(ServiceOrderStatus::class)],
        ]);

        return $this->guard(fn () => $this->execution->changeStatus($id, ServiceOrderStatus::from($data['status'])));
    }

    public function start(Request $request, string $id, string $serviceId): JsonResponse
    {
        return $this->guard(fn () => $this->execution->startService($id, $serviceId, $request->attributes->get('user_id')));
    }

    public function finish(Request $request, string $id, string $serviceId): JsonResponse
    {
        return $this->guard(fn () => $this->execution->finishService($id, $serviceId, $request->attributes->get('user_id')));
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $this->respond($action());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function respond(ServiceOrder $order): JsonResponse
    {
        return response()->json(['data' => $order->toArray() + ['services' => $order->services->toArray()]]);
    }
}
