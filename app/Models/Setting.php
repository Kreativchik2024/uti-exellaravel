<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Настройки сайта, которые меняет админ (не .env): хранятся в базе, переживают очистку кеша. */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    /** Все настройки — одним запросом на первый вызов (таблица маленькая; раньше — запрос на каждый ключ). */
    private static ?array $memo = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$memo ??= static::query()->pluck('value', 'key')->all();

        return self::$memo[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        if (self::$memo !== null) self::$memo[$key] = $value;
    }

    public static function gamesEnabled(): bool
    {
        return (bool) (int) static::get('games.enabled', 0);
    }

    /** Для тестов: между запросами значения читаются заново. */
    public static function forgetMemo(): void
    {
        self::$memo = null;
    }
}
