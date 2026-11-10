<?php

namespace App\Services\Games;

/**
 * Морской бой по классическим правилам: поле 10×10, корабли 4, 3+3, 2+2+2, 1+1+1+1,
 * корабли не касаются друг друга даже углами. Попал — стреляешь ещё; потопил —
 * клетки вокруг корабля отмечаются промахами автоматически.
 *
 * ships: [[[r,c],...], ...]; shots: ["r,c" => "hit"|"miss"]
 */
class Battleship
{
    public const SIZE = 10;
    public const FLEET = [4, 3, 3, 2, 2, 2, 1, 1, 1, 1];

    /** Случайная правильная расстановка флота. */
    public static function randomFleet(): array
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $taken = [];
            $ships = [];
            foreach (self::FLEET as $len) {
                $placed = false;
                for ($try = 0; $try < 200 && ! $placed; $try++) {
                    $vertical = (bool) random_int(0, 1);
                    $r = random_int(0, $vertical ? self::SIZE - $len : self::SIZE - 1);
                    $c = random_int(0, $vertical ? self::SIZE - 1 : self::SIZE - $len);
                    $cells = [];
                    for ($i = 0; $i < $len; $i++) $cells[] = $vertical ? [$r + $i, $c] : [$r, $c + $i];
                    if (array_filter($cells, fn ($p) => isset($taken["{$p[0]},{$p[1]}"]))) continue;
                    $ships[] = $cells;
                    foreach ($cells as [$cr, $cc]) {
                        for ($dr = -1; $dr <= 1; $dr++) for ($dc = -1; $dc <= 1; $dc++) $taken[($cr + $dr) . ',' . ($cc + $dc)] = true;
                    }
                    $placed = true;
                }
                if (! $placed) continue 2;
            }

