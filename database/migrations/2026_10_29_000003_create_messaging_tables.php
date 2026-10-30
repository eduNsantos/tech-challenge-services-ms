<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('type', 80);
            $table->timestamp('processed_at')->useCurrent();
        });

        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('type', 80);
            $table->string('group_id', 36)->index();
            $table->json('payload');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
        Schema::dropIfExists('processed_messages');
    }
};
