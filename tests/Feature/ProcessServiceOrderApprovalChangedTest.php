<?php

namespace Tests\Feature;

use App\Application\ServiceOrder\ProcessServiceOrderApprovalChanged;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ProcessServiceOrderApprovalChangedTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = 'a1f1b1c0-0000-4000-8000-000000000010';

    private function seedOrder(): void
    {
        DB::table('service_orders')->insert([
            'id' => self::ORDER_ID,
            'customer_id' => 'c1f1b1c0-0000-4000-8000-000000000020',
            'vehicle_id' => 'd1f1b1c0-0000-4000-8000-000000000030',
            'status' => 'aguardando_aprovacao',
            'services_total' => 100,
            'creation_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(string $status, array $overrides = []): array
    {
        return array_replace([
            'event_id' => 'b1f1b1c0-0000-4000-8000-000000000001',
            'type' => 'ServiceOrderApprovalChanged',
            'version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'service_order_id' => self::ORDER_ID,
            'status' => $status,
            'previous_status' => 'aguardando_aprovacao',
        ], $overrides);
    }

    public function test_approval_moves_order_to_in_progress_and_writes_status_outbox(): void
    {
        $this->seedOrder();

        $this->assertTrue(app(ProcessServiceOrderApprovalChanged::class)->handle($this->payload('em_execucao')));
        $this->assertDatabaseHas('service_orders', ['id' => self::ORDER_ID, 'status' => 'em_execucao']);

        $event = json_decode(DB::table('outbox')->first()->payload, true);
        $this->assertSame('ServiceOrderStatusChanged', $event['type']);
        $this->assertSame('aguardando_aprovacao', $event['previous_status']);
        $this->assertSame('em_execucao', $event['status']);
    }

    public function test_rejection_moves_order_to_diagnosis(): void
    {
        $this->seedOrder();

        $this->assertTrue(app(ProcessServiceOrderApprovalChanged::class)->handle($this->payload('em_diagnostico')));
        $this->assertDatabaseHas('service_orders', ['id' => self::ORDER_ID, 'status' => 'em_diagnostico']);
    }

    public function test_duplicate_approval_event_is_ignored(): void
    {
        $this->seedOrder();
        $processor = app(ProcessServiceOrderApprovalChanged::class);
        $payload = $this->payload('em_execucao');

        $this->assertTrue($processor->handle($payload));
        $this->assertFalse($processor->handle($payload));
        $this->assertSame(1, DB::table('outbox')->count());
    }

    public function test_stale_transition_is_ignored(): void
    {
        $this->seedOrder();
        DB::table('service_orders')->where('id', self::ORDER_ID)->update(['status' => 'em_execucao']);

        $this->assertFalse(app(ProcessServiceOrderApprovalChanged::class)->handle($this->payload(
            'em_diagnostico',
            ['event_id' => 'b1f1b1c0-0000-4000-8000-000000000002'],
        )));
        $this->assertSame(0, DB::table('outbox')->count());
    }

    public function test_missing_order_fails_for_sqs_redelivery(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(ProcessServiceOrderApprovalChanged::class)->handle($this->payload('em_execucao'));
    }

    public function test_unsupported_transition_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProcessServiceOrderApprovalChanged::class)->handle($this->payload('finalizada'));
    }

    public function test_event_requires_a_valid_occurrence_time(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProcessServiceOrderApprovalChanged::class)->handle($this->payload(
            'em_execucao',
            ['occurred_at' => 'not-a-date'],
        ));
    }
}
