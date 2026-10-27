<?php
// Чистка sled.csv -> sled_clean.csv
// 13 колонок, разделитель ';', кавычки '"', переносы внутри кавычек

$in  = __DIR__ . '/storage/import/sled.csv';
$out = __DIR__ . '/storage/import/sled_clean.csv';

$fh_in  = fopen($in, 'r');
$fh_out = fopen($out, 'w');

$first = true;
$bad   = [];   // <-- ВАЖНО: именно массив
$count = 0;

while (($row = fgetcsv($fh_in, 0, ';', '"', '\\')) !== false) {
    if ($first) { $first = false; continue; } // заголовок

    // оставить только 13 значимых колонок
    $row = array_slice($row, 0, 13);
    $row = array_map(fn($v) => trim((string)$v), $row);

    // пропустить полностью пустые строки
    if (implode('', $row) === '') continue;

    [$seq, $num, $date, $fio, $fus, $fus_ser, $frame, $bracket,
     $reported, $rejected, $actions, $found, $result] = array_pad($row, 13, '');

    // дата ДД.ММ.ГГГГ -> ГГГГ-ММ-ДД
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
        $date = "{$m[3]}-{$m[2]}-{$m[1]}";
    } else {
        $bad[] = "date: " . implode(';', $row);
        continue;
    }

    // seq: число или NULL
    $seqVal = ($seq !== '' && ctype_digit($seq)) ? (int)$seq : null;

    // worker1 NOT NULL — если пусто, подставить заглушку
    if ($fio === '') {
        $fio = 'не указан';
    }

    // нормализовать кавычки-артефакты
    $fio = trim($fio, '"');

    // пропустить записи-пустышки (только seq/дата)
    if ($reported === '' && $result === '' && $found === '' && $actions === '') {
        continue;
    }

    fputcsv($fh_out, [
        $seqVal, $date, $fio,
        $fus !== '' ? $fus : null,
        $fus_ser !== '' ? $fus_ser : null,
        $frame !== '' ? $frame : null,
        $bracket !== '' ? $bracket : null,
        $reported !== '' ? $reported : null,
        $rejected !== '' ? $rejected : null,
        $actions !== '' ? $actions : null,
        $found !== '' ? $found : null,
        $result !== '' ? $result : null,
    ], ';');

    $count++;
}

fclose($fh_in);
fclose($fh_out);

if ($bad) {
    file_put_contents(__DIR__ . '/storage/import/sled_bad.txt', implode("\n", $bad));
    echo "Пропущено строк: " . count($bad) . " (см. storage/import/sled_bad.txt)\n";
}
