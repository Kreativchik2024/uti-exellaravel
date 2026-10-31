<?php

$workers = [
    'worker1' => ['label'=>'Исполнитель 1','type'=>'text','rules'=>'required|string|max:120','required_mark'=>true],
    'worker2' => ['label'=>'Исполнитель 2','type'=>'text','rules'=>'nullable|string|max:120'],
    'worker3' => ['label'=>'Исполнитель 3','type'=>'text','rules'=>'nullable|string|max:120'],
    'worker4' => ['label'=>'Исполнитель 4','type'=>'text','rules'=>'nullable|string|max:120'],
    'worker5' => ['label'=>'Исполнитель 5','type'=>'text','rules'=>'nullable|string|max:120'],
];

$faultCode = [
    'fault_code' => ['label'=>'Код неисправности','type'=>'text','rules'=>'nullable|string|max:100'],
];

return [
    'map' => [

        // =====================================================
        // ПГП (борта) — Cross3 / Bracket / Manet (все с lookup)
        // =====================================================
        'pgp' => [
            'title'  => 'ПГП (борта)',
            'model'  => \App\Models\PgpRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'post_number' => ['label'=>'Пост','type'=>'number','rules'=>'nullable|integer|min:1|max:30'],
            ], $workers, [
                'work_start'      => ['label'=>'Начало','type'=>'time','rules'=>'nullable'],
                'work_end'        => ['label'=>'Окончание','type'=>'time','rules'=>'nullable'],
                'board_number'    => ['label'=>'Номер борта','type'=>'text','rules'=>'nullable|string|max:80'],
                'ip_address'      => ['label'=>'IP','type'=>'text','rules'=>'nullable|string|max:64'],
                'fuselage_number' => ['label'=>'№ фюзеляжа','type'=>'text','rules'=>'nullable|string|max:64'],
                'frame_serial'    => ['label'=>'№ моторамы','type'=>'text','rules'=>'nullable|string|max:120'],

                'cross3_number'   => [
                    'label'=>'№ Кросс 3',
                    'type'=>'text',
                    'rules'=>'nullable|string|max:120',
                    'lookup'=>['sheet'=>'cross3','match_by'=>'serial_number','prefix'=>'cross3'],
                ],
                'bracket_serial'  => [
                    'label'=>'Номер камеры',
                    'type'=>'text',
                    'rules'=>'nullable|string|max:120',
                    'lookup'=>['sheet'=>'camera_bracket','match_by'=>'serial_number','prefix'=>'bracket'],
                ],
                'manet_number'    => [
                    'label'=>'№ Манета',
                    'type'=>'text',
                    'rules'=>'nullable|string|max:120',
                    'lookup'=>['sheet'=>'comm_module','match_by'=>'serial_number','prefix'=>'manet'],
                ],

                'status'          => ['label'=>'Статус','type'=>'select','rules'=>'required',
                                       'options'=>['Сдан','Не сдан','В работе','Исправен','Неисправен','В ремонт']],
                'defect_reason'   => ['label'=>'Причина','type'=>'textarea','rules'=>'nullable|string'],
                'actions_taken'   => ['label'=>'Действия','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),

            'health_fields'      => ['status'],
            'health_good_values' => ['Сдан','Исправен'],
            'list_columns' => [
                'record_date',
                'post_number',
                'board_number',
                'cross3_number',
                'bracket_serial',
                'status',
            ],
        ],

        // =====================================================
        // Модуль связи
        // =====================================================
        'comm_module' => [
            'title'  => 'Модуль связи',
            'model'  => \App\Models\CommModuleRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date'   => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'serial_number' => ['label'=>'S/N','type'=>'text','rules'=>'required|string|max:120'],
                'ip_address'    => ['label'=>'IP','type'=>'text','rules'=>'nullable|string|max:64'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','serial_number','ip_address','fault_code'],
        ],

        // =====================================================
        // Кросс 1
        // =====================================================
        'cross1' => [
            'title'  => 'Кросс 1',
            'model'  => \App\Models\Cross1Record::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Кросс 3 — сюда переехал «Барометр» из camera_bracket
        // =====================================================
        'cross3' => [
            'title'  => 'Кросс 3',
            'model'  => \App\Models\Cross3Record::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date'     => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'serial_number'   => ['label'=>'Заводской №','type'=>'text','rules'=>'nullable|string|max:120'],
                'pc_version'      => ['label'=>'ПК версия','type'=>'text','rules'=>'nullable|string|max:80'],
                'ahrs'            => ['label'=>'AHRS','type'=>'text','rules'=>'nullable|string|max:20'],
                'compass_version' => ['label'=>'Компас, версия','type'=>'text','rules'=>'nullable|string|max:80'],
                'barometer_status'=> ['label'=>'Барометр','type'=>'select','rules'=>'nullable',
                                      'options'=>['Работает','Не работает','Требует замены']],
            ], $workers, [
                'notes'      => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
                'defect'     => ['label'=>'Дефект','type'=>'textarea','rules'=>'nullable|string'],
                'status'     => ['label'=>'Статус проверки','type'=>'select','rules'=>'nullable',
                                 'options'=>['Проверен','Требует ремонта','Брак','В работе']],
                'is_working' => ['label'=>'Исправен','type'=>'select','rules'=>'required',
                                 'options'=>['Исправен','Неисправен']],
            ], $faultCode),
            'health_fields'      => ['is_working'],
            'health_good_values' => ['Исправен'],
            'list_columns' => ['record_date','serial_number','pc_version','is_working','fault_code'],
        ],

        // =====================================================
        // Кросс 5
        // =====================================================
        'cross5' => [
            'title'  => 'Кросс 5',
            'model'  => \App\Models\Cross5Record::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Мини ПК
        // =====================================================
        'mini_pc' => [
            'title'  => 'Мини ПК',
            'model'  => \App\Models\MiniPcRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
                'device_type' => ['label'=>'Тип','type'=>'select','rules'=>'nullable',
                                  'options'=>['Роутер','Софон','Регулятор мотора','Прочее']],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','device_type','fault_code'],
        ],

        // =====================================================
        // Исследования
        // =====================================================
        'research' => [
            'title'  => 'Исследования',
            'model'  => \App\Models\ResearchRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'seq'         => ['label'=>'№','type'=>'number','rules'=>'nullable|integer'],
            ], $workers, [
                'fuselage_no'     => ['label'=>'№ фюзеляжа','type'=>'text','rules'=>'nullable|string|max:64'],
                'fuselage_serial' => ['label'=>'Фюзеляж с аппаратурой','type'=>'text','rules'=>'nullable|string|max:120'],
                'frame_serial'    => ['label'=>'Моторама с аппаратурой','type'=>'text','rules'=>'nullable|string|max:120'],
                'bracket_serial'  => ['label'=>'Кронштейн с камерами','type'=>'text','rules'=>'nullable|string|max:120'],
                'reported_defect' => ['label'=>'Отказ заявленный','type'=>'textarea','rules'=>'nullable|string'],
                'rejected_by'     => ['label'=>'Кто забраковал','type'=>'text','rules'=>'nullable|string|max:120'],
                'analysis_actions'=> ['label'=>'Действия для анализа','type'=>'textarea','rules'=>'nullable|string'],
                'found_defect'    => ['label'=>'Дефект при анализе','type'=>'textarea','rules'=>'nullable|string'],
                'result'          => ['label'=>'Результат','type'=>'textarea','rules'=>'nullable|string'],
                'is_repaired'     => ['label'=>'Отремонтировано','type'=>'select','rules'=>'required|boolean',
                                      'options'=>['1'=>'Да','0'=>'Нет']],
            ], $faultCode),
            'list_columns' => ['record_date','fuselage_no','frame_serial','bracket_serial','is_repaired'],
        ],

        // =====================================================
        // Проверка ПК
        // =====================================================
        'pc_check' => [
            'title'  => 'Проверка ПК',
            'model'  => \App\Models\PcCheckRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Проверка Sophon
        // =====================================================
        'sophon_check' => [
            'title'  => 'Проверка Sophon',
            'model'  => \App\Models\SophonCheckRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
            ], $workers, [
                'reported_defect' => ['label'=>'Заявленный дефект','type'=>'textarea','rules'=>'nullable|string'],
                'rejected_by'     => ['label'=>'Кто забраковал','type'=>'text','rules'=>'nullable|string|max:120'],
                'found_defect'    => ['label'=>'Выявленный дефект','type'=>'textarea','rules'=>'nullable|string'],
                'work_done'       => ['label'=>'Проделанная работа','type'=>'textarea','rules'=>'nullable|string'],
                'stand_test'      => ['label'=>'Тест на стенде','type'=>'text','rules'=>'nullable|string|max:120'],
                'notes'           => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
                'status'          => ['label'=>'Статус','type'=>'text','rules'=>'nullable|string|max:80'],
            ], $faultCode),
            'list_columns' => ['record_date','reported_defect','found_defect','status','fault_code'],
        ],

        // =====================================================
        // Установка ПО на борт
        // =====================================================
        'software_install' => [
            'title'  => 'Установка ПО на борт',
            'model'  => \App\Models\SoftwareInstallRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Регулятор мотора
        // =====================================================
        'motor_regulator' => [
            'title'  => 'Регулятор мотора',
            'model'  => \App\Models\MotorRegulatorRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Провода питания
        // =====================================================
        'power_wires' => [
            'title'  => 'Провода питания',
            'model'  => \App\Models\PowerWiresRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Data 15
        // =====================================================
        'data15' => [
            'title'  => 'Data 15',
            'model'  => \App\Models\Data15Record::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Data 17
        // =====================================================
        'data17' => [
            'title'  => 'Data 17',
            'model'  => \App\Models\Data17Record::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Кронштейн с камерой — БЕЗ barometer_status (переехал в cross3)
        // =====================================================
        'camera_bracket' => [
            'title'  => 'Кронштейн с камерой',
            'model'  => \App\Models\CameraBracketRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date'         => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'serial_number'       => ['label'=>'S/N кронштейна','type'=>'text','rules'=>'nullable|string|max:120'],
                'camera_status'       => ['label'=>'Камера','type'=>'select','rules'=>'nullable',
                                            'options'=>['Работает','Не работает','Требует ремонта']],
                'night_camera_status' => ['label'=>'Ночная камера','type'=>'select','rules'=>'nullable',
                                            'options'=>['Работает','Не работает','Требует ремонта']],
                'speed_sensor_status' => ['label'=>'Датчик скорости','type'=>'select','rules'=>'nullable',
                                            'options'=>['Работает','Не работает','Требует замены']],
                'suspension_status'   => ['label'=>'Подвес камеры','type'=>'select','rules'=>'nullable',
                                            'options'=>['Работает','Не работает','Требует ремонта']],
            ], $workers, [
                'notes'    => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
                'comments' => ['label'=>'Комментарии','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'health_fields' => [
                'camera_status',
                'night_camera_status',
                'speed_sensor_status',
                'suspension_status',
            ],
            'list_columns' => ['record_date','serial_number'],
        ],

        // =====================================================
        // Ночная камера в сборе с дневной
        // =====================================================
        'night_camera_assembly' => [
            'title'  => 'Ночная камера в сборе с дневной',
            'model'  => \App\Models\NightCameraAssemblyRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Прошивка роутеров
        // =====================================================
        'router_firmware' => [
            'title'  => 'Прошивка роутеров',
            'model'  => \App\Models\RouterFirmwareRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

        // =====================================================
        // Датчик скорости
        // =====================================================
        'speed_sensor' => [
            'title'  => 'Датчик скорости',
            'model'  => \App\Models\SpeedSensorRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date'   => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'serial_number' => ['label'=>'Серийный номер','type'=>'text','rules'=>'required|string|max:120'],
                'is_working'    => ['label'=>'Рабочий','type'=>'select','rules'=>'required',
                                    'options'=>['Да','Нет']],
                'runs_count'    => ['label'=>'Кол-во прогонов','type'=>'number','rules'=>'required|integer|min:1',
                                    'default'=>3],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','serial_number','is_working','runs_count','fault_code'],
        ],

        // =====================================================
        // Проверка винтов
        // =====================================================
        'screw_check' => [
            'title'  => 'Проверка винтов',
            'model'  => \App\Models\ScrewCheckRecord::class,
            'policy' => \App\Policies\RecordPolicy::class,
            'fields' => array_merge([
                'record_date' => ['label'=>'Дата','type'=>'date','rules'=>'required|date'],
                'qty'         => ['label'=>'Кол-во за день','type'=>'number','rules'=>'required|integer|min:1'],
            ], $workers, [
                'notes' => ['label'=>'Примечание','type'=>'textarea','rules'=>'nullable|string'],
            ], $faultCode),
            'list_columns' => ['record_date','qty','fault_code'],
        ],

    ],
];