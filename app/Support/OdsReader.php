<?php

namespace App\Support;

/**
 * Потоковое чтение .ods (LibreOffice) без PhpSpreadsheet: XMLReader идёт по content.xml и хранит только значения.
 * PhpSpreadsheet держит каждую ячейку объектом — журнал на десятки тысяч строк не помещается в 128 МБ небольшого сервера.
 *
 * Значения: число — float/int, дата — «Y-m-d» (или «Y-m-d H:i»), время — «H:i», да/нет — bool, остальное — текст.
 * Пустые «повторённые» строки и колонки (оформление LibreOffice до 1 048 576 строк) не разворачиваются.
 */
class OdsReader
{
    private const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /** Названия листов. */
    public static function sheetNames(string $path): array
    {
        $names = [];
        $xml = self::open($path);
        $ok = $xml->read();
        while ($ok) {
            if (self::isTable($xml)) {
                $names[] = (string) $xml->getAttributeNs('name', self::TABLE);
                $ok = $xml->next();   // к следующему листу, содержимое не читаем
                continue;
            }
            $ok = $xml->read();
        }
        $xml->close();

        return $names;
    }

    /**
     * Строки листа: [номер строки с 0 => [номер колонки с 0 => значение]] — только непустые строки и ячейки.
     * $maxRows — прочитать только начало листа, $maxCols — сколько колонок слева учитывать.
     */
    public static function sheet(string $path, string $sheet, ?int $maxRows = null, int $maxCols = 60): array
    {
        $xml = self::open($path);
        $rows = [];

        // ищем нужный лист
        $found = false;
        $ok = $xml->read();
        while ($ok) {
            if (self::isTable($xml)) {
                if ((string) $xml->getAttributeNs('name', self::TABLE) === $sheet) { $found = true; break; }
                $ok = $xml->next();
                continue;
            }
            $ok = $xml->read();
        }
        if (! $found) { $xml->close(); return []; }

        $depth = $xml->depth;
        $rowIdx = 0;
        while ($xml->read()) {
            if ($xml->nodeType === \XMLReader::END_ELEMENT && $xml->depth === $depth) break;   // конец листа
            if ($xml->nodeType !== \XMLReader::ELEMENT || $xml->namespaceURI !== self::TABLE || $xml->localName !== 'table-row') continue;
            if ($maxRows !== null && $rowIdx >= $maxRows) break;

            $repeat = max(1, (int) ($xml->getAttributeNs('number-rows-repeated', self::TABLE) ?: 1));
            $cells = $xml->isEmptyElement ? [] : self::row($xml, $maxCols);
            if ($cells) {
                // одинаковые непустые строки подряд LibreOffice тоже схлопывает — разворачиваем (но не больше разумного)
                for ($i = 0; $i < min($repeat, 1000); $i++) {
                    if ($maxRows !== null && $rowIdx + $i >= $maxRows) break;
                    $rows[$rowIdx + $i] = $cells;
                }
            }
            $rowIdx += $repeat;
        }
        $xml->close();

        return $rows;
    }

    /** Ячейки текущей строки (курсор на <table:table-row>), курсор остаётся на её конце. */
    private static function row(\XMLReader $xml, int $maxCols): array
    {
        $cells = [];
        $col = 0;
        $depth = $xml->depth;
        $ok = $xml->read();
        while ($ok) {
            if ($xml->nodeType === \XMLReader::END_ELEMENT && $xml->depth === $depth) break;
            $isCell = $xml->nodeType === \XMLReader::ELEMENT && $xml->depth === $depth + 1 && $xml->namespaceURI === self::TABLE
                && ($xml->localName === 'table-cell' || $xml->localName === 'covered-table-cell');
            if (! $isCell) { $ok = $xml->read(); continue; }

            $repeat = max(1, (int) ($xml->getAttributeNs('number-columns-repeated', self::TABLE) ?: 1));
            $value = $col < $maxCols ? self::cell($xml) : null;
            if ($value !== null && $value !== '') {
                for ($i = 0; $i < $repeat && $col + $i < $maxCols; $i++) $cells[$col + $i] = $value;
            }
            $col += $repeat;
            $ok = $xml->next();   // к следующей ячейке, содержимое этой пропускаем
        }

        return $cells;
    }

    /** Значение ячейки (курсор на <table:table-cell>). */
    private static function cell(\XMLReader $xml)
    {
        $type = (string) $xml->getAttributeNs('value-type', self::OFFICE);
        switch ($type) {
            case 'float':
            case 'percentage':
            case 'currency':
                $v = (string) $xml->getAttributeNs('value', self::OFFICE);
                return is_numeric($v) ? $v + 0 : $v;
            case 'date':
                $v = (string) $xml->getAttributeNs('date-value', self::OFFICE);   // 2026-11-05 или 2026-11-05T09:15:00
                return preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}):(\d{2}))?/', $v, $m)
                    ? (isset($m[2]) && ($m[2] !== '00' || $m[3] !== '00') ? "$m[1] $m[2]:$m[3]" : $m[1]) : $v;
            case 'time':
                $v = (string) $xml->getAttributeNs('time-value', self::OFFICE);   // PT08H30M00S
                return preg_match('/PT(\d+)H(\d+)M/', $v, $m) ? sprintf('%02d:%02d', (int) $m[1] % 24, (int) $m[2]) : $v;
            case 'boolean':
                return (string) $xml->getAttributeNs('boolean-value', self::OFFICE) === 'true';
        }
        if ($xml->isEmptyElement) return null;

        // текст: абзацы <text:p> через перевод строки; <text:s/> — пробелы, <text:tab/>, <text:line-break/>
        $node = $xml->expand();
        if (! $node) return null;
        $parts = [];
        foreach ($node->childNodes as $p) {
            if ($p->namespaceURI === self::TEXT && $p->localName === 'p') $parts[] = self::text($p);
        }

        return trim(implode("\n", $parts));
    }

    private static function text(\DOMNode $node): string
    {
        $s = '';
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) { $s .= $c->nodeValue; continue; }
            if ($c->namespaceURI !== self::TEXT) { $s .= $c->textContent; continue; }
            $s .= match ($c->localName) {
                's'          => str_repeat(' ', max(1, (int) $c->getAttributeNS(self::TEXT, 'c'))),
                'tab'        => "\t",
                'line-break' => "\n",
                default      => self::text($c),
            };
        }

        return $s;
    }

    private static function isTable(\XMLReader $xml): bool
    {
        return $xml->nodeType === \XMLReader::ELEMENT && $xml->localName === 'table' && $xml->namespaceURI === self::TABLE;
    }

    private static function open(string $path): \XMLReader
    {
        $xml = new \XMLReader();
        if (! $xml->open('zip://' . str_replace('\\', '/', $path) . '#content.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Не удалось открыть .ods: нет content.xml');
        }

        return $xml;
    }
}
