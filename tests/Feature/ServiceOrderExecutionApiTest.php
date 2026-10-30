<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceOrderExecutionApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-with-at-least-32-bytes-long!!';
    private const ORDER = 'a1f1b1c0-0000-4000-8000-000000000010';
    private const SVC_1 = 'e1f1b1c0-0000-4000-8000-000000000041';
    private const SVC_2 = 'e1f1b1c0-0000-4000-8000-000000000042';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.jwt.secret' => self::SECRET]);
    }

    private function seedOrder(string $status = 'aguardando_aprovacao'): void
    {
        DB::table('service_orders')->insert([
            'id' => self::ORDER, 'customer_id' => 'c', 'vehicle_id' => 'v', 'status' => $status,
            'services_total' => 200, 'creation_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([self::SVC_1, self::SVC_2] as $id) {
            DB::table('service_order_services')->insert([
                'id' => $id, 'service_order_id' => self::ORDER, 'service_id' => 's', 'name' => 'n',
                'quantity' => 1, 'price' => 100, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function auth(): array
    {
        $token = JWT::encode(['sub' => '42', 'exp' => time() + 600], self::SECRET, 'HS256');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_requires_valid_jwt(): void
    {
        $this->seedOrder();

        $this->getJson('/service-orders/'.self::ORDER)->assertUnauthorized();

        $expired = JWT::encode(['sub' => '1', 'exp' => time() - 10], self::SECRET, 'HS256');
        $this->getJson('/service-orders/'.self::ORDER, ['Authorization' => "Bearer {$expired}"])->assertUnauthorized();

        $wrong = JWT::encode(['sub' => '1'], 'another-secret-with-at-least-32-bytes!!', 'HS256');
        $this->getJson('/service-orders/'.self::ORDER, ['Authorization' => "Bearer {$wrong}"])->assertUnauthorized();
    }

    public function test_show_returns_order_with_services(): void
    {
        $this->seedOrder();

        $this->getJson('/service-orders/'.self::ORDER, $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'aguardando_aprovacao')
            ->assertJsonCount(2, 'data.services');
    }

    public function test_show_returns_404_for_unknown_order(): void
    {
        $this->getJson('/service-orders/missing', $this->auth())->assertNotFound();
    }

    public function test_first_start_moves_order_to_in_progress_and_writes_outbox(): void
    {
        $this->seedOrder();

        $this->postJson('/service-orders/'.self::ORDER.'/services/'.self::SVC_1.'/start', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'em_execucao');

        $this->assertDatabaseHas('service_order_services', ['id' => self::SVC_1, 'started_user_id' => '42']);

        $event = json_decode(DB::table('outbox')->first()->payload, true);
        $this->assertSame('ServiceOrderStatusChanged', $event['type']);
        $this->assertSame('aguardando_aprovacao', $event['previous_status']);
        $this->assertSame('em_execucao', $event['status']);
    }

    public function test_cannot_start_service_before_approval(): void
    {
        $this->seedOrder('em_diagnostico');

        $this->postJson('/service-orders/'.self::ORDER.'/services/'.self::SVC_1.'/start', [], $this->auth())
            ->assertStatus(422);

        $this->assertSame(0, DB::table('outbox')->count());
    }

    public function test_cannot_start_twice_or_finish_without_start(): void
    {
        $this->seedOrder();
        $base = '/service-orders/'.self::ORDER.'/services/'.self::SVC_1;

        $this->postJson($base.'/finish', [], $this->auth())->assertStatus(422);
        $this->postJson($base.'/start', [], $this->auth())->assertOk();
        $this->postJson($base.'/start', [], $this->auth())->assertStatus(422);
    }

    public function test_finishing_all_services_finishes_the_order(): void
    {
        $this->seedOrder();
        $order = '/service-orders/'.self::ORDER.'/services/';

        foreach ([self::SVC_1, self::SVC_2] as $id) {
            $this->postJson($order.$id.'/start', [], $this->auth())->assertOk();
        }

        $this->postJson($order.self::SVC_1.'/finish', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'em_execucao');

        $this->postJson($order.self::SVC_2.'/finish', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'finalizada');

        $this->assertSame(2, DB::table('outbox')->count());
    }

    public function test_patch_status_validates_state_machine(): void
    {
        $this->seedOrder('finalizada');

        $this->patchJson('/service-orders/'.self::ORDER.'/status', ['status' => 'recebida'], $this->auth())
            ->assertStatus(422);
        $this->patchJson('/service-orders/'.self::ORDER.'/status', ['status' => 'invalid'], $this->auth())
            ->assertStatus(422);
        $this->patchJson('/service-orders/'.self::ORDER.'/status', ['status' => 'entregue'], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'entregue');
    }
}
