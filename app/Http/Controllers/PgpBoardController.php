<?php

namespace App\Http\Controllers;

use App\Services\BoardHistory;
use App\Services\PgpBoardLookup;
use Illuminate\Http\Request;

/**
 * Форма ПГП: «Что известно об этом борте» — по введённым номерам (моторама, фюзеляж, Кросс 3, кронштейн).
 * Борт с таким фюзеляжем уже есть — сверху подсказка открыть его запись вместо новой (PgpBoardLookup).
 * Модуль связи не учитывается — он переходит с номером борта на другой борт. Отдаёт готовый HTML блока или пусто.
 */
class PgpBoardController extends Controller
{
    use Concerns\RecordSheets;

    public function journal(Request $r, string $sheet, PgpBoardLookup $lookup, BoardHistory $history)
    {
        abort_unless($sheet === 'pgp', 404);
        $this->cfg($sheet);   // доступ к вкладке на участке

        $numbers = $r->validate([
            'frame_serial'    => ['nullable', 'string', 'max:120'],
            'fuselage_number' => ['nullable', 'string', 'max:120'],
            'cross3_number'   => ['nullable', 'string', 'max:120'],
            'bracket_serial'  => ['nullable', 'string', 'max:120'],
            'except'          => ['nullable', 'integer'],
        ]);
        $exceptId = (int) ($numbers['except'] ?? 0) ?: null;   // форма правки — сама запись не в счёт
        unset($numbers['except']);
        $numbers = array_filter(array_map(fn ($v) => trim((string) $v), $numbers), fn ($v) => $v !== '');
        if (! $numbers) return response('');

        $existing = $lookup->check($numbers['fuselage_number'] ?? null, $exceptId);

        return response(
            ($existing ? view('records._board_existing', ['existing' => $existing, 'isEdit' => (bool) $exceptId])->render() : '')
            . view('records._board_journal', [
                'boardHistory' => $history->forNumbers($numbers),
                'title'        => 'Что известно об этом борте',
                'sectionId'    => 'board-journal-draft',
                'draft'        => true,
            ])->render()
        );
    }
}
