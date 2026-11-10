#!/usr/bin/env bash
# =============================================================================
#  Развёртывание версии на сервере БЕЗ интернета.
#
#  Архив собирается на компьютере с интернетом: scripts/make-release.ps1
#  (в нём уже есть vendor и собранные стили public/build).
#
#  Использование (на сервере):
#      bash deploy-release.sh ~/uti-release-ГГГГ-ММ-ДД_ЧЧММ-xxxxxxx.tar.gz [папка сайта]
#
#  Что делает:
#    1. Бэкап: дамп базы + архив текущего кода (с vendor — чтобы откатиться офлайн)
#    2. Режим обслуживания
#    3. Замена кода (.env, storage и .git не трогаются)
#    4. Права, миграции, кеши
#    5. Выход из режима обслуживания, перезапуск PHP
#
#  Откат: тем же скриптом развернуть архив предыдущей версии и при
#  необходимости вернуть базу:  sudo mysql uti < ~/backups/uti-db-….sql
# =============================================================================
set -euo pipefail

ARCHIVE="${1:-}"
APP="${2:-/var/www/uti-exellaravel}"
BACKUPS="$HOME/backups"
STAMP="$(date +%F_%H%M)"
WEB_GROUP="www-data"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mОШИБКА: %s\033[0m\n' "$*" >&2; exit 1; }

[ -n "$ARCHIVE" ] || die "укажите архив: bash $0 ~/uti-release-….tar.gz"
[ -f "$ARCHIVE" ] || die "архив не найден: $ARCHIVE"
[ -f "$APP/.env" ] || die "не найден $APP/.env — проверьте папку сайта"
command -v php >/dev/null || die "php не найден"

# Проверка целостности, если рядом лежит .sha256
if [ -f "$ARCHIVE.sha256" ]; then
    say "Проверка контрольной суммы"
    (cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$ARCHIVE").sha256") || die "архив повреждён при копировании"
fi

DB_NAME="$(grep -E '^DB_DATABASE=' "$APP/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"'\r')"
[ -n "$DB_NAME" ] || die "в .env не задан DB_DATABASE"

# ---------------------------------------------------------------- 1. бэкап
say "1/5 Бэкап базы «$DB_NAME» и текущего кода → $BACKUPS"
mkdir -p "$BACKUPS"
sudo mysqldump --single-transaction --no-tablespaces "$DB_NAME" > "$BACKUPS/uti-db-$STAMP.sql"
tail -n 1 "$BACKUPS/uti-db-$STAMP.sql" | grep -q 'Dump completed' || die "дамп базы неполный — обновление остановлено"
sudo tar --exclude=node_modules --exclude=storage/framework --exclude=storage/logs \
    -czf "$BACKUPS/uti-code-$STAMP.tar.gz" -C "$(dirname "$APP")" "$(basename "$APP")"
echo "   база: $BACKUPS/uti-db-$STAMP.sql ($(du -h "$BACKUPS/uti-db-$STAMP.sql" | cut -f1))"
echo "   код:  $BACKUPS/uti-code-$STAMP.tar.gz ($(du -h "$BACKUPS/uti-code-$STAMP.tar.gz" | cut -f1))"

# ---------------------------------------------------------------- 2. распаковка
say "2/5 Распаковка архива"
SRC="$(mktemp -d /tmp/uti-release-XXXXXX)"
trap 'rm -rf "$SRC"' EXIT
tar -xzf "$ARCHIVE" -C "$SRC"
[ -f "$SRC/artisan" ] && [ -d "$SRC/vendor" ] && [ -f "$SRC/public/build/manifest.json" ] \
    || die "в архиве нет artisan/vendor/public/build — соберите его scripts/make-release.ps1"
[ -f "$SRC/RELEASE.txt" ] && sed 's/^/   /' "$SRC/RELEASE.txt"

# Если что-то упадёт дальше — не оставлять сайт в режиме обслуживания
cd "$APP"
trap 'php artisan up >/dev/null 2>&1 || true; rm -rf "$SRC"' EXIT
php artisan down --retry=30 >/dev/null 2>&1 || true

# ---------------------------------------------------------------- 3. замена кода
say "3/5 Замена кода (.env, storage и .git сохраняются)"
if command -v rsync >/dev/null; then
    sudo rsync -a --delete \
        --exclude='/.env' --exclude='/storage/' --exclude='/.git/' \
        --exclude='/public/storage' --exclude='/bootstrap/cache/' \
        "$SRC/" "$APP/"
else
    echo "   (rsync не найден — копирую без удаления старых файлов)"
    sudo cp -a "$SRC/." "$APP/"
fi

# ---------------------------------------------------------------- 4. права, миграции, кеши
say "4/5 Права, миграции, кеши"
sudo chown -R "$(id -un):$WEB_GROUP" "$APP"
sudo chmod -R ug+rwX "$APP/storage" "$APP/bootstrap/cache"
sudo find "$APP/storage" "$APP/bootstrap/cache" -type d -exec chmod g+s {} +

php artisan package:discover --ansi >/dev/null
php artisan migrate --force
php artisan optimize:clear >/dev/null
php artisan optimize

# ---------------------------------------------------------------- 5. запуск
say "5/5 Запуск"
php artisan up
for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^(php[0-9.]*-fpm|apache2|nginx)\.service$'); do
    sudo systemctl reload "$svc" 2>/dev/null || sudo systemctl restart "$svc"
    echo "   перезапущен $svc"
done

printf '\n\033[1;32mГотово.\033[0m Версия: %s\n' "$(head -1 "$APP/RELEASE.txt" 2>/dev/null || echo '?')"
echo "Бэкап перед обновлением: $BACKUPS/uti-db-$STAMP.sql и $BACKUPS/uti-code-$STAMP.tar.gz"
