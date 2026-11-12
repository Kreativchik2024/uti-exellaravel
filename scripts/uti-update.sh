#!/usr/bin/env bash
# =============================================================================
#  uti-update — обновление сайта УТИ на сервере из GitHub.
#
#  Порядок (сайт работает, пока идёт всё долгое):
#    1. забирает новый код из GitHub (git fetch) — сайт ещё на старом коде
#    2. (по желанию) обновляет пакеты системы: apt update && apt upgrade
#    3. собирает стили и скрипты (npm ci + npm run build) в отдельной копии
#       ~/.uti-build — если сборка упала, сайт не тронут, обновление отменяется
#    4. бэкап базы в ~/backups (последние 10)
#    5. режим обслуживания (на несколько секунд)
#    6. переключает код на новую версию (git merge)
#    7. PHP-зависимости (composer install)
#    8. подкладывает готовую сборку стилей
#    9. миграции базы
#   10. права, сброс и сборка кешей Laravel, перезапуск PHP и веб-сервера
#   11. сайт снова открыт
#  Если шаги 6–10 упали — код и стили возвращаются на прежнюю версию,
#  сайт открывается обратно (базу при необходимости — из бэкапа).
#
#  Использование:
#      uti-update              — обновление сайта
#      uti-update --system     — то же + обновление пакетов системы
#      uti-update --no-backup  — без бэкапа базы
#
#  Установка (один раз):
#      chmod +x /var/www/uti-exellaravel/scripts/uti-update.sh
#      sudo ln -sf /var/www/uti-exellaravel/scripts/uti-update.sh /usr/local/bin/uti-update
# =============================================================================

# Весь скрипт — внутри функции: bash прочитает его целиком до запуска,
# поэтому git, обновивший этот же файл, не сломает выполнение.
main() {
set -euo pipefail

APP="${UTI_APP:-/var/www/uti-exellaravel}"
BRANCH="${UTI_BRANCH:-main}"
BUILD="${UTI_BUILD:-$HOME/.uti-build}"
WEB_GROUP="www-data"
BACKUPS="$HOME/backups"
STAMP="$(date +%F_%H%M)"
SYSTEM=0
BACKUP=1

for arg in "$@"; do
    case "$arg" in
        --system)    SYSTEM=1 ;;
        --no-backup) BACKUP=0 ;;
        -h|--help)   sed -n '2,29p' "$(readlink -f "$0")"; exit 0 ;;
        *) echo "Неизвестный параметр: $arg (см. uti-update --help)"; exit 1 ;;
    esac
done

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m   %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mОШИБКА: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -ne 0 ] || die "запускайте без sudo (от пользователя $(logname 2>/dev/null || echo orangepi)) — пароль sudo скрипт спросит сам"
[ -f "$APP/artisan" ] || die "не найден сайт в $APP"
[ -f "$APP/.env" ]    || die "не найден $APP/.env"
for tool in git php npm; do command -v "$tool" >/dev/null || die "не установлен $tool"; done

cd "$APP"
START="$(git rev-parse HEAD)"

# ---------------------------------------------------------------- 1. git
say "1. Новый код из GitHub (ветка $BRANCH)"
# package-lock.json на сервере меняет npm — это не правки, откатываем
git checkout -- package-lock.json 2>/dev/null || true
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no
    die "на сервере изменены файлы (выше) — обновление их затрёт. Уберите правки: git checkout -- <файл>"
fi
git fetch origin "$BRANCH"
TARGET="$(git rev-parse "origin/$BRANCH")"
git merge-base --is-ancestor HEAD "$TARGET" || die "на сервере есть свои коммиты, которых нет в GitHub — пришлите вывод «git log -3» разработчику"
if [ "$START" = "$TARGET" ]; then
    echo "   новых коммитов нет ($(git rev-parse --short HEAD)) — всё равно пересобираю"
else
    git --no-pager log --oneline "$START..$TARGET" | sed 's/^/   /'
fi

# ---------------------------------------------------------------- 2. система
if [ "$SYSTEM" = 1 ]; then
    say "2. Обновление пакетов системы"
    sudo apt-get update
    sudo DEBIAN_FRONTEND=noninteractive apt-get -y upgrade
    sudo apt-get -y autoremove
fi

# ---------------------------------------------------------------- 3. сборка (сайт работает)
say "3. Сборка стилей и скриптов в $BUILD (сайт пока работает)"
git worktree prune
if ! git -C "$BUILD" checkout --detach --force "$TARGET" >/dev/null 2>&1; then
    # первый запуск или копия испорчена — создать заново
    rm -rf "$BUILD"
    git worktree prune
    git worktree add --detach --force "$BUILD" "$TARGET" >/dev/null 2>&1 || die "не удалось создать копию для сборки в $BUILD"
