<#
.SYNOPSIS
    Сборка архива версии для сервера БЕЗ доступа в интернет.

.DESCRIPTION
    Запускается на компьютере С интернетом (Windows, OSPanel). Берёт код
    из последнего коммита (не из рабочей папки!), ставит PHP-зависимости
    (vendor, без dev-пакетов), собирает CSS/JS (public/build) и упаковывает
    всё в releases\uti-release-<дата>-<коммит>.tar.gz.

    На сервере архив разворачивается скриптом scripts/deploy-release.sh —
    ему не нужны ни GitHub, ни composer, ни npm.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\make-release.ps1
#>
param(
    [string]$Php      = 'C:\OSPanel\modules\PHP-8.4\php.exe',            # PHP 8.4 (у 8.5 из OSPanel не грузится openssl)
    [string]$Composer = 'C:\ProgramData\ComposerSetup\bin\composer.phar',
    [string]$OutDir   = ''
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (-not $OutDir) { $OutDir = Join-Path $root 'releases' }

function Step($text) { Write-Host "`n==> $text" -ForegroundColor Cyan }
function Run($exe, [string[]]$arguments) {
    & $exe @arguments
    if ($LASTEXITCODE -ne 0) { throw "Команда завершилась с ошибкой ($LASTEXITCODE): $exe $($arguments -join ' ')" }
}

foreach ($tool in @($Php, $Composer)) {
    if (-not (Test-Path $tool)) { throw "Не найден $tool — укажите путь параметром." }
}

Push-Location $root
try {
    Step 'Проверка репозитория'
    $commit = (git rev-parse --short HEAD).Trim()
    $dirty  = git status --porcelain
    if ($dirty) {
        Write-Host 'ВНИМАНИЕ: есть незакоммиченные изменения — в архив они НЕ попадут (собирается последний коммит):' -ForegroundColor Yellow
        $dirty | ForEach-Object { Write-Host "   $_" -ForegroundColor Yellow }
    }
    $stamp   = Get-Date -Format 'yyyy-MM-dd_HHmm'
    $name    = "uti-release-$stamp-$commit"
    $work    = Join-Path $env:TEMP $name
    $tarTmp  = "$work.src.tar"

    Step "Выгрузка кода из коммита $commit"
    if (Test-Path $work) { Remove-Item $work -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    Run 'git' @('archive', '--format=tar', '-o', $tarTmp, 'HEAD')
    Run "$env:SystemRoot\System32\tar.exe" @('-xf', $tarTmp, '-C', $work)
    Remove-Item $tarTmp -Force
}
finally { Pop-Location }

Push-Location $work
try {
    Step 'PHP-зависимости (composer install --no-dev)'
    # --no-scripts: package:discover выполнит сервер при развёртывании
    Run $Php @($Composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts')

    Step 'Сборка CSS/JS (npm ci + npm run build)'
    Run 'npm.cmd' @('ci', '--no-audit', '--no-fund')
    Run 'npm.cmd' @('run', 'build')
    if (-not (Test-Path 'public\build\manifest.json')) { throw 'Сборка не создала public\build\manifest.json' }
    Remove-Item 'node_modules' -Recurse -Force   # на сервере не нужны — всё уже в public/build

    # UTF-8 без BOM и LF — файл читается на Linux-сервере
    [System.IO.File]::WriteAllText((Join-Path $work 'RELEASE.txt'),
        "Версия:   $commit`nСобрано:  $(Get-Date -Format 'yyyy-MM-dd HH:mm')`n", (New-Object System.Text.UTF8Encoding $false))

    Step 'Упаковка'
    New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
    $archive = Join-Path $OutDir "$name.tar.gz"
    Run "$env:SystemRoot\System32\tar.exe" @('-czf', $archive, '-C', $work, '.')
}
finally { Pop-Location }

Remove-Item $work -Recurse -Force
$sizeMb = [math]::Round((Get-Item $archive).Length / 1MB, 1)
$hash   = (Get-FileHash $archive -Algorithm SHA256).Hash.ToLower()
# Формат sha256sum и перевод строки LF — иначе `sha256sum -c` на Linux не узнает имя файла
[System.IO.File]::WriteAllText("$archive.sha256", "$hash  $name.tar.gz`n", [System.Text.Encoding]::ASCII)

Write-Host "`nГотово: $archive ($sizeMb МБ)" -ForegroundColor Green
Write-Host "SHA-256: $hash"
Write-Host "`nДальше:"
Write-Host "  1) скопируйте на сервер:  scp `"$archive`" scripts\deploy-release.sh orangepi@<IP>:~/"
Write-Host "  2) на сервере:            bash ~/deploy-release.sh ~/$name.tar.gz"
