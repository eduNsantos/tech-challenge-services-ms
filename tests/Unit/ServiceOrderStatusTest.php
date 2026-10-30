<?php

namespace Tests\Unit;

use App\Domain\ServiceOrder\ServiceOrder;
use App\Domain\ServiceOrder\ServiceOrderStatus;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceOrderStatusTest extends TestCase
{
    public static function validTransitions(): array
    {
        return [
            ['recebida', 'em_diagnostico'],
            ['recebida', 'aguardando_aprovacao'],
            ['em_diagnostico', 'aguardando_aprovacao'],
            ['aguardando_aprovacao', 'em_execucao'],
            ['em_execucao', 'finalizada'],
            ['finalizada', 'entregue'],
        ];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transitions(string $from, string $to): void
    {
        $this->assertTrue(ServiceOrderStatus::from($from)->canTransitionTo(ServiceOrderStatus::from($to)));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $this->assertFalse(ServiceOrderStatus::Received->canTransitionTo(ServiceOrderStatus::InProgress));
        $this->assertFalse(ServiceOrderStatus::Finished->canTransitionTo(ServiceOrderStatus::InProgress));
        $this->assertSame([], ServiceOrderStatus::Delivered->allowedTransitions());
    }

    public function test_service_order_defaults_to_received_with_uuid(): void
    {
        $order = ServiceOrder::create('customer-1', 'vehicle-1', 100.0);

        $this->assertSame(ServiceOrderStatus::Received, $order->status());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $order->id());
    }

    public function test_service_order_transition_updates_status_or_throws(): void
    {
        $order = ServiceOrder::create('customer-1', 'vehicle-1', 100.0, 'aguardando_aprovacao');
        $order->transitionTo(ServiceOrderStatus::InProgress);
        $this->assertSame(ServiceOrderStatus::InProgress, $order->status());

        $this->expectException(DomainException::class);
        $order->transitionTo(ServiceOrderStatus::Received);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ServiceOrder::create('customer-1', 'vehicle-1', 10.0, 'OPEN');
    }
}
