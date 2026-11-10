<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Полный цикл по КАЖДОЙ вкладке учёта из config/sheets.php:
 * список → форма → создание → просмотр → правка → сохранение → удаление.
 * Ловит опечатки в конфиге вкладок, полях, правилах и шаблонах.
 */
class SheetsCrudTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_every_sheet_full_crud_cycle(): void
    {
        $master = $this->worker('master');
        $name = $this->nameOf($master);
        $failures = [];

        foreach (array_keys(config('sheets.map')) as $sheet) {
            try {
                $this->crudCycle($sheet, $master, $name);
            } catch (\Throwable $e) {
                $failures[] = "{$sheet}: " . strtok($e->getMessage(), "\n");
            }
        }

        $this->assertSame([], $failures, "Сломаны вкладки:\n" . implode("\n", $failures));
    }

    private function crudCycle(string $sheet, $user, string $name): void
    {
        $model = $this->modelOf($sheet);

        $this->asUser($user)->get("/records/$sheet")->assertOk();
        $this->asUser($user)->get("/records/$sheet/create")->assertOk();

        $this->asUser($user)->post("/records/$sheet", $this->validRecord($sheet, $name))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('records.index', $sheet));

        $record = $model::latest('id')->firstOrFail();
        $this->assertSame($user->id, (int) $record->created_by, 'запись должна запомнить автора');

        $this->asUser($user)->get("/records/$sheet/{$record->id}")->assertOk();
        $this->asUser($user)->get("/records/$sheet/{$record->id}/edit")->assertOk();

        // Поиск по списку находит запись
        $this->asUser($user)->get("/records/$sheet?q=" . urlencode('Тест'))->assertOk();

        $update = $this->validRecord($sheet, $name);
        $this->asUser($user)->put("/records/$sheet/{$record->id}", $update)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('records.index', $sheet));
        $this->assertSame($user->id, (int) $record->fresh()->updated_by);

        $this->asUser($user)->delete("/records/$sheet/{$record->id}")->assertRedirect(route('records.index', $sheet));
        $this->assertNull($model::find($record->id), 'запись должна удалиться');
    }

    public function test_unknown_sheet_returns_404(): void
    {
        $this->asUser($this->worker('master'))->get('/records/no-such-sheet')->assertNotFound();
    }
}
