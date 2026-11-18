#!/usr/bin/env bash
# =============================================================================
#  security-update — проверка и обновление пакетов с известными уязвимостями.
#
#  Использование:
#      bash scripts/security-update.sh          — только проверка (можно на сервере)
#      bash scripts/security-update.sh --fix    — обновить уязвимые пакеты
#      bash scripts/security-update.sh --fix --skip-tests
#
#  --fix меняет composer.lock и package-lock.json. На сервере его не запускать:
#  uti-update берёт код из GitHub и откатывает местные правки lock-файлов.
#  Порядок: --fix на компьютере разработчика (или scripts\security-update.ps1
#  в Windows) → коммит и push → на сервере uti-update.
#
#  При --fix: обновляются только уязвимые PHP-пакеты (composer audit),
#  JS — npm audit fix без --force; затем сборка и тесты. Если что-то упало —
#  lock-файлы и пакеты возвращаются как были.
# =============================================================================
set -uo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP"

FIX=0; TESTS=1
for a in "$@"; do
    case "$a" in
        --fix) FIX=1 ;;
        --skip-tests) TESTS=0 ;;
        -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
        *) echo "Неизвестный параметр: $a"; exit 2 ;;
    esac
done

PHP="${PHP:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"
say()  { printf '\n\033[36m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[32m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m%s\033[0m\n' "$*"; }
die()  { printf '\033[31m%s\033[0m\n' "$*"; exit 1; }

command -v "$COMPOSER" >/dev/null || die "composer не найден (путь можно задать: COMPOSER_BIN=/path/composer)"

# Уязвимые PHP-пакеты по composer.lock — по одному имени в строке
vulnerable_php() {
    "$COMPOSER" audit --locked --format=json --no-interaction 2>/dev/null |
        "$PHP" -r '$j = json_decode(stream_get_contents(STDIN), true); if (!is_array($j)) exit(1); foreach (array_keys($j["advisories"] ?? []) as $p) echo $p, PHP_EOL;'
}

report() {
    say "Итог"
    local left
    if ! left="$(vulnerable_php)"; then
        warn "PHP: проверить не удалось (composer audit с ошибкой — нет интернета или composer не работает)"
    elif [ -n "$left" ]; then
        warn "PHP: есть уязвимые пакеты: $(echo $left)"
        [ "$FIX" = 0 ] && warn "   исправить: bash scripts/security-update.sh --fix (на компьютере разработчика)"
    else
        ok "PHP: уязвимостей нет."
    fi
    if command -v npm >/dev/null; then
        if npm audit --omit=dev >/dev/null 2>&1; then
            ok "JS (то, что попадает на сайт): уязвимостей нет."
        else
            warn "JS: есть уязвимости в пакетах сайта — подробности: npm audit --omit=dev"
        fi
        echo "JS (инструменты сборки): подробности — npm audit"
    fi
}

if [ "$FIX" = 0 ]; then
    say "Проверка (ничего не меняется)"
    "$COMPOSER" audit --locked --no-interaction || true
    report
    exit 0
fi

# ---------------------------------------------------------------- --fix
if [ -d .git ] && git remote -v 2>/dev/null | grep -q . && [ -x /usr/local/bin/uti-update ]; then
    warn "Похоже, это сервер (установлен uti-update). Правки lock-файлов здесь откатятся при следующем обновлении."
    read -r -p "Всё равно продолжить? [y/N] " ans
    [ "$ans" = "y" ] || exit 1
fi
command -v npm >/dev/null || die "npm не найден"

BACKUP="$(mktemp -d /tmp/uti-security-XXXXXX)"
cp composer.lock package-lock.json "$BACKUP/"

rollback() {
    warn "Откат: возвращаю composer.lock и package-lock.json"
    cp "$BACKUP/composer.lock" "$BACKUP/package-lock.json" "$APP/"
    "$COMPOSER" install --no-interaction
    npm ci
    npm run build
    die "Откат выполнен, пакеты как до запуска. Резервные копии: $BACKUP"
}

say "PHP: поиск уязвимых пакетов (composer audit)"
LIST="$(vulnerable_php)" || die "composer audit не сработал — проверьте интернет и composer"
mapfile -t PKGS < <(printf '%s' "$LIST" | sed '/^$/d')
if [ "${#PKGS[@]}" -gt 0 ]; then
    warn "Обновляются: ${PKGS[*]}"
    "$COMPOSER" update "${PKGS[@]}" --with-all-dependencies --no-interaction || rollback
else
    ok "Уязвимых PHP-пакетов нет."
fi

say "JS: безопасные обновления (npm audit fix)"
# ошибка npm audit fix = часть уязвимостей без --force не исправить; это не провал
npm audit fix || true

say "Сборка CSS/JS"
npm run build || rollback

if [ "$TESTS" = 1 ]; then
    if [ -x vendor/bin/phpunit ]; then
        say "Тесты"
        "$PHP" artisan test --compact || rollback
    else
        warn "Тесты пропущены: не установлены dev-пакеты (vendor/bin/phpunit)"
    fi
fi

report
echo
echo "Изменения: git diff --stat composer.lock package-lock.json. Резервные копии: $BACKUP"
