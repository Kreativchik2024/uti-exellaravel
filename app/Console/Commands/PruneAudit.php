<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/** Удаляет записи журнала действий старше срока хранения. */
class PruneAudit extends Command
{
    protected $signature = 'audit:prune {--days= : Сколько дней хранить (по умолчанию ' . AuditLog::RETENTION_DAYS . ')}';

    protected $description = 'Очистить журнал действий старше срока хранения';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: AuditLog::RETENTION_DAYS);
        $deleted = AuditLog::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Удалено записей журнала: {$deleted}");

        return self::SUCCESS;
    }
}
