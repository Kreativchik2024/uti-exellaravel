<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Section;
use Illuminate\Database\Eloquent\Model;

/**
 * Журнал изменений записей учёта всех вкладок (подключается к моделям из config/sheets.php).
 * Хранит «было → стало» по каждому изменённому полю.
 */
class RecordAuditObserver
{
    /** Служебные поля, изменения которых не интересны. */
    private const IGNORED = ['updated_at', 'created_at', 'updated_by', 'created_by'];

    /** @var array<class-string, string>|null  модель → slug вкладки */
    private static ?array $slugs = null;

    public function created(Model $record): void
    {
        $this->log($record, 'created', null);
    }

    public function updated(Model $record): void
    {
        $changes = [];
        foreach ($record->getChanges() as $field => $new) {
            if (in_array($field, self::IGNORED, true)) continue;
            $old = $record->getRawOriginal($field);
            if ((string) $old === (string) $new) continue;   // 10 → "10" — не изменение
            $changes[$field] = [$old, $new];
        }
        if ($changes) {
            $this->log($record, 'updated', $changes);
        }
    }

    public function deleted(Model $record): void
    {
        // Сохраняем содержимое удалённой записи — её можно будет восстановить вручную
        $snapshot = [];
        foreach ($record->getAttributes() as $field => $value) {
            if (in_array($field, self::IGNORED, true) || $value === null || $value === '') continue;
            $snapshot[$field] = [$value, null];
        }
        $this->log($record, 'deleted', $snapshot);
    }

    private function log(Model $record, string $action, ?array $changes): void
    {
        $slug = self::slugFor($record);
        $cfg = config("sheets.map.$slug", []);
        $title = $cfg['title'] ?? class_basename($record);

        AuditLog::write('records', $action, $title . ' #' . $record->getKey() . self::identity($record), [
            'subject_type' => $slug,
            'subject_id'   => $record->getKey(),
            'section_id'   => Section::where('slug', $cfg['section'] ?? Section::DEFAULT_SLUG)->value('id'),
            'changes'      => $changes,
        ]);
    }

    /** Чем запись узнаётся человеком: номер борта, серийник… */
    private static function identity(Model $record): string
    {
        foreach (['board_number', 'serial_number', 'serial', 'device_number', 'number', 'fuselage_number'] as $f) {
            $v = $record->getAttribute($f);
            if (filled($v)) return ' · ' . $v;
        }

        return '';
    }

    public static function slugFor(Model $record): string
    {
        if (self::$slugs === null) {
            self::$slugs = [];
            foreach (config('sheets.map', []) as $slug => $cfg) {
                if (! empty($cfg['model'])) self::$slugs[$cfg['model']] = $slug;
            }
        }

        return self::$slugs[$record::class] ?? class_basename($record);
    }

    /** Модели всех вкладок — для подключения наблюдателя. */
    public static function models(): array
    {
        return collect(config('sheets.map', []))->pluck('model')->filter()->unique()->values()->all();
    }
}
