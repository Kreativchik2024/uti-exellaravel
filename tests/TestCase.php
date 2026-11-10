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

    /**
     * Браузер в тестах уже принял правила (cookie согласия). Тесты самого согласия
     * убирают её: $this->withoutConsentCookie().
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(config('legal.cookie'), config('legal.version'));
        $this->withCredentials();   // JSON-запросы тоже несут cookie, как fetch() в браузере
    }

    protected function withoutConsentCookie(): static
    {
        unset($this->defaultCookies[config('legal.cookie')]);

        return $this;
    }

    /**
     * На сервере каждый запрос обрабатывает «чистое» приложение, а в тестах одно
     * приложение обслуживает несколько запросов подряд. Сбрасываем scoped-сервисы
     * (SectionContext, EmployeeDirectory), как это было бы между запросами в бою.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();
        \App\Models\Setting::forgetMemo();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
