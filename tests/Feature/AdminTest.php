<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/**
 * Администрирование: пользователи, сотрудники, категории прошивок, участки.
 */
class AdminTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function admin(): User
    {
        return $this->worker('admin');
    }

    public function test_admin_pages_open_for_admin_only(): void
    {
        $pages = ['/users', '/employees', '/employees/create', '/employees/import', '/firmware/categories', '/sections'];

        foreach ($pages as $page) {
            $this->asUser($this->admin())->get($page)->assertOk();
            // список сотрудников мастеру открыт на просмотр (StatsPostsAndBoardNumberTest)
            if ($page !== '/employees') $this->asUser($this->worker('master'))->get($page)->assertForbidden();
            $this->asUser($this->worker())->get($page)->assertForbidden();
        }
    }

    public function test_employee_template_and_export_download(): void
    {
        foreach (['/employees/template', '/employees/export'] as $url) {
            $response = $this->asUser($this->admin())->get($url)->assertOk();
            // шаблон — CSV, выгрузка — Excel
            $this->assertMatchesRegularExpression('/spreadsheetml|text\/csv/', $response->headers->get('Content-Type'), $url);
        }
    }

    /** Карточка сотрудника для учётной записи с ролью — так теперь назначается роль. */
    private function employeeCard(User $admin, User $user, string $role, string $last = 'Новиков')
    {
        return $this->asUser($admin)->post('/employees', [
            'last_name' => $last, 'first_name' => 'Пётр', 'middle_name' => 'Сергеевич', 'is_active' => 1,
            'user_id' => $user->id, 'role' => $role,
        ]);
    }

    // ---------------------------------------------------------------- пользователи

    public function test_change_role_approve_deactivate_and_reset_password(): void
    {
        $admin = $this->admin();
        $user = User::factory()->inactive()->create();

        $this->asUser($admin)->patch("/users/{$user->id}/approve")->assertRedirect();
        $this->assertTrue($user->fresh()->is_active);

        $this->employeeCard($admin, $user, 'master')->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('master', $user->fresh()->role);

        $this->asUser($admin)->patch("/users/{$user->id}/password", ['password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPass123!', $user->fresh()->password));

        $this->asUser($admin)->patch("/users/{$user->id}/deactivate")->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_admin_cannot_demote_or_deactivate_himself(): void
    {
        $admin = $this->admin();
        $card = $admin->employee;
        $base = ['last_name' => $card->last_name, 'first_name' => $card->first_name, 'middle_name' => $card->middle_name, 'is_active' => 1];

        // ни сменой роли своей карточки, ни отвязкой своей учётной записи
        $this->asUser($admin)->put("/employees/{$card->id}", $base + ['user_id' => $admin->id, 'role' => 'executor'])->assertSessionHasErrors('role');
        $this->asUser($admin)->put("/employees/{$card->id}", $base + ['user_id' => '', 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->asUser($admin)->patch("/users/{$admin->id}/deactivate");

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame($admin->id, $card->fresh()->user_id);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_otk_role_is_assignable_and_has_worker_rights(): void
    {
        $user = User::factory()->create(['role' => 'executor']);
        $this->employeeCard($this->admin(), $user, 'otk')->assertSessionHasNoErrors();

        $user = $user->fresh();
        $this->assertSame('otk', $user->role);
        $this->assertTrue($user->isOtk());
        $this->assertTrue($user->isWorker());
        $this->assertFalse($user->canManageContent());
    }

    public function test_invalid_role_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->employeeCard($this->admin(), $user, 'superuser')->assertSessionHasErrors('role');
        $this->assertSame('executor', $user->fresh()->role);
    }

    public function test_role_is_changed_on_employee_not_on_user(): void
    {
        $admin = $this->admin();
        $worker = $this->worker('executor');
        $card = $worker->employee;

        // У пользователя роль больше не меняется
        $this->asUser($admin)->patch("/users/{$worker->id}/role", ['role' => 'master'])->assertNotFound();
        $this->assertSame('executor', $worker->fresh()->role);
        $users = $this->asUser($admin)->get('/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="role" onchange', $users);
        $this->assertStringContainsString(route('employees.edit', $card->id), $users);

        // Меняется в карточке сотрудника — учётная запись получает её
        $this->asUser($admin)->put("/employees/{$card->id}", [
            'last_name' => $card->last_name, 'first_name' => $card->first_name, 'middle_name' => $card->middle_name,
            'is_active' => 1, 'user_id' => $worker->id, 'role' => 'repair',
        ])->assertSessionHasNoErrors();
        $this->assertSame('repair', $card->fresh()->role);
        $this->assertSame('repair', $worker->fresh()->role);
        $this->assertStringContainsString('Ремонтник', $this->asUser($admin)->get('/employees')->getContent());
    }

    public function test_employee_created_without_role_keeps_user_role(): void
    {
        // Импорт и прочее создание без формы не меняют роль учётной записи
        $master = User::factory()->create(['role' => 'master']);
        $card = Employee::create(['user_id' => $master->id, 'last_name' => 'Мастеров', 'first_name' => 'Иван', 'short_name' => 'Мастеров И.', 'is_active' => true]);

        $this->assertSame('master', $card->fresh()->role);
        $this->assertSame('master', $master->fresh()->role);
    }

    // ---------------------------------------------------------------- сотрудники

    public function test_create_and_update_employee(): void
    {
        $admin = $this->admin();

        $this->asUser($admin)->post('/employees', [
            'last_name' => 'Новиков', 'first_name' => 'Пётр', 'middle_name' => 'Сергеевич', 'is_active' => 1, 'role' => 'executor',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $employee = Employee::where('last_name', 'Новиков')->firstOrFail();
        $this->assertSame('Новиков П.С.', $employee->short_name, 'короткое ФИО собирается автоматически');

        $this->asUser($admin)->put("/employees/{$employee->id}", [
            'last_name' => 'Новиков', 'first_name' => 'Пётр', 'middle_name' => 'Сергеевич', 'is_active' => 0, 'role' => 'executor',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_employee_requires_last_and_first_name(): void
    {
        $this->asUser($this->admin())->post('/employees', ['middle_name' => 'Без имени'])
            ->assertSessionHasErrors(['last_name', 'first_name']);
    }

    // ---------------------------------------------------------------- категории прошивок

    public function test_firmware_categories_create_and_delete(): void
    {
        $admin = $this->admin();

        $this->asUser($admin)->post('/firmware/categories', ['title' => 'Тестовые прошивки', 'slug' => 'test-fw'])
            ->assertSessionHasNoErrors();
        $category = \App\Models\FirmwareCategory::where('slug', 'test-fw')->firstOrFail();

        $this->asUser($admin)->delete("/firmware/categories/{$category->id}")->assertRedirect();
        $this->assertNull($category->fresh());
    }
}
