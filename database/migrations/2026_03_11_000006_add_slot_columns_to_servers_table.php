<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable()->after('backup_storage_limit')
                ->constrained('server_slots')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('installed_at');
            $table->string('archive_snapshot_id')->nullable()->after('archived_at');

            $table->index('slot_id');
            $table->index('status');
        });

        // Make allocation_id nullable so archived servers can release their allocation.
        // Replace the full unique constraint with a partial unique index to allow
        // multiple archived servers to have NULL allocation_id.
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('allocation_id')->nullable()->change();
            $table->dropUnique(['allocation_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX servers_allocation_id_unique ON servers (allocation_id) WHERE allocation_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // Restore original unique index
        DB::statement('DROP INDEX IF EXISTS servers_allocation_id_unique');

        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('allocation_id')->nullable(false)->change();
            $table->unique('allocation_id');
            $table->dropIndex(['status']);
            $table->dropIndex(['slot_id']);
            $table->dropForeign(['slot_id']);
            $table->dropColumn(['slot_id', 'archived_at', 'archive_snapshot_id']);
        });
    }
};
