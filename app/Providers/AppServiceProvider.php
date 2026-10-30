<?php

namespace App\Providers;

use App\Models\CameraBracketRecord;
use App\Models\CommModuleRecord;
use App\Models\Cross1Record;
use App\Models\Cross3Record;
use App\Models\Cross5Record;
use App\Models\Data15Record;
use App\Models\Data17Record;
use App\Models\Employee;
use App\Models\FirmwareVersion;
use App\Models\MiniPcRecord;
use App\Models\MotorRegulatorRecord;
use App\Models\News;
use App\Models\NightCameraAssemblyRecord;
use App\Models\PcCheckRecord;
use App\Models\PgpRecord;
use App\Models\PowerWiresRecord;
use App\Models\ResearchRecord;
use App\Models\RouterFirmwareRecord;
use App\Models\ScrewCheckRecord;
use App\Models\SoftwareInstallRecord;
use App\Models\SophonCheckRecord;
use App\Models\SpeedSensorRecord;
use App\Observers\FirmwareVersionObserver;
use App\Policies\FirmwarePolicy;
use App\Policies\NewsPolicy;
use App\Policies\RecordPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(News::class, NewsPolicy::class);
        Gate::policy(FirmwareVersion::class, FirmwarePolicy::class);

        Gate::policy(PgpRecord::class,          RecordPolicy::class);
        Gate::policy(CommModuleRecord::class,   RecordPolicy::class);
        Gate::policy(Cross1Record::class,       RecordPolicy::class);
        Gate::policy(Cross3Record::class,       RecordPolicy::class);
        Gate::policy(Cross5Record::class,       RecordPolicy::class);
        Gate::policy(MiniPcRecord::class,       RecordPolicy::class);
        Gate::policy(ResearchRecord::class,     RecordPolicy::class);
        Gate::policy(PcCheckRecord::class,      RecordPolicy::class);
        Gate::policy(SophonCheckRecord::class,  RecordPolicy::class);
        Gate::policy(SoftwareInstallRecord::class, RecordPolicy::class);
        Gate::policy(MotorRegulatorRecord::class,  RecordPolicy::class);
        Gate::policy(PowerWiresRecord::class,      RecordPolicy::class);
        Gate::policy(Data15Record::class,          RecordPolicy::class);
        Gate::policy(Data17Record::class,          RecordPolicy::class);
        Gate::policy(CameraBracketRecord::class,   RecordPolicy::class);
        Gate::policy(NightCameraAssemblyRecord::class, RecordPolicy::class);
        Gate::policy(RouterFirmwareRecord::class,  RecordPolicy::class);
        Gate::policy(SpeedSensorRecord::class,     RecordPolicy::class);
        Gate::policy(ScrewCheckRecord::class,      RecordPolicy::class); // <-- ДОБАВЛЕНО

        FirmwareVersion::observe(FirmwareVersionObserver::class);

        Relation::morphMap([
            'pgp'                   => PgpRecord::class,
            'comm_module'           => CommModuleRecord::class,
            'cross1'                => Cross1Record::class,
            'cross3'                => Cross3Record::class,
            'cross5'                => Cross5Record::class,
            'mini_pc'               => MiniPcRecord::class,
            'research'              => ResearchRecord::class,
            'pc_check'              => PcCheckRecord::class,
            'sophon_check'          => SophonCheckRecord::class,
            'software_install'      => SoftwareInstallRecord::class,
            'motor_regulator'       => MotorRegulatorRecord::class,
            'power_wires'           => PowerWiresRecord::class,
            'data15'                => Data15Record::class,
            'data17'                => Data17Record::class,
            'camera_bracket'        => CameraBracketRecord::class,
            'night_camera_assembly' => NightCameraAssemblyRecord::class,
            'router_firmware'       => RouterFirmwareRecord::class,
            'speed_sensor'          => SpeedSensorRecord::class,
            'screw_check'           => ScrewCheckRecord::class, // <-- ДОБАВЛЕНО
        ]);

        foreach (config('sheets.map', []) as $cfg) {
            if (!isset($cfg['model']) || !class_exists($cfg['model'])) {
                continue;
            }
            $cfg['model']::resolveRelationUsing('employees', function ($model) {
                return $model->morphToMany(Employee::class, 'record', 'record_employee')
                    ->withPivot('position')
                    ->withTimestamps()
                    ->orderBy('record_employee.position');
            });
        }

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
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