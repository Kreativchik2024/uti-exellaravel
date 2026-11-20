<?php

namespace App\Services;

use App\Models\FaultCode;

/**
 * Расшифровка кода неисправности для подсказок в таблицах (код → описание).
 * Справочник читается один раз на запрос (scoped-сервис), поэтому в таблице из 50 строк — один запрос.
 */
class FaultCodeDescriptions
{
    private ?array $map = null;

    public function __construct(private SectionContext $sections) {}

    public function describe(?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '') return null;

        $this->map ??= collect(FaultCode::mapFor($this->sections->current()))
            ->mapWithKeys(fn ($desc, $c) => [mb_strtolower((string) $c) => (string) $desc])->all();

        // Несколько кодов «A; B» — «A — описание; B — описание»
        $codes = FaultCodes::split($code);
        if (count($codes) > 1) {
            $parts = array_filter(array_map(fn ($c) => ($d = $this->map[mb_strtolower($c)] ?? null) ? "{$c} — {$d}" : null, $codes));
            return $parts ? implode('; ', $parts) : null;
        }

        return ($this->map[mb_strtolower($code)] ?? null) ?: null;
    }
}
