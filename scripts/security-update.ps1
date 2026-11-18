<#
.SYNOPSIS
    Обновление пакетов с известными уязвимостями (composer + npm) с проверкой тестами.

.DESCRIPTION
    1. composer audit — находит уязвимые PHP-пакеты и обновляет только их
       (в пределах версий из composer.json, вместе с зависимостями).
    2. npm audit fix — безопасные обновления JS-пакетов (без --force:
       тот откатывает vite на старую мажорную версию и ломает сборку).
    3. Тесты и сборка CSS/JS. Если что-то упало — composer.lock, package-lock.json
       и пакеты возвращаются как были.
    4. Итоговый отчёт: что осталось без исправления.

    Запускать на компьютере с интернетом. На сервер обновления уходят
    обычным путём — архивом scripts\make-release.ps1.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\security-update.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\security-update.ps1 -SkipTests
#>
param(
    [string]$Php      = 'C:\OSPanel\modules\PHP-8.4\php.exe',            # PHP 8.4 (консольный 8.5 сломан)
    [string]$Composer = 'C:\ProgramData\ComposerSetup\bin\composer.phar',
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Step($text) { Write-Host "`n==> $text" -ForegroundColor Cyan }
function Run($exe, [string[]]$arguments) {
    & $exe @arguments
    if ($LASTEXITCODE -ne 0) { throw "Ошибка ($LASTEXITCODE): $exe $($arguments -join ' ')" }
}
function ComposerVulnerable {
    $json = & $Php $Composer audit --format=json --no-interaction 2>$null | Out-String
    if (-not $json.Trim()) { return @() }
    $advisories = ($json | ConvertFrom-Json).advisories
    if (-not $advisories) { return @() }
    return @($advisories.PSObject.Properties | ForEach-Object { $_.Name })
}

foreach ($f in @($Php, $Composer)) {
    if (-not (Test-Path $f)) { throw "Не найден файл: $f (укажите -Php / -Composer)" }
}

# Резервные копии lock-файлов — для отката, если после обновления что-то сломается
$backup = Join-Path $env:TEMP ('uti-security-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force $backup | Out-Null
Copy-Item composer.lock, package-lock.json $backup

try {
    Step 'PHP: поиск уязвимых пакетов (composer audit)'
    $packages = ComposerVulnerable
    if ($packages.Count -gt 0) {
        Write-Host ('Обновляются: ' + ($packages -join ', ')) -ForegroundColor Yellow
        Run $Php (@($Composer, 'update') + $packages + @('--with-all-dependencies', '--no-interaction'))
    } else {
        Write-Host 'Уязвимых PHP-пакетов нет.' -ForegroundColor Green
    }

    Step 'JS: безопасные обновления (npm audit fix)'
    & npm audit fix
    # npm audit fix возвращает ошибку, если часть уязвимостей не исправить без --force — это не провал

    Step 'Сборка CSS/JS'
    Run 'npm' @('run', 'build')

    if (-not $SkipTests) {
        Step 'Тесты'
        Run $Php @('artisan', 'test', '--compact')
    }
}
catch {
    Write-Host "`n$($_.Exception.Message)" -ForegroundColor Red
    Step 'Откат: возвращаю composer.lock и package-lock.json'
    Copy-Item (Join-Path $backup 'composer.lock'), (Join-Path $backup 'package-lock.json') $root -Force
    & $Php $Composer install --no-interaction
    & npm ci
    & npm run build
    Write-Host 'Откат выполнен. Пакеты как до запуска.' -ForegroundColor Yellow
    exit 1
}

Step 'Итог'
$left = ComposerVulnerable
if ($left.Count -gt 0) {
    Write-Host ('PHP: остались уязвимые пакеты (нужна новая версия или смена версии в composer.json): ' + ($left -join ', ')) -ForegroundColor Yellow
} else {
    Write-Host 'PHP: уязвимостей нет.' -ForegroundColor Green
}
& npm audit --omit=dev | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Host 'JS (то, что попадает на сайт): уязвимостей нет.' -ForegroundColor Green
} else {
    Write-Host 'JS: есть уязвимости в пакетах сайта — см. npm audit --omit=dev' -ForegroundColor Yellow
}
Write-Host 'JS (сборка, только на машине разработчика): подробности — npm audit' -ForegroundColor Gray
Write-Host "`nИзменения: git diff --stat composer.lock package-lock.json. Резервные копии: $backup"
