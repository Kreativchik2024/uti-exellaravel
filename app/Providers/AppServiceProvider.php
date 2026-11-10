<?php

namespace App\Providers;

use App\Models\FirmwareVersion;
use App\Models\News;
use App\Observers\FirmwareVersionObserver;
use App\Policies\FirmwarePolicy;
use App\Policies\NewsPolicy;
use App\Services\EmployeeDirectory;
use App\Services\SectionContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EmployeeDirectory::class);
        $this->app->scoped(SectionContext::class);
    }

    public function boot(): void
    {
        Gate::policy(News::class, NewsPolicy::class);
        Gate::policy(FirmwareVersion::class, FirmwarePolicy::class);

        FirmwareVersion::observe(FirmwareVersionObserver::class);

        // Журнал действий: вход и выход (изменения записей — в цикле по вкладкам ниже)
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($e) {
            \App\Models\AuditLog::write('auth', 'login', 'Вход: ' . $e->user->email . ($e->remember ? ' (запомнить)' : ''), ['user' => $e->user, 'section_id' => null]);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($e) {
            $email = mb_strimwidth((string) ($e->credentials['email'] ?? ''), 0, 120);
            \App\Models\AuditLog::write('auth', 'login_failed', 'Неверный пароль для ' . $email . ($e->user ? '' : ' (такой учётки нет)'), ['section_id' => null]);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function ($e) {
            if (! $e->user) return;
            $why = request()->input('reason') === 'idle' ? 'Автовыход (бездействие ' . config('auth.idle_logout_minutes') . ' мин): ' : 'Выход: ';
            \App\Models\AuditLog::write('auth', 'logout', $why . $e->user->email, ['user' => $e->user, 'section_id' => null]);
        });

        // Вкладки учёта (config/sheets.php): политика доступа, журнал изменений, короткое имя для связей.
        // Новая вкладка подключается сама — достаточно описать её в конфиге.
        $morph = [];
        foreach (config('sheets.map', []) as $slug => $cfg) {
            if (!isset($cfg['model']) || !class_exists($cfg['model'])) {
                continue;
            }
            Gate::policy($cfg['model'], $cfg['policy'] ?? \App\Policies\RecordPolicy::class);
            $cfg['model']::observe(\App\Observers\RecordAuditObserver::class);   // журнал действий
            $morph[$slug] = $cfg['model'];
        }
        // Короткие имена вкладок в полиморфных связях (старая таблица record_employee хранит их)
        Relation::morphMap($morph);

        // Подтверждённые ПИН — только в layout-шаблоны, которые их используют
        // (раньше composer('*') делал запрос к БД на каждый партиал/компонент).
        View::composer(['layouts.app', 'layouts.navigation'], function ($view) {
            $view->with('globalVerifiedWorkersMap', app(EmployeeDirectory::class)->verifiedMap());
        });

        // Участок: выбор в шапке, вкладки «Учёта», видимость разделов
        View::composer('layouts.navigation', function ($view) {
            $sections = app(SectionContext::class);
            $view->with([
                'currentSection'    => $sections->current(),
                'availableSections' => $sections->available(),
                'sectionSheets'     => $sections->sheets(),
            ]);
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Чат: не больше 20 сообщений в минуту от одного пользователя
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(20)
            ->by('chat:' . ($request->user()?->id ?? $request->ip()))
            ->response(fn (Request $r, array $headers) => response()->json(
                ['message' => 'Слишком часто. Подождите немного.'], 429, $headers
            )));

        // Проверка ПИН: 10 попыток в минуту на пользователя (и на IP для страховки)
        RateLimiter::for('pin', function (Request $request) {
            $message = 'Слишком много попыток ввода ПИН. Подождите минуту.';
            $response = fn (Request $r, array $headers) => response()->json(
                ['ok' => false, 'error' => $message], 429, $headers
            );

            return [
                Limit::perMinute(10)->by('pin-user:' . ($request->user()?->id ?? 'guest'))->response($response),
                Limit::perMinute(30)->by('pin-ip:' . $request->ip())->response($response),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(
                Str::lower((string) $request->input('email')) . '|' . $request->ip()
            );

            return Limit::perMinute(5)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'Слишком много попыток входа. Попробуйте через минуту.',
                        ], 429, $headers);
                    }

                    return redirect()
                        ->back()
                        ->withInput($request->only('email'))
                        ->withErrors([
                            'email' => 'Слишком много попыток входа. Попробуйте через минуту.',
                        ]);
                });
        });
    }
}