            return $ships;
        }
        throw new \RuntimeException('Не удалось расставить флот');
    }

    /**
     * Выстрел по полю с кораблями $ships. Возвращает [shots, result: miss|hit|sunk, allSunk].
     */
    public static function shoot(array $ships, array $shots, int $r, int $c): array
    {
        $key = "$r,$c";
        if (isset($shots[$key]) || $r < 0 || $c < 0 || $r >= self::SIZE || $c >= self::SIZE) {
            return [$shots, 'repeat', false];
        }

        foreach ($ships as $ship) {
            if (! in_array([$r, $c], $ship, true)) continue;
            $shots[$key] = 'hit';
            $sunk = ! array_filter($ship, fn ($p) => ($shots["{$p[0]},{$p[1]}"] ?? null) !== 'hit');
            if ($sunk) {
                foreach ($ship as [$sr, $sc]) {   // вокруг потопленного — промахи
                    for ($dr = -1; $dr <= 1; $dr++) for ($dc = -1; $dc <= 1; $dc++) {
                        $nr = $sr + $dr; $nc = $sc + $dc;
                        if ($nr >= 0 && $nc >= 0 && $nr < self::SIZE && $nc < self::SIZE && ! isset($shots["$nr,$nc"])) $shots["$nr,$nc"] = 'miss';
                    }
                }
            }

            return [$shots, $sunk ? 'sunk' : 'hit', self::allSunk($ships, $shots)];
        }
        $shots[$key] = 'miss';

        return [$shots, 'miss', false];
    }

    public static function allSunk(array $ships, array $shots): bool
    {
        foreach ($ships as $ship) {
            foreach ($ship as [$r, $c]) {
                if (($shots["$r,$c"] ?? null) !== 'hit') return false;
            }
        }

        return true;
    }

    /**
     * Обычный компьютер — как начинающий игрок: стреляет наугад, а попав — добивает,
     * пробуя соседние клетки без расчёта линии корабля.
     */
    public static function casualTarget(array $shots): array
    {
        $free = fn ($r, $c) => $r >= 0 && $c >= 0 && $r < self::SIZE && $c < self::SIZE && ! isset($shots["$r,$c"]);
        $around = [];
        foreach ($shots as $k => $v) {
            if ($v !== 'hit') continue;
            [$r, $c] = array_map('intval', explode(',', $k));
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
                if ($free($r + $dr, $c + $dc)) $around[] = [$r + $dr, $c + $dc];
            }
        }
        if ($around) return $around[array_rand($around)];

        $cells = [];
        for ($r = 0; $r < self::SIZE; $r++) for ($c = 0; $c < self::SIZE; $c++) if ($free($r, $c)) $cells[] = [$r, $c];

        return $cells[array_rand($cells)];
    }

    /**
     * Правильная ли расстановка: ровно флот FLEET, корабли прямые и сплошные, в пределах поля,
     * не касаются друг друга даже углами.
     */
    public static function validFleet(mixed $ships): bool
    {
        if (! is_array($ships) || count($ships) !== count(self::FLEET)) return false;
        $taken = [];
        $lengths = [];
        foreach ($ships as $i => $ship) {
            if (! is_array($ship) || ! $ship) return false;
            $cells = [];
            foreach ($ship as $p) {
                if (! is_array($p) || count($p) !== 2 || ! is_int($p[0]) || ! is_int($p[1])) return false;
                [$r, $c] = $p;
                if ($r < 0 || $c < 0 || $r >= self::SIZE || $c >= self::SIZE) return false;
                $cells[] = [$r, $c];
            }
            $rows = array_unique(array_column($cells, 0));
            $cols = array_unique(array_column($cells, 1));
            if (count($rows) > 1 && count($cols) > 1) return false;                  // не по прямой
            $line = count($rows) === 1 ? $cols : $rows;
            if (count($line) !== count($cells) || max($line) - min($line) + 1 !== count($cells)) return false;   // с разрывом или повтором
            foreach ($cells as [$r, $c]) {
                for ($dr = -1; $dr <= 1; $dr++) for ($dc = -1; $dc <= 1; $dc++) {
                    $owner = $taken[($r + $dr) . ',' . ($c + $dc)] ?? null;
                    if ($owner !== null && $owner !== $i) return false;              // касается другого корабля
                }
            }
            foreach ($cells as [$r, $c]) $taken["$r,$c"] = $i;
            $lengths[] = count($cells);
        }
        $fleet = self::FLEET;
        rsort($lengths);

        return $lengths === $fleet;
    }

    /**
     * Сильный компьютер: карта вероятностей. Для каждого ещё не потопленного корабля перебираются
     * все расстановки, не противоречащие известному (промахи, попадания, потопленные корабли),
     * и выбирается клетка, которую накрывает больше всего возможных расстановок. Есть раненый
     * корабль — учитываются только расстановки через него. Использует только то, что видно
     * и человеку: результаты своих выстрелов и какие корабли потоплены.
     *
     * @param array $sunk потопленные корабли игрока (о потоплении объявляется)
     */
    public static function strongTarget(array $shots, array $sunk): array
    {
        $sunkCells = [];
        foreach ($sunk as $ship) foreach ($ship as [$r, $c]) $sunkCells["$r,$c"] = true;

        $remaining = self::FLEET;
        foreach ($sunk as $ship) {
            $i = array_search(count($ship), $remaining, true);
            if ($i !== false) unset($remaining[$i]);
        }
        $remaining = array_values($remaining);

        $active = [];   // раненые, но не потопленные
        foreach ($shots as $k => $v) {
            if ($v === 'hit' && ! isset($sunkCells[$k])) $active[$k] = true;
        }

        // Поиск (раненых нет): мысленно расставляем весь оставшийся флот много раз с учётом
        // правила «корабли не касаются» и стреляем туда, где корабли оказываются чаще всего
        if (! $active && $remaining) {
            $heat = self::sampleHeat($shots, $sunkCells, $remaining, self::$samples);
            if ($heat) {
                $max = max($heat);
                $best = array_keys(array_filter($heat, fn ($v) => $v === $max));

                return array_map('intval', explode(',', $best[array_rand($best)]));
            }
        }

        $heat = [];
        foreach (array_unique($remaining) as $len) {
            $copies = count(array_keys($remaining, $len, true));
            foreach ([[0, 1], [1, 0]] as [$dr, $dc]) {
                for ($r = 0; $r < self::SIZE; $r++) {
                    for ($c = 0; $c < self::SIZE; $c++) {
                        $cells = [];
                        for ($i = 0; $i < $len; $i++) $cells[] = [$r + $dr * $i, $c + $dc * $i];
                        $weight = self::placementWeight($cells, $shots, $active);
                        if ($weight <= 0) continue;
                        foreach ($cells as [$cr, $cc]) {
                            if (! isset($shots["$cr,$cc"])) $heat["$cr,$cc"] = ($heat["$cr,$cc"] ?? 0) + $weight * $copies;
                        }
                    }
                }
            }
        }

        if (! $heat) return self::botTarget($shots);   // на всякий случай — обычный ход
        $max = max($heat);
        $best = array_keys(array_filter($heat, fn ($v) => $v === $max));

        return array_map('intval', explode(',', $best[array_rand($best)]));
    }

    /** Сколько случайных расстановок флота перебирает сильный компьютер на ход. */
    public static int $samples = 150;

    /**
     * Частота клеток по $count случайным правильным расстановкам оставшегося флота
     * (не на промахах, не рядом с потопленными, корабли не касаются друг друга).
     */
    private static function sampleHeat(array $shots, array $sunkCells, array $lengths, int $count): array
    {
        rsort($lengths);
        // Заняты: промахи, потопленные и их окрестность (там тоже промахи — автоматически)
        $blocked = [];
        foreach ($shots as $k => $v) $blocked[$k] = true;

        // Все возможные положения каждого размера
        $options = [];
        foreach (array_unique($lengths) as $len) {
            foreach ([[0, 1], [1, 0]] as [$dr, $dc]) {
                for ($r = 0; $r < self::SIZE; $r++) for ($c = 0; $c < self::SIZE; $c++) {
                    $cells = [];
                    for ($i = 0; $i < $len; $i++) {
                        $rr = $r + $dr * $i; $cc = $c + $dc * $i;
                        if ($rr >= self::SIZE || $cc >= self::SIZE || isset($blocked["$rr,$cc"])) continue 2;
                        $cells[] = "$rr,$cc";
                    }
                    if ($len === 1 && $dr === 1) continue;   // однопалубный — без дубля
                    $options[$len][] = $cells;
                }
            }
        }

        $heat = [];
        $done = 0;
        for ($attempt = 0; $attempt < $count * 4 && $done < $count; $attempt++) {
            $taken = [];   // занятые клетки + окрестность
            $placed = [];
            foreach ($lengths as $len) {
                $opts = $options[$len] ?? [];
                $ok = false;
                for ($try = 0; $try < 30 && $opts; $try++) {
                    $cells = $opts[array_rand($opts)];
                    foreach ($cells as $k) if (isset($taken[$k])) continue 2;
                    foreach ($cells as $k) {
                        [$r, $c] = explode(',', $k);
                        for ($dr = -1; $dr <= 1; $dr++) for ($dc = -1; $dc <= 1; $dc++) $taken[($r + $dr) . ',' . ($c + $dc)] = true;
                    }
                    $placed[] = $cells;
                    $ok = true;
                    break;
                }
                if (! $ok) continue 2;   // не влезло — новая попытка
            }
            foreach ($placed as $cells) foreach ($cells as $k) $heat[$k] = ($heat[$k] ?? 0) + 1;
            $done++;
        }

        return $heat;
    }

    /** Вес возможной расстановки корабля: 0 — невозможна; через раненые клетки — намного выше. */
    private static function placementWeight(array $cells, array $shots, array $active): int
    {
        $inside = [];
        $hits = 0;
        foreach ($cells as [$r, $c]) {
            if ($r >= self::SIZE || $c >= self::SIZE) return 0;
            $s = $shots["$r,$c"] ?? null;
            if ($s === 'miss') return 0;
            if ($s === 'hit') {
                if (! isset($active["$r,$c"])) return 0;   // клетка потопленного корабля
                $hits++;
            }
            $inside["$r,$c"] = true;
        }
        // Корабли не касаются: рядом не может быть чужой раненой клетки
        foreach ($cells as [$r, $c]) {
            for ($dr = -1; $dr <= 1; $dr++) for ($dc = -1; $dc <= 1; $dc++) {
                $k = ($r + $dr) . ',' . ($c + $dc);
                if (isset($active[$k]) && ! isset($inside[$k])) return 0;
            }
        }
        if ($active && ! $hits) return 0;   // добиваем раненый, новые не ищем

        return $hits ? 1000 ** $hits : 1;
    }

    /** Потопленные корабли (для показа сопернику). */
    public static function sunkShips(array $ships, array $shots): array
    {
        return array_values(array_filter($ships, fn ($ship) => ! array_filter($ship, fn ($p) => ($shots["{$p[0]},{$p[1]}"] ?? null) !== 'hit')));
    }

    /**
     * Ход компьютера: добивает раненый корабль по линии, иначе стреляет в шахматном порядке
     * (большие корабли находятся быстрее).
     */
    public static function botTarget(array $shots): array
    {
        $free = fn ($r, $c) => $r >= 0 && $c >= 0 && $r < self::SIZE && $c < self::SIZE && ! isset($shots["$r,$c"]);

        // Раненые клетки, вокруг которых ещё не всё отмечено (потопленные окружены промахами)
        $hits = [];
        foreach ($shots as $k => $v) {
            if ($v !== 'hit') continue;
            [$r, $c] = array_map('intval', explode(',', $k));
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
                if ($free($r + $dr, $c + $dc)) { $hits[] = [$r, $c]; break; }
            }
        }
        if ($hits) {
            // Если две раненые клетки на одной линии — продолжаем эту линию
            foreach ($hits as [$r, $c]) {
                foreach ([[0, 1], [1, 0]] as [$dr, $dc]) {
                    if (($shots[($r + $dr) . ',' . ($c + $dc)] ?? null) === 'hit' || ($shots[($r - $dr) . ',' . ($c - $dc)] ?? null) === 'hit') {
                        $line = [];
                        foreach ([1, -1] as $sign) {
                            $nr = $r; $nc = $c;
                            while (($shots["$nr,$nc"] ?? null) === 'hit') { $nr += $dr * $sign; $nc += $dc * $sign; }
                            if ($free($nr, $nc)) $line[] = [$nr, $nc];
                        }
                        if ($line) return $line[array_rand($line)];
                    }
                }
            }
            [$r, $c] = $hits[0];
            $opts = array_values(array_filter([[$r + 1, $c], [$r - 1, $c], [$r, $c + 1], [$r, $c - 1]], fn ($p) => $free($p[0], $p[1])));
            if ($opts) return $opts[array_rand($opts)];
        }

        $cells = [];
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                if ($free($r, $c)) $cells[] = [$r, $c];
            }
        }
        $checker = array_values(array_filter($cells, fn ($p) => ($p[0] + $p[1]) % 2 === 0));

        return ($checker ?: $cells)[array_rand($checker ?: $cells)];
    }
}
