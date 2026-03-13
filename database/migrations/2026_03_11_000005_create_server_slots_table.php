<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('server_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->foreignId('node_id')->constrained('nodes');
            $table->foreignId('allocation_id')->constrained('allocations');
            // Application-level reference, NOT a FK (avoids cyclic FK with servers.slot_id)
            $table->unsignedBigInteger('active_server_id')->nullable();
            $table->string('label')->nullable();
            $table->unsignedInteger('memory_override')->nullable();
            $table->unsignedInteger('disk_override')->nullable();
            $table->unsignedInteger('cpu_override')->nullable();
            $table->unsignedInteger('io_override')->nullable();
            $table->integer('swap_override')->nullable();
            $table->string('status', 20)->default('idle');
            $table->timestamps();

            $table->index('user_id');
            $table->index('node_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_slots');
    }
};
