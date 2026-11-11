<?php

namespace App\Services\Games;

use App\Models\BattleshipGame;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Турнирные таблицы — отдельно по каждой игре. Рабочая статистика их не учитывает. */
class Leaderboard
{
    public const PERIODS = ['week' => 'Неделя', 'month' => 'Месяц', 'all' => 'Всё время'];
    private const TOP = 10;

    public function __construct(private string $period = 'week') {}

    private function since(): ?\Carbon\Carbon
    {
        return match ($this->period) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => null,
        };
    }

    // ---- Партии, из которых складывается каждая таблица (одни и те же условия — для показа и для удаления)

    private function minesweeperGames(string $level)
    {
        return GameSession::query()
            ->where('game', 'minesweeper')->where('level', $level)->where('status', 'won')
            ->when($this->since(), fn ($q, $d) => $q->where('finished_at', '>=', $d));
    }

    private function snakeGames()
    {
        return GameSession::query()
            ->where('game', 'snake')->where('status', 'finished')
            ->when($this->since(), fn ($q, $d) => $q->where('finished_at', '>=', $d));
    }

    /** $kind: people — победы над коллегами, bot — над компьютером, strong — над сильным компьютером. */
    private function battleshipWins(string $kind)
    {
        return BattleshipGame::query()->where('status', 'finished')->whereNotNull('winner_id')
            ->when($this->since(), fn ($q, $d) => $q->where('finished_at', '>=', $d))
            ->when($kind === 'people', fn ($q) => $q->whereNotNull('player2_id'), fn ($q) => $q->whereNull('player2_id'))
            ->when($kind === 'strong', fn ($q) => $q->where('bot_level', 'strong'))
            ->when($kind === 'bot', fn ($q) => $q->where(fn ($w) => $w->where('bot_level', 'normal')->orWhereNull('bot_level')));
    }

    /** Ключи таблиц: ms:<уровень>, snake, bs:people, bs:bot, bs:strong. */
    public static function boards(): array
    {
        $keys = array_map(fn ($l) => 'ms:' . $l, array_keys(Minesweeper::LEVELS));

        return [...$keys, 'snake', 'bs:people', 'bs:bot', 'bs:strong'];
    }

    /**
     * Убрать сотрудника из таблицы за текущий период: удаляются его партии,
     * по которым эта таблица считается. Возвращает число удалённых партий.
     */
    public function forget(string $board, int $userId): int
    {
        [$game, $kind] = array_pad(explode(':', $board, 2), 2, null);

        return match (true) {
            $game === 'ms' && isset(Minesweeper::LEVELS[$kind]) => $this->minesweeperGames($kind)->where('user_id', $userId)->delete(),
            $board === 'snake' => $this->snakeGames()->where('user_id', $userId)->delete(),
            $game === 'bs' && in_array($kind, ['people', 'bot', 'strong'], true) => $this->battleshipWins($kind)->where('winner_id', $userId)->delete(),
            default => throw new \InvalidArgumentException('Неизвестная таблица: ' . $board),
        };
    }

    /** Сапёр: лучшее время на каждом уровне. */
    public function minesweeper(): array
    {
        $out = [];
        foreach (Minesweeper::LEVELS as $level => $cfg) {
            $rows = $this->minesweeperGames($level)
                ->groupBy('user_id')
                ->select('user_id', DB::raw('MIN(time_ms) as best'), DB::raw('COUNT(*) as wins'))
                ->orderBy('best')->limit(self::TOP)->get();
            $out[$level] = ['key' => 'ms:' . $level, 'name' => $cfg['name'], 'rows' => $this->withNames($rows, fn ($r) => [
                'value' => number_format($r->best / 1000, 1, ',', '') . ' с', 'extra' => $r->wins . ' побед',
            ])];
        }

        return $out;
    }

    /** Змейка: лучший счёт. */
    public function snake(): array
    {
        $rows = $this->snakeGames()
            ->groupBy('user_id')
            ->select('user_id', DB::raw('MAX(score) as best'), DB::raw('COUNT(*) as games'))
            ->orderByDesc('best')->limit(self::TOP)->get();

        return $this->withNames($rows, fn ($r) => ['value' => $r->best . ' очк.', 'extra' => $r->games . ' игр']);
    }

    /** Морской бой: победы над коллегами и над компьютером отдельно. */
    public function battleship(): array
    {
        $top = fn (string $kind) => $this->battleshipWins($kind)->groupBy('winner_id')
            ->select('winner_id as user_id', DB::raw('COUNT(*) as wins'))->orderByDesc('wins')->limit(self::TOP)->get();
        $fmt = fn ($r) => ['value' => $r->wins . ' побед', 'extra' => ''];

        return [
            'people' => $this->withNames($top('people'), $fmt),
            'bot'    => $this->withNames($top('bot'), $fmt),
            'strong' => $this->withNames($top('strong'), $fmt),
        ];
    }

    private function withNames($rows, callable $fmt): array
    {
        $names = User::query()->whereIn('users.id', $rows->pluck('user_id'))
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->get(['users.id', 'users.name', 'employees.short_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim((string) ($u->short_name ?: $u->name))]);

        return $rows->values()->map(fn ($r, $i) => ['place' => $i + 1, 'user_id' => (int) $r->user_id, 'name' => $names[$r->user_id] ?? '—'] + $fmt($r))->all();
    }
}
