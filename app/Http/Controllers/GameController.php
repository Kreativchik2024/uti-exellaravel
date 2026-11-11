<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BattleshipGame;
use App\Models\GameSession;
use App\Models\Setting;
use App\Services\Games\Leaderboard;
use App\Services\Games\Minesweeper;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Раздел «Игры»: турнирные таблицы, сапёр, змейка, включение/выключение (админ). */
class GameController extends Controller
{
    public function index(Request $r)
    {
        $period = array_key_exists($r->query('period'), Leaderboard::PERIODS) ? $r->query('period') : 'week';
        $board = new Leaderboard($period);
        $me = $r->user()->id;

        $myBattles = BattleshipGame::query()->with(['player1:id,name', 'player2:id,name'])
            ->where(fn ($q) => $q->where('player1_id', $me)->orWhere('player2_id', $me))
            ->whereIn('status', ['invited', 'placing', 'playing'])
            ->latest('id')->limit(10)->get();

        return view('games.index', [
            'enabled'     => Setting::gamesEnabled(),
            'period'      => $period,
            'minesweeper' => $board->minesweeper(),
            'snake'       => $board->snake(),
            'battleship'  => $board->battleship(),
            'myBattles'   => $myBattles,
        ]);
    }

    /** Админ: включить / выключить игры для всех. */
    public function toggle(Request $r)
    {
        abort_unless($r->user()->isAdmin(), 403);
        $on = ! Setting::gamesEnabled();
        Setting::set('games.enabled', $on ? 1 : 0);
        AuditLog::write('system', 'status', $on ? 'Игры включены' : 'Игры выключены', ['section_id' => null]);

        return back()->with('ok', $on ? 'Игры включены для всех сотрудников.' : 'Игры выключены. Их видит только администратор.');
    }

    /** Админ: убрать результат сотрудника из турнирной таблицы (за выбранный период). */
    public function forget(Request $r)
    {
        abort_unless($r->user()->isAdmin(), 403);
        $data = $r->validate([
            'board'   => ['required', Rule::in(Leaderboard::boards())],
            'user_id' => ['required', 'integer'],
            'period'  => ['required', Rule::in(array_keys(Leaderboard::PERIODS))],
        ]);
        $deleted = (new Leaderboard($data['period']))->forget($data['board'], (int) $data['user_id']);
        $name = \App\Models\User::find($data['user_id'])?->name ?? ('#' . $data['user_id']);
        AuditLog::write('system', 'deleted', "Удалён результат из турнирной таблицы ({$data['board']}, " . Leaderboard::PERIODS[$data['period']] . "): {$name}, партий: {$deleted}", ['section_id' => null]);

        return back()->with('ok', $deleted ? "Результат удалён ({$name}, партий: {$deleted})." : 'Нечего удалять — результат уже убран.');
    }

    // ================================================================ сапёр

    public function minesweeper()
    {
        return view('games.minesweeper', ['levels' => Minesweeper::LEVELS]);
    }

    public function minesweeperStart(Request $r)
    {
        $level = $r->validate(['level' => ['required', Rule::in(array_keys(Minesweeper::LEVELS))]])['level'];
        // Незаконченные партии этого игрока — закрываем
        GameSession::where('user_id', $r->user()->id)->where('game', 'minesweeper')->where('status', 'playing')
            ->update(['status' => 'lost', 'finished_at' => now()]);

        $s = GameSession::create(['user_id' => $r->user()->id, 'game' => 'minesweeper', 'level' => $level, 'state' => Minesweeper::newState($level), 'started_at' => now()]);

        return response()->json(['id' => $s->id] + array_intersect_key($s->state, array_flip(['rows', 'cols', 'mines'])));
    }

    public function minesweeperOpen(Request $r, GameSession $session)
    {
        abort_unless($session->user_id === $r->user()->id && $session->game === 'minesweeper', 404);
        abort_unless($session->status === 'playing', 409, 'Партия уже закончена');
        $cells = $r->validate(['cells' => ['required', 'array', 'max:8'], 'cells.*' => ['array', 'size:2'], 'cells.*.*' => ['integer', 'min:0', 'max:40']])['cells'];

        [$state, $opened, $result, $mines, $boom] = Minesweeper::open($session->state, $cells);
        $update = ['state' => $state];
        $time = null;
        if ($result) {
            $time = (int) floor(microtime(true) * 1000) - (int) $state['started_ms'];
            $update += ['status' => $result, 'finished_at' => now(), 'time_ms' => $result === 'won' ? max(1, $time) : null];
        }
        $session->update($update);

        return response()->json(['opened' => $opened, 'result' => $result, 'mines' => $mines, 'boom' => $boom, 'time_ms' => $result === 'won' ? $time : null]);
    }

    // ================================================================ змейка

    public function snake()
    {
        return view('games.snake');
    }

    public function snakeStart(Request $r)
    {
        // Время начала — по часам приложения (у базы может быть другой часовой пояс)
        $s = GameSession::create(['user_id' => $r->user()->id, 'game' => 'snake', 'started_at' => now()]);

        return response()->json(['id' => $s->id]);
    }

    /** Итог змейки. Счёт не может быть больше, чем можно набрать за время партии. */
    public function snakeFinish(Request $r, GameSession $session)
    {
        abort_unless($session->user_id === $r->user()->id && $session->game === 'snake', 404);
        abort_unless($session->status === 'playing', 409, 'Результат уже записан');
        $score = (int) $r->validate(['score' => ['required', 'integer', 'min:0', 'max:5000']])['score'];

        $seconds = max(1, $session->started_at->diffInSeconds(now()));
        $max = (int) ceil($seconds * 3) + 3;   // при скорости змейки больше ~3 яблок в секунду не съесть
        $session->update(['status' => 'finished', 'finished_at' => now(), 'score' => min($score, $max)]);

        return response()->json(['score' => min($score, $max), 'accepted' => $score <= $max]);
    }
}
