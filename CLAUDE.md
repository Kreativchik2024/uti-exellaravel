# Проект

Laravel 13 (PHP ^8.3), Blade + Alpine.js + Tailwind 3, сборка Vite. Тесты — PHPUnit 12. Окружение — OSPanel (Windows).

## Команды

- Консольный `php` (8.5) сломан — artisan и тесты запускать через `C:/OSPanel/modules/PHP-8.4/php.exe`:
  - `C:/OSPanel/modules/PHP-8.4/php.exe artisan test --compact`
  - `C:/OSPanel/modules/PHP-8.4/php.exe artisan test --compact --filter=ИмяТеста`
- Laravel Boost НЕ устанавливать.

## Правила работы

- Отвечать по-русски, кратко, без вступлений; не дублировать код файла в чат — указать, что и где изменено.
- Не читать `vendor/`, `node_modules/`, `public/build/`, `storage/`, `releases/`, `.venv-import/`, лок-файлы, `*.min.*`, `*.map`, `*.sql`, `*.tgz` без явной необходимости.
- Большие файлы (> 300 строк) читать нужным диапазоном, а не целиком.
- Ограничивать вывод: `git diff --stat`, `git log --oneline -n 20`, при падении тестов — только хвост лога (`| tail -n 80`).
- Не повторять одну и ту же упавшую команду больше 2 раз — остановиться и описать причину.
