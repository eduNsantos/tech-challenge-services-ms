<?php

namespace Tests\Feature;

use App\Application\ServiceOrder\ProcessServiceOrderStatusChanged;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessServiceOrderStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $sequence, string $eventId): array
    {
        return [
            'event_id' => $eventId,
            'type' => 'ServiceOrderStatusChanged',
            'version' => 1,
            'sequence' => $sequence,
            'service_order_id' => 'order-1',
            'status' => 'em_execucao',
        ];
    }

    public function test_applies_once_and_ignores_older_events(): void
    {
        DB::table('service_orders')->insert([
            'id' => 'order-1', 'customer_id' => 'customer', 'vehicle_id' => 'vehicle',
            'status' => 'aguardando_aprovacao', 'status_sequence' => 1,
            'services_total' => 0, 'creation_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $processor = app(ProcessServiceOrderStatusChanged::class);
        $event = $this->payload(3, 'event-1');

        $this->assertTrue($processor->handle($event));
        $this->assertFalse($processor->handle($event));
        $old = $this->payload(2, 'event-2');
        $old['status'] = 'em_diagnostico';
        $this->assertFalse($processor->handle($old));
        $this->assertDatabaseHas('service_orders', [
            'id' => 'order-1', 'status' => 'em_execucao', 'status_sequence' => 3,
        ]);
        $this->assertSame(0, DB::table('outbox')->count());
    }

    public function test_missing_order_is_retried_without_recording_the_message(): void
    {
        try {
            app(ProcessServiceOrderStatusChanged::class)->handle($this->payload(2, 'event-1'));
            $this->fail('Expected missing order.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('processed_messages', ['event_id' => 'event-1']);
        }
    }
}
