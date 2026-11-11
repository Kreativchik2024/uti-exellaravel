<?php

namespace App\Exports\Support;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Книга Excel из листов TableSheet. */
class Workbook implements Export, WithMultipleSheets
{
    /** @param TableSheet[] $sheets */
    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        return $this->sheets;
    }

    /** Строка «Выгружено» для блока сведений. */
    public static function stamp(): array
    {
        return ['Выгружено', now()->format('d.m.Y H:i') . (auth()->user() ? ' · ' . auth()->user()->name : '')];
    }

    /** Доля для колонки pct: 0..1 или null, если делить не на что. */
    public static function share(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round($part / $total, 4) : null;
    }
}
