<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // service_id e name são um snapshot do catálogo, que pertence ao monólito.
    public function up(): void
    {
        Schema::create('service_order_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_order_id');
            $table->foreign('service_order_id')->references('id')->on('service_orders')->onDelete('cascade');
            $table->uuid('service_id');
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('price', 10, 2);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('started_user_id', 36)->nullable();
            $table->string('finished_user_id', 36)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_services');
    }
};
