<?php

namespace Tests\Unit;

use App\Domain\ServiceOrder\ServiceOrderService;
use App\Domain\ServiceOrder\ServiceOrderServiceContract;
use App\Application\ServiceOrder\CreateServiceOrderServiceUseCase;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class CreateServiceOrderServiceUseCaseTest extends TestCase
{
    public function test_execute_creates_service_order_service(): void
    {
        $repository = Mockery::mock(ServiceOrderServiceContract::class);

        $repository
            ->shouldReceive('save')
            ->once()
            ->withArgs(function (ServiceOrderService $serviceOrderService) {
                $this->assertSame('order-123', $serviceOrderService->serviceOrderId());
                $this->assertSame('service-123', $serviceOrderService->serviceId());
                $this->assertSame(2, $serviceOrderService->quantity());
                $this->assertSame(50.0, $serviceOrderService->price());
                $this->assertNotNull($serviceOrderService->startedAt());

                return true;
            })
            ->andReturnUsing(fn (ServiceOrderService $serviceOrderService) => $serviceOrderService);

        $useCase = new CreateServiceOrderServiceUseCase($repository);

        $serviceOrderService = $useCase->execute([
            'service_order_id' => 'order-123',
            'service_id' => 'service-123',
            'quantity' => 2,
            'price' => '50.00',
            'started_at' => '2026-10-28 10:00:00',
        ]);

        $this->assertInstanceOf(ServiceOrderService::class, $serviceOrderService);
        $this->assertSame('service-123', $serviceOrderService->serviceId());
    }

    public function test_execute_requires_service_id(): void
    {
        $repository = Mockery::mock(ServiceOrderServiceContract::class);
        $repository->shouldReceive('save')->never();

        $useCase = new CreateServiceOrderServiceUseCase($repository);

        $this->expectException(InvalidArgumentException::class);

        $useCase->execute([
            'service_order_id' => 'order-123',
            'service_id' => '',
            'quantity' => 1,
            'price' => 10,
        ]);
    }
}
