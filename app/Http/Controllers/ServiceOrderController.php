<?php

namespace App\Http\Controllers;

use App\Application\ServiceOrder\CreateServiceOrderUseCase;
use Illuminate\Http\Request;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly CreateServiceOrderUseCase $createServiceOrderUseCase,
    ) {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'string'],
            'vehicle_id' => ['required', 'string'],
            'services_total' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'in:recebida,em_diagnostico,aguardando_aprovacao,em_execucao,finalizada,entregue'],
            'creation_date' => ['sometimes', 'date'],
        ]);

        $serviceOrder = $this->createServiceOrderUseCase->execute($validated);

        return response()->json([
            'message' => 'Service order created successfully.',
            'data' => $serviceOrder->toArray(),
        ], 201);
    }
}
