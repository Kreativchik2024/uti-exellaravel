<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Участки: у каждого свой учёт, статистика и новости;
 * пользователь видит только назначенные участки (админ — все).
 */
class SectionTest extends TestCase
{
    use RefreshDatabase;

    private Section $testing;   // создаётся миграцией: «Участок испытаний» (все текущие вкладки)
    private Section $assembly;  // новый участок без вкладок учёта

    protected function setUp(): void
    {
        parent::setUp();
        $this->testing = Section::where('slug', 'ispytaniya')->firstOrFail();
        $this->assembly = Section::create(['slug' => 'sborka', 'name' => 'Участок сборки', 'has_firmware' => false, 'sort_order' => 2]);
    }

    /** Мастер: у исполнителей «один вход на устройство» мешает серии запросов в тесте. */
    private function master(Section ...$sections): User
    {
        $user = User::factory()->master()->create();
        $user->sections()->sync(collect($sections)->pluck('id'));
        return $user;
    }

    private function news(?Section $section, string $title): News
    {
        return News::create([
            'section_id' => $section?->id, 'title' => $title, 'body' => 'текст',
            'is_published' => true, 'author_id' => User::factory()->admin()->create()->id, 'published_at' => now()->subMinute(),
        ]);
    }

    // ---------------------------------------------------------------- учёт

    public function test_user_sees_records_only_of_assigned_section(): void
    {
        $this->actingAs($this->master($this->testing))->withSession(['workstation_workers' => []])->get('/records/pgp')->assertOk();
        $this->actingAs($this->master($this->assembly))->withSession(['workstation_workers' => []])->get('/records/pgp')->assertForbidden();
    }

    public function test_opening_sheet_of_other_accessible_section_switches_current_section(): void
    {
        $user = $this->master($this->testing, $this->assembly);

        $this->actingAs($user)->post('/section/switch', ['section_id' => $this->assembly->id])->assertRedirect(route('dashboard'));
        $this->assertSame($this->assembly->id, session('section_id'));

        $this->actingAs($user)->withSession(['workstation_workers' => []])->get('/records/pgp')->assertOk();
        $this->assertSame($this->testing->id, session('section_id'));
    }

    public function test_cannot_switch_to_section_without_access(): void
    {
        $this->actingAs($this->master($this->testing))
            ->post('/section/switch', ['section_id' => $this->assembly->id])
            ->assertForbidden();
    }

    public function test_admin_sees_all_sections(): void
    {
        $admin = User::factory()->admin()->create();   // участки не назначены — админу и не нужно

        $this->actingAs($admin)->post('/section/switch', ['section_id' => $this->assembly->id])->assertRedirect();
        $this->actingAs($admin)->withSession(['workstation_workers' => []])->get('/records/pgp')->assertOk();
    }

    public function test_navigation_shows_sheets_of_current_section(): void
    {
        $user = $this->master($this->testing, $this->assembly);

        $this->actingAs($user)->withSession(['workstation_workers' => []])->get('/')
            ->assertSee('ПГП (борта)')->assertSee('Участок испытаний');

        $this->actingAs($user)->post('/section/switch', ['section_id' => $this->assembly->id]);
        $this->actingAs($user)->withSession(['workstation_workers' => []])->get('/')
            ->assertDontSee('ПГП (борта)')
            ->assertSee('У этого участка пока нет вкладок учёта');
    }

    public function test_statistics_use_only_current_section_sheets(): void
    {
        $user = $this->master($this->assembly);

        $this->actingAs($user)->withSession(['workstation_workers' => []])->get('/stats')
            ->assertOk()
            ->assertDontSee('ПГП (борта)');
        $this->actingAs($user)->withSession(['workstation_workers' => []])->getJson('/stats/zone2?sheet=pgp')->assertNotFound();
    }

    // ---------------------------------------------------------------- прошивки

    public function test_firmware_only_in_sections_where_enabled(): void
    {
        $this->actingAs($this->master($this->testing))->withSession(['workstation_workers' => []])->get('/firmware')->assertOk();
        $this->actingAs($this->master($this->assembly))->withSession(['workstation_workers' => []])->get('/firmware')->assertNotFound();
    }

    // ---------------------------------------------------------------- новости

    public function test_news_are_filtered_by_section_and_general_news_are_visible_everywhere(): void
    {
        $this->news($this->testing, 'Новость испытаний');
        $assemblyNews = $this->news($this->assembly, 'Новость сборки');
        $this->news(null, 'Общая новость');

        $user = $this->master($this->testing);

        $this->actingAs($user)->get('/news')
            ->assertSee('Новость испытаний')
            ->assertSee('Общая новость')
            ->assertDontSee('Новость сборки');

        $this->actingAs($user)->get("/news/{$assemblyNews->id}")->assertNotFound();
    }

    public function test_only_admin_can_publish_news_for_all_sections(): void
    {
        $master = $this->master($this->testing);

        $this->actingAs($master)->post('/news', ['body' => 'для всех', 'section_id' => 'all'])->assertForbidden();
        $this->actingAs($master)->post('/news', ['body' => 'своему участку', 'section_id' => $this->testing->id])->assertRedirect();
        $this->actingAs($master)->post('/news', ['body' => 'чужому участку', 'section_id' => $this->assembly->id])->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/news', ['body' => 'для всех от админа', 'section_id' => 'all'])->assertRedirect();

        $this->assertDatabaseHas('news', ['body' => 'своему участку', 'section_id' => $this->testing->id]);
        $this->assertDatabaseHas('news', ['body' => 'для всех от админа', 'section_id' => null]);
        $this->assertDatabaseMissing('news', ['body' => 'чужому участку']);
    }

    // ---------------------------------------------------------------- админка

    public function test_admin_creates_section_and_assigns_users(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->master($this->testing);

        $this->actingAs($admin)->post('/sections', ['name' => 'Участок упаковки'])->assertRedirect();
        $packing = Section::where('name', 'Участок упаковки')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $packing->slug);   // код — латиницей
        $this->assertSame((int) Section::max('sort_order'), $packing->sort_order); // новый — в конце списка

        $this->actingAs($admin)->patch("/users/{$user->id}/sections", ['sections' => [$packing->id]])->assertRedirect();
        $this->assertEquals([$packing->id], $user->sections()->pluck('sections.id')->all());
    }

    public function test_non_admin_cannot_manage_sections(): void
    {
        $this->actingAs($this->master($this->testing))->get('/sections')->assertForbidden();
        $this->actingAs($this->master($this->testing))->post('/sections', ['name' => 'X'])->assertForbidden();
    }

    public function test_approving_user_assigns_admins_current_section(): void
    {
        $admin = User::factory()->admin()->create();
        $newbie = User::factory()->inactive()->create();

        $this->actingAs($admin)->patch("/users/{$newbie->id}/approve")->assertRedirect();

        $this->assertTrue($newbie->fresh()->is_active);
        $this->assertTrue($newbie->sections()->whereKey($this->testing->id)->exists());
    }

    public function test_user_without_sections_sees_notice_instead_of_records(): void
    {
        $user = $this->master();   // без участков

        $this->actingAs($user)->withSession(['workstation_workers' => []])->get('/')
            ->assertOk()
            ->assertSee('Вам пока не назначен участок');
        $this->actingAs($user)->withSession(['workstation_workers' => []])->get('/records/pgp')->assertForbidden();
    }
}
