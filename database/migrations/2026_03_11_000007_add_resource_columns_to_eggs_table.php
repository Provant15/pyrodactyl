<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->unsignedInteger('min_memory')->nullable()->after('force_outgoing_ip');
            $table->unsignedInteger('min_disk')->nullable()->after('min_memory');
            $table->unsignedInteger('min_cpu')->nullable()->after('min_disk');
            $table->unsignedInteger('default_memory')->nullable()->after('min_cpu');
            $table->unsignedInteger('default_disk')->nullable()->after('default_memory');
            $table->unsignedInteger('default_cpu')->nullable()->after('default_disk');
            $table->json('archive_excludes')->nullable()->after('default_cpu');
        });
    }

    public function down(): void
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->dropColumn([
                'min_memory', 'min_disk', 'min_cpu',
                'default_memory', 'default_disk', 'default_cpu',
                'archive_excludes',
            ]);
        });
    }
};
