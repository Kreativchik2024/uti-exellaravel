<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\FaultCode;
use App\Services\FaultCodeRating;
use App\Services\FaultCodes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Приводит коды неисправностей, уже записанные в учёте, к коду справочника: текст, похожий на описание
 * одного кода, и написание, отличающееся от кода, заменяются на код. Неоднозначные и неизвестные не трогаем.
 * Сначала показывает, что изменится, и спрашивает подтверждение. Изменения пишутся в журнал действий.
 */
class LinkFaultCodesInRecords extends Command
{
    protected $signature = 'fault-codes:link-records
        {--dry-run : Только показать, что изменится, ничего не меняя}
        {--force : Не спрашивать подтверждение}';

    protected $description = 'Привязать записанные коды неисправностей к кодам справочника';

    public function handle(FaultCodeRating $rating, FaultCodes $codes): int
    {
        $catalog = FaultCode::all();
        $raws = [];   // сырой код => записей
        foreach (config('sheets.map') as $cfg) {
            if (empty($cfg['model']) || ! isset($cfg['fields']['fault_code'])) continue;
            $table = (new $cfg['model'])->getTable();
            DB::table($table)->whereNotNull('fault_code')->where('fault_code', '<>', '')
                ->select('fault_code', DB::raw('COUNT(*) AS cnt'))->groupBy('fault_code')->get()
                ->each(function ($row) use (&$raws) {
                    $raws[$row->fault_code] = ($raws[$row->fault_code] ?? 0) + (int) $row->cnt;
                });
        }

        $changes = [];   // сырой код => код справочника
        foreach ($raws as $raw => $count) {
            // Код уже есть в справочнике точно как записан — менять нечего
            if ($catalog->contains(fn ($c) => $c->code === $raw)) continue;
            $target = $rating->canonicalCode((string) $raw, $catalog);
            if ($target && $target->code !== $raw) $changes[(string) $raw] = $target->code;
        }

        if (! $changes) {
            $this->info('Записанные коды уже совпадают со справочником — менять нечего.');
            return self::SUCCESS;
        }

        $this->info('Будет приведено написаний: ' . count($changes) . ', записей: ' . array_sum(array_map(fn ($raw) => $raws[$raw], array_keys($changes))));
        $this->table(['Было', 'Записей', 'Станет'], collect($changes)->map(fn ($to, $from) => [$from, $raws[$from], $to])->values()->all());

        if ($this->option('dry-run')) return self::SUCCESS;
        if (! $this->option('force') && ! $this->confirm('Привязать записи к справочнику?', false)) {
            $this->warn('Отменено.');
            return self::SUCCESS;
        }

        $done = 0;
        foreach ($changes as $from => $to) {
            $done += $codes->replaceInRecords($from, $to);
        }
        AuditLog::write('fault_codes', 'linked', 'Коды записей приведены к справочнику: написаний ' . count($changes) . ", записей {$done}",
            ['section_id' => null, 'changes' => $changes]);

        $this->info("Готово, исправлено записей: {$done}");
        return self::SUCCESS;
    }
}
