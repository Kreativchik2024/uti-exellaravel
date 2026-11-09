<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Предохранитель: тесты используют RefreshDatabase (пересоздают все таблицы).
     * Если конфиг закеширован (`php artisan optimize` / `config:cache`), Laravel
     * игнорирует настройки из phpunit.xml, и тесты пошли бы в РАБОЧУЮ базу.
     * Поэтому до любого обращения к БД проверяем, что это временная SQLite в памяти.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()) {
            throw new RuntimeException(
                'Тесты остановлены: конфиг закеширован (bootstrap/cache/config.php), '
                . 'тесты ушли бы в рабочую базу. Выполните `php artisan optimize:clear` и запустите снова.'
            );
        }

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");

        if ($default !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Тесты остановлены: подключение к БД «{$default}» ({$database}), а ожидается временная SQLite в памяти. "
                . 'Проверьте phpunit.xml и .env.testing.'
            );
        }

        return $app;
    }
}
