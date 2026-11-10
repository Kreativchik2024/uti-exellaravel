<?php

namespace App\Http\Controllers;

use App\Models\BattleshipGame;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Games\Battleship;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Морской бой: с компьютером или с коллегой (приглашение уходит в личный чат).
 * Корабли соперника — только на сервере; браузер видит лишь результаты выстрелов.
 */
class BattleshipController extends Controller
{
    /** Приглашение просрочено, если его не приняли за это время. */
    private const INVITE_MINUTES = 30;

    public function lobby(Request $r)
    {
        return redirect()->route('games.index');
    }

    /** Игра с компьютером (обычный или сильный): сразу расстановка. */
    public function vsBot(Request $r)
    {
        $level = $r->input('level') === 'strong' ? 'strong' : 'normal';
        $game = BattleshipGame::create([
            'player1_id' => $r->user()->id, 'player2_id' => null, 'bot_level' => $level, 'status' => 'placing',
            'ships1' => Battleship::randomFleet(), 'ships2' => Battleship::randomFleet(),
            'shots1' => [], 'shots2' => [], 'ready2' => true, 'turn' => 1, 'last_move_at' => now(),
        ]);

        return redirect()->route('games.battleship.show', $game);
    }

    /** Пригласить коллегу: партия + сообщение в личный чат со ссылкой. */
    public function invite(Request $r)
    {
        $data = $r->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $me = $r->user();
        $peer = User::where('id', $data['user_id'])->where('is_active', true)->firstOrFail();
        abort_if($peer->id === $me->id, 422, 'Нельзя пригласить самого себя');

        $game = DB::transaction(function () use ($me, $peer) {
            $game = BattleshipGame::create([
                'player1_id' => $me->id, 'player2_id' => $peer->id, 'status' => 'invited',
                'ships1' => Battleship::randomFleet(), 'ships2' => Battleship::randomFleet(),
                'shots1' => [], 'shots2' => [], 'turn' => 1, 'last_move_at' => now(),
            ]);
            ChatMessage::create([
                'sender_id' => $me->id, 'recipient_id' => $peer->id,
                'body' => 'Приглашаю сыграть в морской бой: ' . route('games.battleship.show', $game, false),
            ]);

            return $game;
        });

        return $r->expectsJson()
            ? response()->json(['url' => route('games.battleship.show', $game)])
            : redirect()->route('games.battleship.show', $game);
    }

    public function show(Request $r, BattleshipGame $game)
    {
        abort_unless($game->seat($r->user()->id), 404);

        return view('games.battleship', ['game' => $game, 'state' => $this->state($game, $r->user()->id)]);
    }

    /** Состояние партии для игрока (браузер опрашивает каждые 2 с). */
    public function state(BattleshipGame $game, ?int $userId = null)
    {
        $userId ??= request()->user()->id;
        $seat = $game->seat($userId);
        abort_unless($seat, 404);
        $this->expire($game);

        $other = $seat === 1 ? 2 : 1;
        $opponent = $game->vsBot() ? null : ($seat === 1 ? $game->player2 : $game->player1);
        $myShots = (array) $game->{"shots$seat"};
        $theirShots = (array) $game->{"shots$other"};

        $data = [
            'id'        => $game->id,
            'status'    => $game->status,
            'seat'      => $seat,
            'my_turn'   => $game->status === 'playing' && $game->turn === $seat,
            'opponent'  => $opponent ? trim((string) ($opponent->employee?->short_name ?: $opponent->name)) : ($game->bot_level === 'strong' ? 'Сильный компьютер' : 'Компьютер'),
            'bot_level' => $game->bot_level,
            'vs_bot'    => $game->vsBot(),
            'ready'     => (bool) $game->{"ready$seat"},
            'opp_ready' => (bool) $game->{"ready$other"},
            'my_ships'  => $game->{"ships$seat"},
            'their_shots' => $theirShots,                 // выстрелы соперника по моему полю
            'my_shots'  => $myShots,                      // мои выстрелы по полю соперника
            'sunk'      => Battleship::sunkShips((array) $game->{"ships$other"}, $myShots),   // только потопленные — их видно
            'winner'    => $game->status === 'finished' ? ($game->winner_bot ? 'bot' : ((int) $game->winner_id === $userId ? 'me' : 'them')) : null,
            'can_accept' => $game->status === 'invited' && $seat === 2,
        ];
        // При окончании показываем весь флот соперника
        if ($game->status === 'finished') $data['their_ships'] = $game->{"ships$other"};

        return request()->expectsJson() || request()->routeIs('games.battleship.state')
            ? response()->json($data)->header('Cache-Control', 'no-store')
            : $data;
    }

    public function accept(Request $r, BattleshipGame $game)
    {
        abort_unless($game->seat($r->user()->id) === 2 && $game->status === 'invited', 409);
        $game->update(['status' => 'placing', 'last_move_at' => now()]);

        return $this->state($game->fresh());
    }

