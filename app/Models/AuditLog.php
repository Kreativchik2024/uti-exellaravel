<?php

namespace App\Models;

use App\Services\SectionContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала действий. Создаётся только через AuditLog::write().
 * Записи журнала не изменяются и не удаляются из интерфейса (только очистка по сроку).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Сколько дней хранить журнал (команда audit:prune). */
    public const RETENTION_DAYS = 365;

    public const AREAS = [
        'records'   => 'Записи учёта',
        'users'     => 'Пользователи',
        'employees' => 'Сотрудники',
        'sections'  => 'Участки',
        'news'      => 'Новости',
        'firmware'  => 'Прошивки',
        'profile'   => 'Профиль',
        'auth'      => 'Вход в систему',
        'chat'      => 'Чат',
        'system'    => 'Обслуживание',
    ];

    public const ACTIONS = [
        'created'      => 'создано',
        'updated'      => 'изменено',
        'deleted'      => 'удалено',
        'login'        => 'вход',
        'login_failed' => 'неудачный вход',
        'logout'       => 'выход',
        'role'         => 'смена роли',
        'approved'     => 'активирован',
        'deactivated'  => 'деактивирован',
        'password'     => 'сброс пароля',
        'pin'          => 'ПИН',
        'sections'     => 'участки',
        'imported'     => 'импорт',
        'status'       => 'смена статуса',
        'two_factor'   => 'двухфакторная защита',
        'pinned'       => 'закреплено',
        'run'          => 'запуск',
        'download'     => 'скачивание',
        'unpinned'     => 'откреплено',
    ];

    /** Поля, значения которых в журнал не пишутся. */
    private const SECRET_FIELDS = ['password', 'pin_hash', 'remember_token', 'session_id', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Записать действие текущего пользователя.
     *
     * @param  array{subject_type?:string, subject_id?:int, section_id?:int, changes?:array, user?:User}  $opt
     */
    public static function write(string $area, string $action, string $summary, array $opt = []): void
    {
        $user = $opt['user'] ?? auth()->user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $sectionId = $opt['section_id'] ?? null;
        if ($sectionId === null && ! array_key_exists('section_id', $opt) && $user) {
            $sectionId = app(SectionContext::class)->current()?->id;
        }

        (new static)->forceFill([
            'user_id'      => $user?->id,
            'user_name'    => $user?->name ?? (app()->runningInConsole() ? 'система' : 'гость'),
            'area'         => $area,
            'action'       => $action,
            'subject_type' => $opt['subject_type'] ?? null,
            'subject_id'   => $opt['subject_id'] ?? null,
            'section_id'   => $sectionId,
            'summary'      => mb_strimwidth($summary, 0, 255, '…'),
            'changes'      => isset($opt['changes']) ? self::clean($opt['changes']) : null,
            'ip'           => $request?->ip(),
        ])->save();
    }

    /** Убирает секреты и слишком длинные значения. */
    private static function clean(array $changes): ?array
    {
        $out = [];
        foreach ($changes as $field => $pair) {
            if (in_array($field, self::SECRET_FIELDS, true)) {
                $out[$field] = ['•••', '•••'];
                continue;
            }
            $out[$field] = array_map(fn ($v) => is_string($v) ? mb_strimwidth($v, 0, 300, '…') : $v, (array) $pair);
        }

        return $out ?: null;
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function areaLabel(): string
    {
        return self::AREAS[$this->area] ?? $this->area;
    }
}
