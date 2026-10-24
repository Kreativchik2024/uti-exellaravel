<?php

namespace App\Providers;

use App\Models\CommModuleRecord;
use App\Models\Cross1Record;
use App\Models\Cross3Record;
use App\Models\FirmwareVersion;
use App\Models\MiniPcRecord;
use App\Models\News;
use App\Models\PcCheckRecord;
use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use App\Models\SophonCheckRecord;
use App\Models\TechnologistRecord;
use App\Observers\FirmwareVersionObserver;
use App\Policies\FirmwarePolicy;
use App\Policies\NewsPolicy;
use App\Policies\RecordPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Models\SoftwareInstallRecord;
use App\Models\MotorRegulatorRecord;
use App\Models\CameraBracketRecord;
use App\Models\RouterFirmwareRecord;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Новости и прошивки
        Gate::policy(News::class, NewsPolicy::class);
        Gate::policy(FirmwareVersion::class, FirmwarePolicy::class);

        // Все вкладки-записи
        Gate::policy(PgpRecord::class,          RecordPolicy::class);
        Gate::policy(CommModuleRecord::class,   RecordPolicy::class);
        Gate::policy(Cross1Record::class,       RecordPolicy::class);
        Gate::policy(Cross3Record::class,       RecordPolicy::class);
        Gate::policy(MiniPcRecord::class,       RecordPolicy::class);
        Gate::policy(ResearchRecord::class,     RecordPolicy::class);
        Gate::policy(TechnologistRecord::class, RecordPolicy::class);
        Gate::policy(PcCheckRecord::class,      RecordPolicy::class);
        Gate::policy(SophonCheckRecord::class,  RecordPolicy::class);
        Gate::policy(SoftwareInstallRecord::class, RecordPolicy::class);
        Gate::policy(MotorRegulatorRecord::class,  RecordPolicy::class);
        Gate::policy(CameraBracketRecord::class,   RecordPolicy::class);
        Gate::policy(RouterFirmwareRecord::class,  RecordPolicy::class);

        // Observer прошивок
        FirmwareVersion::observe(FirmwareVersionObserver::class);
    }
}