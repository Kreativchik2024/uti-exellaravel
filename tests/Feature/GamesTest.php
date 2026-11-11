<?php

namespace Tests\Feature;

use App\Models\BattleshipGame;
use App\Models\ChatMessage;
use App\Models\GameSession;
use App\Models\Setting;
use App\Models\User;
use App\Services\Games\Battleship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Игры: включение админом, сапёр, змейка, морской бой, турнирные таблицы. */
class GamesTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function enable(): void
    {
        Setting::set('games.enabled', 1);
    }

    public function test_games_are_off_by_default_and_admin_toggles_them(): void
    {
        $worker = $this->worker();
        $admin = User::factory()->admin()->create();

        $this->asUser($worker)->get('/games')->assertRedirect(route('dashboard'));
        $this->asUser($worker)->get('/profile')->assertDontSee(route('games.index'));
        $this->asUser($admin)->get('/games')->assertOk()->assertSee('Игры выключены');

        $this->asUser($worker)->post('/games/toggle')->assertRedirect(route('dashboard'));   // выключено — раздел закрыт
        $this->asUser($admin)->post('/games/toggle')->assertSessionHas('ok');
        $this->assertTrue(Setting::gamesEnabled());
        $this->asUser($worker)->get('/games')->assertOk()->assertSee('Турнирные таблицы');
        $this->asUser($worker)->post('/games/toggle')->assertForbidden();   // переключает только админ
    }

    public function test_minesweeper_first_click_is_safe_and_win_goes_to_leaderboard(): void
    {
        $this->enable();
        $u = $this->worker();
        $start = $this->asUser($u)->postJson('/games/minesweeper', ['level' => 'easy'])->assertOk()->json();
        $this->assertArrayNotHasKey('mine', $start, 'мины браузеру не отдаются');

        $first = $this->asUser($u)->postJson("/games/minesweeper/{$start['id']}/open", ['cells' => [[4, 4]]])->assertOk()->json();
        $this->assertNull($first['result'], 'первый клик никогда не подрывает');

        // Открываем все безопасные клетки (тест знает мины из базы — браузер их не знает)
        $mines = array_flip(GameSession::find($start['id'])->state['mine']);
        $result = null;
        for ($r = 0; $r < 9 && ! $result; $r++) {
            for ($c = 0; $c < 9 && ! $result; $c++) {
                if (isset($mines["$r,$c"])) continue;
                $res = $this->asUser($u)->postJson("/games/minesweeper/{$start['id']}/open", ['cells' => [[$r, $c]]])->json();
                $result = $res['result'] ?? null;
            }
        }
        $this->assertSame('won', $result);
        $this->assertNotNull(GameSession::find($start['id'])->time_ms);
        $this->asUser($u)->postJson("/games/minesweeper/{$start['id']}/open", ['cells' => [[0, 0]]])->assertStatus(409);
        $this->asUser($u)->get('/games')->assertSee($this->nameOf($u));

        // Чужая партия недоступна
        $this->asUser($this->worker())->postJson("/games/minesweeper/{$start['id']}/open", ['cells' => [[0, 0]]])->assertNotFound();
    }

    public function test_snake_score_is_capped_by_time(): void
    {
        $this->enable();
        $u = $this->worker();
        $id = $this->asUser($u)->postJson('/games/snake')->json('id');

        $this->travel(10)->seconds();
        $res = $this->asUser($u)->postJson("/games/snake/{$id}/finish", ['score' => 4000])->assertOk()->json();
        $this->assertFalse($res['accepted']);
        $this->assertLessThanOrEqual(40, $res['score'], 'за 10 секунд столько не набрать');
        $this->asUser($u)->postJson("/games/snake/{$id}/finish", ['score' => 5])->assertStatus(409);   // повторно не записать
    }

    public function test_random_fleet_follows_rules(): void
    {
        foreach (range(1, 30) as $_) {
            $ships = Battleship::randomFleet();
            $this->assertSame([4, 3, 3, 2, 2, 2, 1, 1, 1, 1], array_map('count', $ships));
            $cells = [];
            foreach ($ships as $i => $ship) foreach ($ship as [$r, $c]) {
                $this->assertTrue($r >= 0 && $r < 10 && $c >= 0 && $c < 10);
                $cells["$r,$c"] = $i;
            }
            foreach ($cells as $k => $i) {   // корабли не касаются даже углами
                [$r, $c] = explode(',', $k);
                for ($dr = -1; $dr <= 1; $dr++) for ($dc = -1; $dc <= 1; $dc++) {
                    $n = ($r + $dr) . ',' . ($c + $dc);
                    if (isset($cells[$n])) $this->assertSame($i, $cells[$n]);
                }
            }
        }
    }

    public function test_battleship_vs_bot_until_victory(): void
    {
        $this->enable();
        $u = $this->worker();
        $this->asUser($u)->post('/games/battleship/bot')->assertRedirect();
        $game = BattleshipGame::latest('id')->firstOrFail();

        $this->asUser($u)->postJson("/games/battleship/{$game->id}/ready")->assertJsonPath('status', 'playing');

        // Бот отвечает сразу после промаха; стреляем по его кораблям, пока не победим
        $targets = array_merge(...$game->ships2);
        $state = null;
        foreach ($targets as [$r, $c]) {
            $state = $this->asUser($u)->postJson("/games/battleship/{$game->id}/shoot", ['r' => $r, 'c' => $c])->json();
            if (($state['status'] ?? null) === 'finished') break;
        }
        $this->assertSame('finished', $state['status']);
        $this->assertSame('me', $state['winner']);
        $this->asUser($u)->get('/games')->assertSee('Морской бой · победы над компьютером')->assertSee($this->nameOf($u));
    }

    public function test_battleship_invite_via_chat_and_turns(): void
    {
        $this->enable();
        [$a, $b, $outsider] = [$this->worker(), $this->worker(), $this->worker()];

        $url = $this->asUser($a)->postJson('/games/battleship/invite', ['user_id' => $b->id])->assertOk()->json('url');
        $game = BattleshipGame::latest('id')->firstOrFail();
        $this->assertStringContainsString("/games/battleship/{$game->id}", ChatMessage::where('recipient_id', $b->id)->value('body'), 'приглашение пришло в личный чат');

        $this->asUser($outsider)->get($url)->assertNotFound();
        $this->asUser($b)->postJson("/games/battleship/{$game->id}/accept")->assertJsonPath('status', 'placing');
        $this->asUser($a)->postJson("/games/battleship/{$game->id}/ready");
        $this->asUser($b)->postJson("/games/battleship/{$game->id}/ready")->assertJsonPath('status', 'playing');

        // Первым ходит пригласивший; соперник стрелять не может
        $this->asUser($b)->postJson("/games/battleship/{$game->id}/shoot", ['r' => 0, 'c' => 0])->assertStatus(409);
        $state = $this->asUser($a)->getJson("/games/battleship/{$game->id}/state")->json();
        $this->assertTrue($state['my_turn']);
        $this->assertArrayNotHasKey('their_ships', $state, 'корабли соперника не видны до конца партии');

        $this->asUser($a)->postJson("/games/battleship/{$game->id}/surrender")->assertJsonPath('winner', 'them');
    }

    // ---------------------------------------------------------------- расстановка и сильный компьютер

    public function test_fleet_validation(): void
    {
        $this->assertTrue(Battleship::validFleet(Battleship::randomFleet()));

        $good = [[[0,0],[0,1],[0,2],[0,3]], [[2,0],[2,1],[2,2]], [[4,0],[4,1],[4,2]], [[6,0],[6,1]], [[8,0],[8,1]], [[0,6],[0,7]],
                 [[9,9]], [[9,7]], [[7,9]], [[5,9]]];
        $this->assertTrue(Battleship::validFleet($good));

        $touch = $good; $touch[9] = [[1,4]];                          // касается углом четырёхпалубного
        $this->assertFalse(Battleship::validFleet($touch));
        $gap = $good; $gap[0] = [[0,0],[0,1],[0,3],[0,4]];            // с разрывом
        $this->assertFalse(Battleship::validFleet($gap));
        $bent = $good; $bent[1] = [[2,0],[2,1],[3,1]];                // буквой Г
        $this->assertFalse(Battleship::validFleet($bent));
        $this->assertFalse(Battleship::validFleet(array_slice($good, 0, 9)), 'не весь флот');
        $this->assertFalse(Battleship::validFleet('мусор'));
    }

    public function test_manual_placement_is_checked_by_server(): void
    {
        $this->enable();
        $u = $this->worker();
        $this->asUser($u)->post('/games/battleship/bot', ['level' => 'strong']);
        $game = BattleshipGame::latest('id')->firstOrFail();
        $this->assertSame('strong', $game->bot_level);

        $mine = [[[0,0],[0,1],[0,2],[0,3]], [[2,0],[2,1],[2,2]], [[4,0],[4,1],[4,2]], [[6,0],[6,1]], [[8,0],[8,1]], [[0,6],[0,7]],
                 [[9,9]], [[9,7]], [[7,9]], [[5,9]]];
        $this->asUser($u)->postJson("/games/battleship/{$game->id}/place", ['ships' => [[[0,0],[1,0]]]])->assertStatus(422);
        $this->asUser($u)->postJson("/games/battleship/{$game->id}/place", ['ships' => $mine])->assertOk()->assertJsonPath('my_ships', $mine);
        $this->asUser($u)->postJson("/games/battleship/{$game->id}/ready")->assertJsonPath('status', 'playing');
        $this->asUser($u)->postJson("/games/battleship/{$game->id}/place", ['ships' => $mine])->assertStatus(409);   // после «Готов» — нельзя
    }

    /** Сколько выстрелов нужно компьютеру, чтобы потопить случайный флот. */
    private function shotsToWin(bool $strong): int
    {
        $ships = Battleship::randomFleet();
        $shots = [];
        for ($n = 1; $n <= 100; $n++) {
            [$r, $c] = $strong ? Battleship::strongTarget($shots, Battleship::sunkShips($ships, $shots)) : Battleship::casualTarget($shots);
            $this->assertArrayNotHasKey("$r,$c", $shots, 'компьютер не стреляет дважды в одну клетку');
            [$shots, , $won] = Battleship::shoot($ships, $shots, $r, $c);
            if ($won) return $n;
        }
        $this->fail('компьютер не смог победить за 100 выстрелов');
    }

    public function test_strong_bot_wins_in_fewer_shots(): void
    {
        $normal = $strong = 0;
        foreach (range(1, 40) as $_) {
            $normal += $this->shotsToWin(false);
            $strong += $this->shotsToWin(true);
        }
        $this->assertLessThan($normal / 40, $strong / 40, 'сильный компьютер в среднем побеждает быстрее');
        $this->assertLessThan(59, $strong / 40, 'и в среднем укладывается меньше чем в 59 выстрелов');
        fwrite(STDERR, sprintf("
  морской бой: обычный — %.1f выстр., сильный — %.1f выстр. в среднем
", $normal / 40, $strong / 40));
    }

    public function test_admin_removes_any_result_from_leaderboard(): void
    {
        $this->enable();
        $admin = User::factory()->admin()->create();
        $cheater = $this->worker();
        $honest = $this->worker();

        $snake = fn (User $u, int $score, $at) => GameSession::create(['user_id' => $u->id, 'game' => 'snake', 'status' => 'finished', 'score' => $score, 'finished_at' => $at]);
        $snake($cheater, 9999, now());
        $snake($cheater, 50, now());
        $old = $snake($cheater, 70, now()->subMonths(2));          // за прошлые месяцы — не в «Неделе»
        $snake($honest, 120, now());
        GameSession::create(['user_id' => $cheater->id, 'game' => 'minesweeper', 'level' => 'easy', 'status' => 'won', 'time_ms' => 900, 'finished_at' => now()]);
        $win = fn (?string $level, ?User $p2 = null) => BattleshipGame::create(['player1_id' => $cheater->id, 'player2_id' => $p2?->id, 'bot_level' => $level, 'status' => 'finished', 'winner_id' => $cheater->id, 'finished_at' => now()]);
        $win('normal');
        $strong = $win('strong');
        $win(null, $honest);

        // Кнопку видит только админ
        $this->asUser($admin)->get('/games')->assertOk()->assertSee('Удалить результат');
        $this->asUser($honest)->get('/games')->assertOk()->assertDontSee('Удалить результат');

        // Работник удалить не может
        $this->asUser($honest)->delete('/games/board', ['board' => 'snake', 'user_id' => $cheater->id, 'period' => 'week'])->assertForbidden();
        $this->assertSame(4, GameSession::where('game', 'snake')->count());

        // Админ убирает змейку за неделю: строка пропадает, честный игрок и старые партии не тронуты
        $this->asUser($admin)->delete('/games/board', ['board' => 'snake', 'user_id' => $cheater->id, 'period' => 'week'])->assertSessionHas('ok');
        $board = (new \App\Services\Games\Leaderboard('week'))->snake();
        $this->assertSame([$honest->id], array_column($board, 'user_id'));
        $this->assertNotNull($old->fresh());
        $this->assertSame(1, GameSession::where('game', 'minesweeper')->count(), 'другие таблицы не тронуты');

        // Сапёр и морской бой (только выбранная таблица)
        $this->asUser($admin)->delete('/games/board', ['board' => 'ms:easy', 'user_id' => $cheater->id, 'period' => 'all'])->assertSessionHas('ok');
        $this->assertSame(0, GameSession::where('game', 'minesweeper')->count());
        $this->asUser($admin)->delete('/games/board', ['board' => 'bs:bot', 'user_id' => $cheater->id, 'period' => 'all'])->assertSessionHas('ok');
        $this->assertSame(2, BattleshipGame::count(), 'остались победы над сильным компьютером и над коллегой');
        $this->assertNotNull($strong->fresh());

        // Неизвестная таблица — ошибка проверки, а не 500
        $this->asUser($admin)->delete('/games/board', ['board' => 'chess', 'user_id' => $cheater->id, 'period' => 'week'])->assertSessionHasErrors('board');
        $this->assertDatabaseHas('audit_logs', ['area' => 'system', 'action' => 'deleted']);
    }
}
