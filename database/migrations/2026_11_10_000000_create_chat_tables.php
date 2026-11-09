<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Сообщения: recipient_id = NULL — общий канал, иначе личное сообщение
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_id', 'id']);            // общий канал и «входящие»
            $table->index(['sender_id', 'recipient_id', 'id']); // переписка двоих
        });

        // Докуда прочитано: peer_id = 0 — общий канал, иначе id собеседника
        Schema::create('chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('peer_id')->default(0);
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['user_id', 'peer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_messages');
    }
};
