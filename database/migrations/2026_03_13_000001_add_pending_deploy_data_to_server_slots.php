<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('server_slots', function (Blueprint $table) {
            $table->json('pending_deploy_data')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('server_slots', function (Blueprint $table) {
            $table->dropColumn('pending_deploy_data');
        });
    }
};
