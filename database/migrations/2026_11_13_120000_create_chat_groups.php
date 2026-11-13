<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Групповые беседы чата: название + список участников.
 * Сообщение группы — chat_messages.group_id (recipient_id = NULL).
 * Отметка «прочитано» группы — chat_reads.group_id (peer_id = 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('chat_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('recipient_id')
                ->constrained('chat_groups')->cascadeOnDelete();
            $table->index(['group_id', 'id']);
        });

        // Отметки «прочитано» для групп: (user_id, peer_id = 0, group_id)
        Schema::table('chat_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id')->default(0)->after('peer_id');
            // Сначала новый уникальный индекс (на нём держится внешний ключ user_id), потом старый — долой
            $table->unique(['user_id', 'peer_id', 'group_id'], 'chat_reads_user_peer_group_unique');
        });
        Schema::table('chat_reads', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'peer_id']);
        });
    }

    public function down(): void
    {
        DB::table('chat_reads')->where('group_id', '!=', 0)->delete();
        Schema::table('chat_reads', function (Blueprint $table) {
            $table->unique(['user_id', 'peer_id']);
        });
        Schema::table('chat_reads', function (Blueprint $table) {
            $table->dropUnique('chat_reads_user_peer_group_unique');
            $table->dropColumn('group_id');
        });

        DB::table('chat_messages')->whereNotNull('group_id')->delete();
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'id']);
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::dropIfExists('chat_group_members');
        Schema::dropIfExists('chat_groups');
    }
};
