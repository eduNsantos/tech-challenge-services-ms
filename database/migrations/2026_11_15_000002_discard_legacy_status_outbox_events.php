<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// O status da O.S. passou a ser da aplicação. Eventos ServiceOrderStatusChanged
// pendentes no outbox do MS não podem mais ser publicados e bloqueariam o grupo
// da O.S. no OutboxPublisher; são marcados como publicados para liberar a fila.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('outbox')
            ->where('type', 'ServiceOrderStatusChanged')
            ->whereNull('published_at')
            ->update(['published_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Irreversível: os eventos descartados não são mais suportados.
    }
};
