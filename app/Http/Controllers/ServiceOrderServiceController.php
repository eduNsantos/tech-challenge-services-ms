<?php

namespace App\Http\Controllers;

use App\Application\ServiceOrder\CreateServiceOrderServiceUseCase;
use Illuminate\Http\Request;

class ServiceOrderServiceController extends Controller
{
    public function __construct(
        private readonly CreateServiceOrderServiceUseCase $createServiceOrderServiceUseCase,
    ) {
    }

    public function store(Request $request)
    {
        $request->merge([
            'service_order_id' => $request->route('serviceOrderId') ?? $request->input('service_order_id'),
        ]);

        $validated = $request->validate([
            'service_order_id' => ['required', 'string'],
            'service_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'started_at' => ['sometimes', 'nullable', 'date'],
            'finished_at' => ['sometimes', 'nullable', 'date'],
            'started_user_id' => ['sometimes', 'nullable', 'string'],
            'finished_user_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $serviceOrderService = $this->createServiceOrderServiceUseCase->execute($validated);

        return response()->json([
            'message' => 'Service added to the service order successfully.',
            'data' => $serviceOrderService->toArray(),
        ], 201);
    }
}
