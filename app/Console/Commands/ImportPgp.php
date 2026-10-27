<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPgp extends Command
{
    protected $signature = 'import:pgp {file}';
    protected $description = 'Импорт pgp_records из CSV (дата ДД.ММ.ГГГГ -> ГГГГ-ММ-ДД)';

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
            if (count($row) < 14) { $skipped++; continue; }

            [$date, $post, $fio1, $fio2, $fio3, $start, $end, $otk,
             $board, $ip, $fuselage, $status, $reason, $actions] = $row;

            $dateDb = $this->normalizeDate($date);
            if ($dateDb === null || $fio1 === '') { $skipped++; continue; }

            $chunk[] = [
                'record_date'      => $dateDb,
                'post_number'      => $post === '' ? null : (int)$post,
                'worker1'          => $fio1,
                'worker2'          => $fio2 ?: null,
                'worker3'          => $fio3 ?: null,
                'work_start'       => $start ?: null,
                'work_end'         => $end   ?: null,
                'otk_wait_minutes' => $otk === '' ? null : (int)$otk,
                'board_number'     => $board ?: null,
                'ip_address'       => $ip ?: null,
                'fuselage_number'  => $fuselage ?: null,
                'status'           => $status ?: 'Сдан',
                'defect_reason'    => $reason ?: null,
                'actions_taken'    => $actions ?: null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ];

            if (count($chunk) === 200) {
                DB::table('pgp_records')->insert($chunk);
                $total += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            DB::table('pgp_records')->insert($chunk);
            $total += count($chunk);
        }
        fclose($handle);

        $this->info("Загружено: {$total}, пропущено: {$skipped}");
        return self::SUCCESS;
    }

    private function normalizeDate(string $d): ?string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }
}
