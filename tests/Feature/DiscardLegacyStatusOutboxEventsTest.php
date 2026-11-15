<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscardLegacyStatusOutboxEventsTest extends TestCase
{
    use RefreshDatabase;

    private function insertEvent(string $eventId, string $type): void
    {
        DB::table('outbox')->insert([
            'event_id' => $eventId, 'type' => $type, 'group_id' => 'order-1',
            'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_marks_only_pending_legacy_status_events_as_published(): void
    {
        $this->insertEvent('a1f1b1c0-0000-4000-8000-000000000001', 'ServiceOrderStatusChanged');
        $this->insertEvent('a1f1b1c0-0000-4000-8000-000000000002', 'ServiceOrderExecutionFinished');

        $migration = require database_path('migrations/2026_11_15_000002_discard_legacy_status_outbox_events.php');
        $migration->up();

        $this->assertNotNull(DB::table('outbox')->where('type', 'ServiceOrderStatusChanged')->value('published_at'));
        $this->assertNull(DB::table('outbox')->where('type', 'ServiceOrderExecutionFinished')->value('published_at'));
    }
}
