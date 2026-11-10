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

    private static array $memo = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = static::query()->whereKey($key)->value('value');
        }

        return self::$memo[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::$memo[$key] = $value;
    }

    public static function gamesEnabled(): bool
    {
        return (bool) (int) static::get('games.enabled', 0);
    }

    /** Для тестов: между запросами значения читаются заново. */
    public static function forgetMemo(): void
    {
        self::$memo = [];
    }
}
