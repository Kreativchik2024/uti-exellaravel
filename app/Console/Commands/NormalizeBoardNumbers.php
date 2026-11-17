<?php

namespace App\Console\Commands;

use App\Models\PgpRecord;
use App\Support\BoardNumber;
use Illuminate\Console\Command;

/**
 * Одноразово приводит уже записанные номера бортов ПГП к общему виду (App\Support\BoardNumber).
 * Сначала показывает, что изменится, и спрашивает подтверждение. Изменения пишутся в журнал действий.
 */
class NormalizeBoardNumbers extends Command
{
    protected $signature = 'boards:normalize
        {--dry-run : Только показать, что изменится, ничего не меняя}
        {--force : Не спрашивать подтверждение}';

    protected $description = 'Привести номера бортов ПГП к общему виду (S50Z100844MH-02T)';

    public function handle(): int
    {
        $changes = [];   // id => [было, стало]
        PgpRecord::query()->whereNotNull('board_number')->where('board_number', '<>', '')
            ->select(['id', 'board_number'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$changes) {
                foreach ($rows as $r) {
                    $new = BoardNumber::normalize($r->board_number);
                    if ($new !== $r->board_number) $changes[$r->id] = [$r->board_number, $new];
                }
            });

        if (! $changes) {
            $this->info('Все номера бортов уже в общем виде — менять нечего.');
            return self::SUCCESS;
        }

        $this->info('Будет изменено номеров: ' . count($changes));
        $this->table(['ID', 'Было', 'Стало'], collect($changes)->take(30)
            ->map(fn ($c, $id) => [$id, $c[0], $c[1]])->values()->all());
        if (count($changes) > 30) $this->line('… и ещё ' . (count($changes) - 30));

        if ($this->option('dry-run')) return self::SUCCESS;
        if (! $this->option('force') && ! $this->confirm('Изменить?', false)) {
            $this->warn('Отменено.');
            return self::SUCCESS;
        }

        $done = 0;
        foreach (array_chunk(array_keys($changes), 500) as $ids) {
            foreach (PgpRecord::whereIn('id', $ids)->get() as $rec) {
                $rec->timestamps = false;                         // дату изменения записи не трогаем
                $rec->board_number = $changes[$rec->id][1];
                $rec->save();                                     // через модель — попадёт в журнал действий
                $done++;
            }
        }

        $this->info("Готово, изменено: {$done}");
        return self::SUCCESS;
    }
}
