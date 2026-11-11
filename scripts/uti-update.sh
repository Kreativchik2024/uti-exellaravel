#!/usr/bin/env bash
# =============================================================================
#  uti-update — обновление сайта УТИ на сервере из GitHub.
#
#  Что делает (каждый раз, полностью):
#    1. (по желанию) обновляет пакеты системы: apt update && apt upgrade
#    2. бэкап базы в ~/backups (хранятся последние 10)
#    3. режим обслуживания
#    4. забирает код из GitHub (git pull)
#    5. PHP-зависимости (composer install)
#    6. пересобирает стили и скрипты (npm ci + npm run build) —
#       при неудаче возвращает прежнюю сборку, сайт не ломается
#    7. миграции базы
#    8. права, сброс и сборка кешей Laravel
#    9. перезапуск PHP-FPM и веб-сервера, выход из режима обслуживания
#
#  Использование:
#      uti-update              — обычное обновление сайта
#      uti-update --system     — то же + обновление пакетов Ubuntu/Debian
#      uti-update --no-backup  — без бэкапа базы
#
#  Установка (один раз):
#      chmod +x /var/www/uti-exellaravel/scripts/uti-update.sh
#      sudo ln -sf /var/www/uti-exellaravel/scripts/uti-update.sh /usr/local/bin/uti-update
#
#  Откат базы: sudo mysql <база> < ~/backups/uti-db-….sql
#  Откат кода: git -C /var/www/uti-exellaravel reset --hard <коммит> && uti-update
# =============================================================================

# Весь скрипт — внутри функции: bash прочитает его целиком до запуска,
# поэтому git pull, обновивший этот же файл, не сломает выполнение.
main() {
set -euo pipefail

APP="${UTI_APP:-/var/www/uti-exellaravel}"
BRANCH="${UTI_BRANCH:-main}"
WEB_GROUP="www-data"
BACKUPS="$HOME/backups"
STAMP="$(date +%F_%H%M)"
SYSTEM=0
BACKUP=1

for arg in "$@"; do
    case "$arg" in
        --system)    SYSTEM=1 ;;
        --no-backup) BACKUP=0 ;;
        -h|--help)   sed -n '2,30p' "$(readlink -f "$0")"; exit 0 ;;
        *) echo "Неизвестный параметр: $arg (см. uti-update --help)"; exit 1 ;;
    esac
done

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m   %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mОШИБКА: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -ne 0 ] || die "запускайте без sudo (от пользователя orangepi) — sudo скрипт попросит сам"
[ -f "$APP/artisan" ] || die "не найден сайт в $APP"
[ -f "$APP/.env" ]    || die "не найден $APP/.env"
for tool in git php npm; do command -v "$tool" >/dev/null || die "не установлен $tool"; done

cd "$APP"
START="$(git rev-parse --short HEAD)"

# ---------------------------------------------------------------- 1. система
if [ "$SYSTEM" = 1 ]; then
    say "Обновление пакетов системы"
    sudo apt-get update
    sudo DEBIAN_FRONTEND=noninteractive apt-get -y upgrade
    sudo apt-get -y autoremove
fi

# ---------------------------------------------------------------- 2. бэкап
if [ "$BACKUP" = 1 ]; then
    DB_NAME="$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d '"'"'"'\r')"
    if [ -n "$DB_NAME" ] && command -v mysqldump >/dev/null; then
        say "Бэкап базы «$DB_NAME»"
        mkdir -p "$BACKUPS"
        sudo mysqldump --single-transaction --no-tablespaces "$DB_NAME" > "$BACKUPS/uti-db-$STAMP.sql"
        tail -n 1 "$BACKUPS/uti-db-$STAMP.sql" | grep -q 'Dump completed' || die "дамп базы неполный — обновление остановлено"
        echo "   $BACKUPS/uti-db-$STAMP.sql ($(du -h "$BACKUPS/uti-db-$STAMP.sql" | cut -f1))"
        ls -1t "$BACKUPS"/uti-db-*.sql 2>/dev/null | tail -n +11 | xargs -r rm -f
    else
        warn "бэкап пропущен (нет DB_DATABASE или mysqldump)"
    fi
fi

# ---------------------------------------------------------------- 3. код
say "Код из GitHub (ветка $BRANCH)"
# package-lock.json на сервере меняет npm — это не правки, откатываем
git checkout -- package-lock.json 2>/dev/null || true
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no
    die "на сервере изменены файлы (выше) — git pull их затрёт. Уберите правки: git checkout -- <файл>"
fi
git fetch origin "$BRANCH"
git merge --ff-only "origin/$BRANCH" || die "не удалось обновиться без слияния — пришлите вывод разработчику"
NOW="$(git rev-parse --short HEAD)"
if [ "$START" = "$NOW" ]; then echo "   новых коммитов нет ($NOW) — всё равно пересобираю"
else git --no-pager log --oneline "$START..$NOW" | sed 's/^/   /'; fi

# Сайт недоступен только на время сборки; при любой ошибке — вернуть
php artisan down --retry=30 >/dev/null 2>&1 || true
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

# ---------------------------------------------------------------- 4. PHP
say "PHP-зависимости (composer)"
if command -v composer >/dev/null; then
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
else
    warn "composer не найден — пропускаю (vendor остаётся прежним)"
fi

# ---------------------------------------------------------------- 5. стили/скрипты
say "Сборка стилей и скриптов (npm)"
rm -rf public/build.prev
[ -d public/build ] && cp -a public/build public/build.prev
build_ok=0
if npm ci --no-audit --no-fund || { warn "npm ci не прошёл — пробую npm install"; npm install --no-audit --no-fund; }; then
    if npm run build && [ -f public/build/manifest.json ] && ls public/build/assets/*.css public/build/assets/*.js >/dev/null 2>&1; then
        build_ok=1
    fi
fi
git checkout -- package-lock.json 2>/dev/null || true
if [ "$build_ok" = 1 ]; then
    rm -rf public/build.prev
    echo "   собрано: $(ls public/build/assets | wc -l) файлов"
else
    if [ -d public/build.prev ]; then rm -rf public/build; mv public/build.prev public/build; fi
    die "сборка стилей не удалась — оставлена прежняя. Частая причина на Orange Pi — мало памяти: включите swap (sudo apt install zram-tools)"
fi

# ---------------------------------------------------------------- 6. база
say "Миграции базы"
php artisan migrate --force

# ---------------------------------------------------------------- 7. права и кеши
say "Права и кеши"
sudo chown -R "$(id -un):$WEB_GROUP" storage bootstrap/cache public/build
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan optimize:clear >/dev/null
php artisan storage:link >/dev/null 2>&1 || true
php artisan optimize

# ---------------------------------------------------------------- 8. перезапуск
say "Перезапуск PHP и веб-сервера"
for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^(php[0-9.]*-fpm|nginx|apache2)\.service$'); do
    sudo systemctl reload "$svc" 2>/dev/null || sudo systemctl restart "$svc"
    echo "   $svc"
done

php artisan up
trap - EXIT
printf '\n\033[1;32mГотово.\033[0m Версия: %s — %s\n' "$NOW" "$(git log -1 --format=%s)"
[ "$BACKUP" = 1 ] && [ -f "$BACKUPS/uti-db-$STAMP.sql" ] && echo "Бэкап базы: $BACKUPS/uti-db-$STAMP.sql"
[ -f /var/run/reboot-required ] && warn "после обновления системы нужна перезагрузка: sudo reboot"
return 0
}
main "$@"; exit $?
