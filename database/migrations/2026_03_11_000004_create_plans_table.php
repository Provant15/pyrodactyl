<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('memory');
            $table->unsignedInteger('disk');
            $table->unsignedInteger('cpu');
            $table->unsignedInteger('io')->default(500);
            $table->integer('swap')->default(0);
            $table->boolean('oom_disabled')->default(true);
            $table->string('threads')->nullable();
            $table->unsignedInteger('databases_limit')->default(0);
            $table->unsignedInteger('backups_limit')->default(0);
            $table->unsignedInteger('allocations_limit')->default(0);
            $table->unsignedInteger('archive_limit')->default(3);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
