<?php

namespace App\Services\Games;

/**
 * Сапёр. Поле и мины — только на сервере (браузер узнаёт лишь открытые клетки),
 * поэтому рекорд нельзя получить, подсмотрев мины. Мины ставятся после первого клика:
 * первая клетка и её соседи всегда безопасны. Время — по часам сервера.
 *
 * state: rows, cols, mines (кол-во), mine (список "r,c"), open ("r,c" => число), started_ms
 */
class Minesweeper
{
    public const LEVELS = [
        'easy'   => ['name' => 'Новичок',  'rows' => 9,  'cols' => 9,  'mines' => 10],
        'medium' => ['name' => 'Любитель', 'rows' => 16, 'cols' => 16, 'mines' => 40],
        'hard'   => ['name' => 'Профи',    'rows' => 16, 'cols' => 30, 'mines' => 99],
    ];

    public static function newState(string $level): array
    {
        $l = self::LEVELS[$level];

        return ['rows' => $l['rows'], 'cols' => $l['cols'], 'mines' => $l['mines'], 'mine' => [], 'open' => [], 'started_ms' => null];
    }

    /**
     * Открыть клетки. Возвращает [state, opened (список [r,c,n]), result: null|'won'|'lost', mines (при проигрыше/победе)].
     */
    public static function open(array $state, array $cells): array
    {
        if ($state['started_ms'] === null) {
            [$r0, $c0] = $cells[0];
            $state['mine'] = self::placeMines($state, (int) $r0, (int) $c0);
            $state['started_ms'] = (int) floor(microtime(true) * 1000);
        }
        $mines = array_flip($state['mine']);
        $opened = [];

        foreach ($cells as [$r, $c]) {
            $r = (int) $r; $c = (int) $c;
            if (! self::inside($state, $r, $c) || isset($state['open']["$r,$c"])) continue;
            if (isset($mines["$r,$c"])) {
                return [$state, $opened, 'lost', $state['mine'], "$r,$c"];
            }
            // Открытие с «разливом» по пустым клеткам
            $queue = [[$r, $c]];
            while ($queue) {
                [$qr, $qc] = array_pop($queue);
                if (isset($state['open']["$qr,$qc"])) continue;
                $n = self::count($state, $mines, $qr, $qc);
                $state['open']["$qr,$qc"] = $n;
                $opened[] = [$qr, $qc, $n];
                if ($n === 0) {
                    foreach (self::neighbours($state, $qr, $qc) as [$nr, $nc]) {
                        if (! isset($state['open']["$nr,$nc"]) && ! isset($mines["$nr,$nc"])) $queue[] = [$nr, $nc];
                    }
                }
            }
        }

        $won = count($state['open']) === $state['rows'] * $state['cols'] - $state['mines'];

        return [$state, $opened, $won ? 'won' : null, $won ? $state['mine'] : null, null];
    }

    private static function placeMines(array $s, int $r0, int $c0): array
    {
        $safe = ["$r0,$c0" => true];
        foreach (self::neighbours($s, $r0, $c0) as [$r, $c]) $safe["$r,$c"] = true;

        $cells = [];
        for ($r = 0; $r < $s['rows']; $r++) {
            for ($c = 0; $c < $s['cols']; $c++) {
                if (! isset($safe["$r,$c"])) $cells[] = "$r,$c";
            }
        }
        shuffle($cells);

        return array_slice($cells, 0, $s['mines']);
    }

    private static function count(array $s, array $mines, int $r, int $c): int
    {
        $n = 0;
        foreach (self::neighbours($s, $r, $c) as [$nr, $nc]) {
            if (isset($mines["$nr,$nc"])) $n++;
        }

        return $n;
    }

    private static function neighbours(array $s, int $r, int $c): array
    {
        $out = [];
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if (($dr || $dc) && self::inside($s, $r + $dr, $c + $dc)) $out[] = [$r + $dr, $c + $dc];
            }
        }

        return $out;
    }

    private static function inside(array $s, int $r, int $c): bool
    {
        return $r >= 0 && $c >= 0 && $r < $s['rows'] && $c < $s['cols'];
    }
}
