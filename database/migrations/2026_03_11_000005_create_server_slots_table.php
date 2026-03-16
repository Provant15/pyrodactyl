<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('server_slots', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('plan_id');
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('allocation_id');
            // Application-level reference, NOT a FK (avoids cyclic FK with servers.slot_id)
            $table->unsignedInteger('active_server_id')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->foreign('node_id')->references('id')->on('nodes');
            $table->foreign('allocation_id')->references('id')->on('allocations');
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
