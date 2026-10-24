<?php

namespace Database\Seeders;

use App\Models\FirmwareCategory;
use Illuminate\Database\Seeder;

class FirmwareCategorySeeder extends Seeder
{
    public function run(): void
    {
        $cats = [
            ['sophon','Sophon',10],
            ['pc','Полётный контроллер (ПК)',20],
            ['router','Роутер',30],
            ['thermal','Тепловизор',40],
            ['cross1','Кросс 1',50],
            ['cross3','Кросс 3',60],
            ['comm_module','Модуль связи',70],
        ];
        foreach ($cats as [$slug,$title,$sort]) {
            FirmwareCategory::updateOrCreate(
                ['slug'=>$slug],
                ['title'=>$title,'is_system'=>true,'sort_order'=>$sort]
            );
        }
    }
}