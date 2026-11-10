<?php

namespace App\Console\Commands;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use Illuminate\Console\Command;

/**
 * Удаляет сообщения чата старше ChatMessage::RETENTION_DAYS (7 дней)
 * вместе с вложениями (файлы на диске) и реакциями.
 * Запускается по расписанию ежедневно (routes/console.php).
 */
class PruneChat extends Command
{
    protected $signature = 'chat:prune {--days= : Хранить столько дней (по умолчанию ' . ChatMessage::RETENTION_DAYS . ')}';

    protected $description = 'Удалить старые сообщения чата вместе с файлами';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: ChatMessage::RETENTION_DAYS);
        $border = now()->subDays($days);
        $messages = 0;
        $files = 0;

        // Закреплённое объявление хранится, пока его не открепят
        ChatMessage::where('created_at', '<', $border)->whereNull('pinned_at')->select('id')->chunkById(500, function ($chunk) use (&$messages, &$files) {
            $ids = $chunk->pluck('id');

            // Через модель — чтобы удалились и файлы с диска
            ChatAttachment::whereIn('message_id', $ids)->get()->each(function (ChatAttachment $a) use (&$files) {
                $a->delete();
                $files++;
            });

            // Реакции удаляются каскадом, ответы на удалённые теряют цитату (nullOnDelete)
            $messages += ChatMessage::whereIn('id', $ids)->delete();
        });

        $this->info("Удалено сообщений: {$messages}, файлов: {$files} (старше {$days} дн.)");

        return self::SUCCESS;
    }
}
