<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чат «как в Telegram»: ответы на сообщения, вложения (картинки и файлы),
 * реакции. История хранится 7 дней (см. команду chat:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // Сообщение может быть только с вложением — без текста
            $table->text('body')->nullable()->change();
            $table->foreignId('reply_to_id')->nullable()->after('recipient_id')
                ->constrained('chat_messages')->nullOnDelete();
            // Меняется при реакциях — по нему клиент забирает обновления
            $table->timestamp('updated_at')->nullable()->after('created_at')->index();
        });

        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('kind', 10);                 // image | file
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('chat_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['message_id', 'user_id']);   // одна реакция от человека на сообщение
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reactions');
        Schema::dropIfExists('chat_attachments');
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn('updated_at');
        });
    }
};
