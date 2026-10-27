<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportResearch extends Command
{
    protected $signature = 'import:research {file}';
    protected $description = 'Импорт research_records из CSV';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (!is_file($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $chunk = [];
        $total = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            if (count($row) < 12) { $skipped++; continue; }

            [$seq, $date, $fio, $fus, $fusSer, $frame, $bracket,
             $reported, $rejected, $actions, $found, $result] = array_pad($row, 12, '');

            if ($date === '' || $fio === '') { $skipped++; continue; }

            $chunk[] = [
                'record_date'     => $date,
                'seq'             => ($seq !== '' && ctype_digit((string)$seq)) ? (int)$seq : null,
                'worker1'         => $fio,
                'fuselage_no'     => $fus ?: null,
                'fuselage_serial' => $fusSer ?: null,
                'frame_serial'    => $frame ?: null,
                'bracket_serial'  => $bracket ?: null,
                'reported_defect' => $reported ?: null,
                'rejected_by'     => $rejected ?: null,
                'analysis_actions'=> $actions ?: null,
                'found_defect'    => $found ?: null,
                'result'          => $result ?: null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];

            if (count($chunk) === 200) {
                DB::table('research_records')->insert($chunk);
                $total += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            DB::table('research_records')->insert($chunk);
            $total += count($chunk);
        }
        fclose($handle);

        $this->info("Загружено: {$total}, пропущено: {$skipped}");
        return self::SUCCESS;
    }
}
