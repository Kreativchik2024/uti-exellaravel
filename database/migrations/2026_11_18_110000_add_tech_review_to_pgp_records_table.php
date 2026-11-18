<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->text('tech_note')->nullable()->after('actions_taken');
            $table->boolean('tech_processed')->default(false)->after('tech_note');
            $table->foreignId('tech_updated_by')->nullable()->after('tech_processed')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('tech_updated_at')->nullable()->after('tech_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropForeign(['tech_updated_by']);
            $table->dropColumn(['tech_note', 'tech_processed', 'tech_updated_by', 'tech_updated_at']);
        });
    }
};
