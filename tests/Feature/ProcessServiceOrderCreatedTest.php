<?php

namespace Tests\Feature;

use App\Application\ServiceOrder\ProcessServiceOrderCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ProcessServiceOrderCreatedTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'event_id' => 'b1f1b1c0-0000-4000-8000-000000000001',
            'type' => 'ServiceOrderCreated',
            'version' => 1,
            'occurred_at' => '2026-10-29T10:00:00-03:00',
            'service_order' => [
                'id' => 'a1f1b1c0-0000-4000-8000-000000000010',
                'customer_id' => 'c1f1b1c0-0000-4000-8000-000000000020',
                'vehicle_id' => 'd1f1b1c0-0000-4000-8000-000000000030',
                'status' => 'recebida',
                'services_total' => 150.0,
            ],
            'services' => [
                ['id' => 'e1f1b1c0-0000-4000-8000-000000000040', 'service_id' => 'f1f1b1c0-0000-4000-8000-000000000050', 'name' => 'Troca de óleo', 'price' => 150.0, 'quantity' => 1],
            ],
        ], $override);
    }

    public function test_persists_order_and_services(): void
    {
        $processed = app(ProcessServiceOrderCreated::class)->handle($this->payload());

        $this->assertTrue($processed);
        $this->assertDatabaseHas('service_orders', ['id' => 'a1f1b1c0-0000-4000-8000-000000000010', 'status' => 'recebida']);
        $this->assertDatabaseHas('service_order_services', ['name' => 'Troca de óleo', 'quantity' => 1]);
        $this->assertDatabaseHas('processed_messages', ['event_id' => 'b1f1b1c0-0000-4000-8000-000000000001']);
    }

    public function test_duplicate_event_is_ignored(): void
    {
        $handler = app(ProcessServiceOrderCreated::class);

        $this->assertTrue($handler->handle($this->payload()));
        $this->assertFalse($handler->handle($this->payload()));

        $this->assertSame(1, DB::table('service_orders')->count());
        $this->assertSame(1, DB::table('service_order_services')->count());
    }

    public function test_invalid_payload_throws_and_persists_nothing(): void
    {
        try {
            app(ProcessServiceOrderCreated::class)->handle($this->payload(['services' => [['name' => '']]]));
            $this->fail('Expected exception');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('service_orders')->count());
            $this->assertSame(0, DB::table('processed_messages')->count());
        }
    }

    public function test_wrong_event_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProcessServiceOrderCreated::class)->handle($this->payload(['type' => 'Other']));
    }
}