    public function decline(Request $r, BattleshipGame $game)
    {
        $seat = $game->seat($r->user()->id);
        abort_unless($seat && in_array($game->status, ['invited', 'placing'], true), 409);
        $game->update(['status' => $seat === 2 && $game->status === 'invited' ? 'declined' : 'cancelled', 'finished_at' => now()]);

        return redirect()->route('games.index')->with('ok', 'Партия отменена');
    }

    /** Перемешать свои корабли (до «Готов»). */
    public function shuffle(Request $r, BattleshipGame $game)
    {
        $seat = $game->seat($r->user()->id);
        abort_unless($seat && in_array($game->status, ['invited', 'placing'], true) && ! $game->{"ready$seat"}, 409);
        $game->update(["ships$seat" => Battleship::randomFleet()]);

        return $this->state($game->fresh());
    }

    /** Своя расстановка (ручная). Сервер проверяет правила. */
    public function place(Request $r, BattleshipGame $game)
    {
        $seat = $game->seat($r->user()->id);
        abort_unless($seat && in_array($game->status, ['invited', 'placing'], true) && ! $game->{"ready$seat"}, 409);
        $ships = $r->input('ships');
        if (! Battleship::validFleet($ships)) {
            return response()->json(['message' => 'Расстановка не по правилам: нужны 1×4, 2×3, 3×2, 4×1, корабли не должны касаться.'], 422);
        }
        $game->update(["ships$seat" => $ships]);

        return $this->state($game->fresh());
    }

    public function ready(Request $r, BattleshipGame $game)
    {
        $seat = $game->seat($r->user()->id);
        abort_unless($seat && $game->status === 'placing', 409);
        $game->{"ready$seat"} = true;
        if ($game->ready1 && $game->ready2) {
            $game->status = 'playing';
            $game->turn = 1;
        }
        $game->last_move_at = now();
        $game->save();

        return $this->state($game->fresh());
    }

    public function shoot(Request $r, BattleshipGame $game)
    {
        $cell = $r->validate(['r' => ['required', 'integer', 'min:0', 'max:9'], 'c' => ['required', 'integer', 'min:0', 'max:9']]);
        $me = $r->user()->id;

        DB::transaction(function () use ($game, $cell, $me) {
            $game = BattleshipGame::lockForUpdate()->findOrFail($game->id);
            $seat = $game->seat($me);
            abort_unless($seat && $game->status === 'playing' && $game->turn === $seat, 409, 'Сейчас не ваш ход');
            $other = $seat === 1 ? 2 : 1;

            [$shots, $result, $won] = Battleship::shoot((array) $game->{"ships$other"}, (array) $game->{"shots$seat"}, $cell['r'], $cell['c']);
            if ($result === 'repeat') return;
            $game->{"shots$seat"} = $shots;

            if ($won) {
                $this->finish($game, $me);
            } elseif ($result === 'miss') {
                $game->turn = $other;
                if ($game->vsBot()) $this->botMoves($game);
            }
            $game->last_move_at = now();
            $game->save();
        });

        return $this->state($game->fresh());
    }

    public function surrender(Request $r, BattleshipGame $game)
    {
        $seat = $game->seat($r->user()->id);
        abort_unless($seat && $game->status === 'playing', 409);
        if ($game->vsBot()) {
            $game->forceFill(['status' => 'finished', 'winner_bot' => true, 'finished_at' => now()])->save();
        } else {
            $this->finish($game, $seat === 1 ? $game->player2_id : $game->player1_id);
            $game->save();
        }

        return $this->state($game->fresh());
    }

    /** Ходы компьютера: стреляет, пока попадает. */
    private function botMoves(BattleshipGame $game): void
    {
        $shots = (array) $game->shots2;
        $strong = $game->bot_level === 'strong';
        for ($i = 0; $i < 100; $i++) {
            [$r, $c] = $strong
                ? Battleship::strongTarget($shots, Battleship::sunkShips((array) $game->ships1, $shots))
                : Battleship::casualTarget($shots);
            [$shots, $result, $won] = Battleship::shoot((array) $game->ships1, $shots, $r, $c);
            if ($won) {
                $game->shots2 = $shots;
                $game->forceFill(['status' => 'finished', 'winner_bot' => true, 'finished_at' => now()]);
                return;
            }
            if ($result === 'miss') break;
        }
        $game->shots2 = $shots;
        $game->turn = 1;
    }

    private function finish(BattleshipGame $game, int $winnerId): void
    {
        $game->status = 'finished';
        $game->winner_id = $winnerId;
        $game->finished_at = now();
    }

    /** Непринятое приглашение и брошенная расстановка — отменяются. */
    private function expire(BattleshipGame $game): void
    {
        if (in_array($game->status, ['invited', 'placing'], true) && $game->updated_at->lt(now()->subMinutes(self::INVITE_MINUTES))) {
            $game->update(['status' => 'cancelled', 'finished_at' => now()]);
        }
    }
}