fi
git -C "$BUILD" clean -fdq -e node_modules
# Tailwind берёт классы и из шаблонов в vendor/ (пагинация и т.п.) — без этого кнопки страниц пропадают
[ -d "$APP/vendor" ] && ln -sfn "$APP/vendor" "$BUILD/vendor"
(
    cd "$BUILD" &&
    { npm ci --no-audit --no-fund || { echo "   npm ci не прошёл — пробую npm install"; npm install --no-audit --no-fund; }; } &&
    rm -rf public/build &&
    npm run build
) || die "сборка стилей не удалась — сайт НЕ изменён, работает прежняя версия. На Orange Pi частая причина — мало памяти: включите swap (sudo apt install zram-tools)"
[ -f "$BUILD/public/build/manifest.json" ] && ls "$BUILD"/public/build/assets/*.css >/dev/null 2>&1 \
    || die "сборка не создала public/build — сайт НЕ изменён"
echo "   собрано: $(ls "$BUILD/public/build/assets" | wc -l) файлов"

# ---------------------------------------------------------------- 4. бэкап
if [ "$BACKUP" = 1 ]; then
    DB_NAME="$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d '"'"'"'\r')"
    if [ -n "$DB_NAME" ] && command -v mysqldump >/dev/null; then
        say "4. Бэкап базы «$DB_NAME»"
        mkdir -p "$BACKUPS"
        sudo mysqldump --single-transaction --no-tablespaces "$DB_NAME" > "$BACKUPS/uti-db-$STAMP.sql"
        tail -n 1 "$BACKUPS/uti-db-$STAMP.sql" | grep -q 'Dump completed' || die "дамп базы неполный — обновление остановлено, сайт НЕ изменён"
        echo "   $BACKUPS/uti-db-$STAMP.sql ($(du -h "$BACKUPS/uti-db-$STAMP.sql" | cut -f1))"
        ls -1t "$BACKUPS"/uti-db-*.sql 2>/dev/null | tail -n +11 | xargs -r rm -f
    else
        warn "бэкап пропущен (нет DB_DATABASE или mysqldump)"
    fi
fi

# ---------------------------------------------------------------- 5–10. переключение
say "5. Режим обслуживания"
rm -rf public/build.prev
[ -d public/build ] && cp -a public/build public/build.prev

# Права: логи и кеши, созданные веб-сервером (www-data), должны быть доступны и нам
fix_perms() {
    sudo chown -R "$(id -un):$WEB_GROUP" storage bootstrap/cache
    sudo chmod -R ug+rwX storage bootstrap/cache
    sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
}
# Кеш списка пакетов/конфигурации: если в нём пакет, которого больше нет в vendor
# (например, поставленный когда-то вручную), artisan не запустится — удаляем
drop_caches() { rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php; }
fix_perms
php artisan down --retry=15 >/dev/null 2>&1 || true

# Любая ошибка дальше — вернуть прежний код и стили, открыть сайт
SWITCHED=0
rollback() {
    local code=$?
    [ "$code" -eq 0 ] && return
    printf '\n\033[1;31mОШИБКА на шаге выше — возвращаю прежнюю версию\033[0m\n' >&2
    cd "$APP"
    fix_perms || true
    drop_caches
    if [ "$SWITCHED" = 1 ]; then
        git reset --hard -q "$START" || true
        command -v composer >/dev/null && composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q || true
    fi
    if [ -d public/build.prev ]; then rm -rf public/build; mv public/build.prev public/build; fi
    php artisan package:discover >/dev/null 2>&1 || true
    php artisan optimize:clear >/dev/null 2>&1 || true
    php artisan optimize >/dev/null 2>&1 || true
    php artisan up >/dev/null 2>&1 || true
    echo "   сайт открыт на прежней версии $(git rev-parse --short HEAD)." >&2
    [ "$BACKUP" = 1 ] && [ -f "$BACKUPS/uti-db-$STAMP.sql" ] && \
        echo "   если упали миграции и база повреждена: sudo mysql <база> < $BACKUPS/uti-db-$STAMP.sql" >&2
    exit "$code"
}
trap rollback EXIT

say "6. Переключение кода на новую версию"
SWITCHED=1
git merge --ff-only -q "$TARGET"
git checkout -- package-lock.json 2>/dev/null || true

say "7. PHP-зависимости (composer)"
drop_caches
if command -v composer >/dev/null; then
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
else
    warn "composer не найден — пропускаю (vendor остаётся прежним)"
fi

say "8. Готовая сборка стилей"
rm -rf public/build.new
cp -a "$BUILD/public/build" public/build.new
rm -rf public/build
mv public/build.new public/build

say "9. Миграции базы"
php artisan migrate --force

say "10. Права, кеши, перезапуск"
fix_perms
sudo chown -R "$(id -un):$WEB_GROUP" public/build
php artisan optimize:clear >/dev/null
php artisan storage:link >/dev/null 2>&1 || true
php artisan optimize
for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^(php[0-9.]*-fpm|nginx|apache2)\.service$'); do
    sudo systemctl reload "$svc" 2>/dev/null || sudo systemctl restart "$svc"
    echo "   $svc"
done

say "11. Сайт открыт"
php artisan up
trap - EXIT
rm -rf public/build.prev
printf '\n\033[1;32mГотово.\033[0m Версия: %s — %s\n' "$(git rev-parse --short HEAD)" "$(git log -1 --format=%s)"
if [ "$BACKUP" = 1 ] && [ -f "$BACKUPS/uti-db-$STAMP.sql" ]; then echo "Бэкап базы: $BACKUPS/uti-db-$STAMP.sql"; fi
if [ -f /var/run/reboot-required ]; then warn "после обновления системы нужна перезагрузка: sudo reboot"; fi
return 0
}
main "$@"; exit $?
