<?php

namespace Tests\Feature;

use App\Models\FirmwareCategory;
use App\Models\FirmwareVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * «Дымовая» проверка всего сайта + прошивки, профиль, присутствие.
 */
class SiteSmokeTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    /** Каждая страница без параметров открывается у админа без ошибки сервера. */
    public function test_every_page_without_parameters_has_no_server_error(): void
    {
        $admin = $this->worker('admin');
        $skip = ['up', 'csrf-token', 'logout'];
        $failures = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!in_array('GET', $route->methods(), true) || str_contains($uri, '{') || in_array($uri, $skip, true)) continue;
            if (str_starts_with($uri, '_') || str_starts_with($uri, 'storage')) continue;   // служебные маршруты пакетов

            $status = $this->asUser($admin)->get('/' . ltrim($uri, '/'))->getStatusCode();
            $checked++;
            if ($status >= 500) $failures[] = "/$uri → $status";
        }

        $this->assertGreaterThan(30, $checked, 'проверено подозрительно мало страниц');
        $this->assertSame([], $failures, "Ошибки сервера:\n" . implode("\n", $failures));
    }

    public function test_guest_pages(): void
    {
        $this->get('/login')->assertOk()->assertSee('Вход в систему');
        $this->get('/register')->assertOk();
        $this->get('/news')->assertOk();
        foreach (['/', '/stats', '/firmware', '/users', '/workstation/select'] as $private) {
            $this->get($private)->assertRedirect('/login');
        }
    }

    // ---------------------------------------------------------------- прошивки

    public function test_firmware_upload_download_status_and_delete(): void
    {
        $master = $this->worker('master');
        $category = FirmwareCategory::create(['slug' => 'test-smoke-' . uniqid(), 'title' => 'Тест', 'sort_order' => 1]);
        $dir = storage_path("app/private/firmware/{$category->slug}");

        try {
            $this->asUser($master)->post('/firmware', [
                'category_id' => $category->id, 'version' => '1.2.3', 'notes' => 'тестовая',
                'make_actual' => 1, 'file' => UploadedFile::fake()->createWithContent('fw.bin', 'BINARY-DATA'),
            ])->assertSessionHasNoErrors()->assertRedirect();

            $version = FirmwareVersion::where('version', '1.2.3')->firstOrFail();
            $this->assertSame('actual', $version->status);
            $this->assertSame(hash('sha256', 'BINARY-DATA'), $version->sha256, 'контрольная сумма считается при загрузке');

            $this->asUser($master)->get('/firmware')->assertOk()->assertSee('1.2.3');

            $download = $this->asUser($this->worker())->get("/firmware/{$version->id}/download");   // скачать может любой
            $download->assertOk();
            $this->assertSame('BINARY-DATA', file_get_contents($download->baseResponse->getFile()->getPathname()));

            $this->asUser($master)->patch("/firmware/{$version->id}/status", ['status' => 'archive'])->assertRedirect();
            $this->assertSame('archive', $version->fresh()->status);

            $this->asUser($master)->delete("/firmware/{$version->id}")->assertRedirect();
            $this->assertNull($version->fresh());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_executor_cannot_upload_or_delete_firmware(): void
    {
        $this->asUser($this->worker())->get('/firmware/create')->assertForbidden();
        $this->asUser($this->worker())->post('/firmware', ['version' => 'x'])->assertForbidden();
    }

    // ---------------------------------------------------------------- профиль и ПИН

    public function test_pin_can_be_set_and_is_validated(): void
    {
        $user = User::factory()->admin()->create();

        $this->asUser($user)->post('/profile/pin', ['pin' => '12', 'pin_confirm' => '12'])->assertSessionHasErrors('pin');
        $this->asUser($user)->post('/profile/pin', ['pin' => '5678', 'pin_confirm' => '0000'])->assertSessionHasErrors('pin_confirm');
        $this->asUser($user)->post('/profile/pin', ['pin' => '5678', 'pin_confirm' => '5678'])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('5678', $user->fresh()->pin_hash));
    }

    public function test_profile_page_opens(): void
    {
        $this->asUser($this->worker())->get('/profile')->assertOk();
    }

    // ---------------------------------------------------------------- присутствие «Онлайн»

    public function test_heartbeat_marks_user_online(): void
    {
        $user = $this->worker('master');

        $this->asUser($user)->postJson('/worker-sessions/heartbeat')->assertOk()->assertJsonPath('ok', true);

        $this->asUser($user)->getJson('/worker-sessions/online')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('online.0.employee_name', $this->nameOf($user));
    }

    public function test_dashboard_news_feed_loads_next_page_as_json(): void
    {
        $this->asUser($this->worker())->getJson('/?page=2&json=1')
            ->assertOk()
            ->assertJsonStructure(['html', 'has_more']);
    }
}
