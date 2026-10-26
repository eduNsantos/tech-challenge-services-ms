<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('customer_id');
            $table->uuid('vehicle_id');
            $table->string('status', 40);
            $table->decimal('services_total', 10, 2);
            $table->decimal('parts_total', 10, 2);
            $table->decimal('total_budget', 10, 2);
            $table->dateTime('creation_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
