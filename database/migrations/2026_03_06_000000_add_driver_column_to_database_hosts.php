<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds a `driver` column to the `database_hosts` table to support
 * multiple database engines (MySQL, PostgreSQL) per host entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_hosts', function (Blueprint $table) {
            $table->string('driver', 10)->default('mysql')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('database_hosts', function (Blueprint $table) {
            $table->dropColumn('driver');
        });
    }
};
