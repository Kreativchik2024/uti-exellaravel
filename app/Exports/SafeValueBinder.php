<?php

namespace App\Exports;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Защита выгрузок Excel от «формульной инъекции».
 *
 * Стандартный binder превращает любую строку, начинающуюся с «=», в формулу.
 * Значения в выгрузках вводят пользователи (номер борта, причина, ФИО…), и строка
 * вида =HYPERLINK("http://…") выполнилась бы у того, кто откроет файл.
 * Здесь такие строки всегда записываются как обычный текст.
 */
class SafeValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && str_starts_with(ltrim($value), '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
