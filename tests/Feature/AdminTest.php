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
            $this->asUser($this->worker('master'))->get($page)->assertForbidden();
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

    // ---------------------------------------------------------------- пользователи

    public function test_change_role_approve_deactivate_and_reset_password(): void
    {
        $admin = $this->admin();
        $user = User::factory()->inactive()->create();

        $this->asUser($admin)->patch("/users/{$user->id}/approve")->assertRedirect();
        $this->assertTrue($user->fresh()->is_active);

        $this->asUser($admin)->patch("/users/{$user->id}/role", ['role' => 'master'])->assertRedirect();
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

        $this->asUser($admin)->patch("/users/{$admin->id}/role", ['role' => 'executor'])->assertSessionHasErrors();
        $this->asUser($admin)->patch("/users/{$admin->id}/deactivate");

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->asUser($this->admin())->patch("/users/{$user->id}/role", ['role' => 'superuser'])->assertSessionHasErrors('role');
        $this->assertSame('executor', $user->fresh()->role);
    }

    // ---------------------------------------------------------------- сотрудники

    public function test_create_and_update_employee(): void
    {
        $admin = $this->admin();

        $this->asUser($admin)->post('/employees', [
            'last_name' => 'Новиков', 'first_name' => 'Пётр', 'middle_name' => 'Сергеевич', 'is_active' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $employee = Employee::where('last_name', 'Новиков')->firstOrFail();
        $this->assertSame('Новиков П.С.', $employee->short_name, 'короткое ФИО собирается автоматически');

        $this->asUser($admin)->put("/employees/{$employee->id}", [
            'last_name' => 'Новиков', 'first_name' => 'Пётр', 'middle_name' => 'Сергеевич', 'is_active' => 0,
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
