<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('slot_id')->nullable()->after('backup_storage_limit');
            $table->foreign('slot_id')->references('id')->on('server_slots')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('installed_at');
            $table->string('archive_snapshot_id')->nullable()->after('archived_at');

            $table->index('slot_id');
            $table->index('status');
        });

        // Make allocation_id nullable so archived servers can release their allocation.
        // MariaDB requires dropping the FK before the unique index, then re-adding both.
        Schema::table('servers', function (Blueprint $table) {
            $table->dropForeign(['allocation_id']);
            $table->dropUnique(['allocation_id']);
            $table->unsignedInteger('allocation_id')->nullable()->change();
            $table->unique('allocation_id');
            $table->foreign('allocation_id')->references('id')->on('allocations');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('allocation_id')->nullable(false)->change();
            $table->unique('allocation_id');
            $table->dropIndex(['status']);
            $table->dropIndex(['slot_id']);
            $table->dropForeign(['slot_id']);
            $table->dropColumn(['slot_id', 'archived_at', 'archive_snapshot_id']);
        });
    }
};
