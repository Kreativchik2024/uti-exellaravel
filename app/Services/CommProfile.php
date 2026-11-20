<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Профиль прошивки модулей связи (MANET Flasher) — общий для всех рабочих мест:
 *  - значения из инструкции (частота, аттенюации, калибровка, DHCP, Data Cache, UART, мощность…);
 *  - файлы бэкапа .msconf («Update All Nodes → Import»), один из них — рабочий.
 * Хранение — storage (диск local): comm_profile/settings.json и comm_profile/msconf/*.msconf.
 */
class CommProfile
{
    public const DIR = 'comm_profile';

    /** Параметры: ключ = поле Target во флешере. type: int | num | bool | str. */
    public const PARAMS = [
        'RF Measurement' => [
            'rf_freq_mhz' => ['label' => 'RF Frequency, МГц', 'type' => 'num', 'default' => 1650],
            'rf_atten'    => ['label' => 'TX1 / TX2 RF Attenuation, dB', 'type' => 'int', 'default' => 36],
            'min_atten'   => ['label' => 'Minimum Attenuation Value, dB', 'type' => 'int', 'default' => 9],
            'tfci'        => ['label' => 'TCFI Value', 'type' => 'int', 'default' => 18],
        ],
        'RF Power Calibration' => [
            'power_max_mw' => ['label' => 'Maximum RF Tx Power, мВт', 'type' => 'int', 'default' => 5000],
            'freq_min_mhz' => ['label' => 'Start Frequency, МГц', 'type' => 'num', 'default' => 1600],
            'freq_max_mhz' => ['label' => 'End Frequency, МГц', 'type' => 'num', 'default' => 1800],
            'cal_atten'    => ['label' => 'RF Attenuation TX1 / TX2, dB', 'type' => 'int', 'default' => 9],
        ],
        'Сеть и порты' => [
            'factory_ip'  => ['label' => 'Заводской IP модуля', 'type' => 'str', 'default' => '192.168.10.42'],
            'net_prefix'  => ['label' => 'Сеть модулей (без последнего числа)', 'type' => 'str', 'default' => '192.168.1'],
            'nw_mask'     => ['label' => 'Маска', 'type' => 'str', 'default' => '255.255.255.0'],
            'blocked'     => ['label' => 'Запрещённые номера IP', 'type' => 'str', 'default' => '1, 40-42, 100, 200, 205, 250-254'],
            'dhcp_server' => ['label' => 'DHCP-сервер включён', 'type' => 'bool', 'default' => false],
            'data_cache'  => ['label' => 'Data Cache, мс', 'type' => 'int', 'default' => 500],
            'uart0_port'  => ['label' => 'UART0 — Tcp Server, порт', 'type' => 'int', 'default' => 1027],
            'uart1_port'  => ['label' => 'UART1 — Tcp Server, порт', 'type' => 'int', 'default' => 1024],
        ],
        'Мощность и проверки' => [
            'final_power_mw'      => ['label' => 'Итоговая мощность антенн, мВт', 'type' => 'num', 'default' => 10],
            'test_power_mw'       => ['label' => 'Проверка мощностью, мВт', 'type' => 'num', 'default' => 5000],
            'test_power_hold'     => ['label' => 'Проверка мощностью — держать, c', 'type' => 'int', 'default' => 10],
            'stock_max_nodes'     => ['label' => 'Стоковая лицензия — узлов не больше', 'type' => 'int', 'default' => 2],
            'msconf_global'       => ['label' => 'Импорт .msconf на все узлы (configGlobal)', 'type' => 'bool', 'default' => true],
            'topology_first_wait' => ['label' => 'Топология: ждать до перезагрузки, c', 'type' => 'int', 'default' => 20],
            'topology_wait'       => ['label' => 'Топология: ждать после перезагрузки, c', 'type' => 'int', 'default' => 120],
            'topology_reboots'    => ['label' => 'Топология: перезагрузок', 'type' => 'int', 'default' => 1],
        ],
    ];

    private function disk()
    {
        return Storage::disk('local');
    }

    public static function flatParams(): array
    {
        return array_merge(...array_values(self::PARAMS));
    }

    public function settings(): array
    {
        $raw = $this->disk()->exists(self::DIR . '/settings.json')
            ? json_decode($this->disk()->get(self::DIR . '/settings.json'), true) ?: [] : [];
        $params = [];
        foreach (self::flatParams() as $k => $p) {
            $params[$k] = array_key_exists($k, $raw['params'] ?? []) ? $raw['params'][$k] : $p['default'];
        }

        return ['params' => $params, 'active' => $raw['active'] ?? null,
                'updated_at' => $raw['updated_at'] ?? null, 'updated_by' => $raw['updated_by'] ?? null];
    }

    private function save(array $s, ?string $by): void
    {
        $s['updated_at'] = now()->toIso8601String();
        $s['updated_by'] = $by;
        $this->disk()->put(self::DIR . '/settings.json', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** Значения из формы → типы параметров. */
    public function saveParams(array $input, ?string $by): void
    {
        $s = $this->settings();
        foreach (self::flatParams() as $k => $p) {
            $v = $input[$k] ?? null;
            $s['params'][$k] = match ($p['type']) {
                'bool' => (bool) $v,
                'int'  => (int) $v,
                'num'  => is_numeric($v) && (float) $v == (int) $v ? (int) $v : (float) $v,
                default => trim((string) $v),
            };
        }
        $this->save($s, $by);
    }

    /** @return array<int, array{name: string, size: int, mtime: int, version: string, mesh: string, active: bool}> */
    public function msconfs(): array
    {
        $active = $this->settings()['active'];
        $out = [];
        foreach ($this->disk()->files(self::DIR . '/msconf') as $f) {
            if (!str_ends_with(strtolower($f), '.msconf')) continue;
            $js = json_decode($this->disk()->get($f), true) ?: [];
            $out[] = ['name' => basename($f), 'size' => $this->disk()->size($f), 'mtime' => $this->disk()->lastModified($f),
                      'version' => trim($js['version'] ?? ''), 'mesh' => $js['uniformityParam']['meshName'] ?? '',
                      'active' => basename($f) === $active];
        }
        usort($out, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $out;
    }

    /** Проверить .msconf (JSON {version, uniformityParam}) и сохранить; первый — сразу рабочий. */
    public function storeMsconf(UploadedFile $file, bool $makeActive, ?string $by): string
    {
        $js = json_decode((string) file_get_contents($file->getRealPath()), true);
        if (!is_array($js) || !is_array($js['uniformityParam'] ?? null) || !filled($js['version'] ?? null)) {
            throw new \InvalidArgumentException('Это не бэкап настроек модуля: нет version / uniformityParam');
        }
        $name = app(CommLicenses::class)->batchName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.msconf';
        $file->storeAs(self::DIR . '/msconf', $name, 'local');
        $s = $this->settings();
        if ($makeActive || !$s['active']) {
            $s['active'] = $name;
            $this->save($s, $by);
        }

        return $name;
    }

    public function activate(string $name, ?string $by): void
    {
        abort_unless($this->disk()->exists(self::DIR . '/msconf/' . basename($name)), 404);
        $s = $this->settings();
        $s['active'] = basename($name);
        $this->save($s, $by);
    }

    public function deleteMsconf(string $name, ?string $by): void
    {
        $this->disk()->delete(self::DIR . '/msconf/' . basename($name));
        $s = $this->settings();
        if ($s['active'] === basename($name)) {
            $s['active'] = null;
            $this->save($s, $by);
        }
    }

    public function msconfPath(?string $name = null): ?string
    {
        $name = basename($name ?? (string) $this->settings()['active']);
        $p = self::DIR . '/msconf/' . $name;

        return $name !== '' && $this->disk()->exists($p) ? $p : null;
    }

    /** Для программ: параметры + рабочий .msconf (имя, sha256, время). */
    public function forStation(): array
    {
        $s = $this->settings();
        $path = $this->msconfPath();
        $ms = $path ? ['name' => basename($path), 'size' => $this->disk()->size($path),
                       'mtime' => $this->disk()->lastModified($path),
                       'sha256' => hash_file('sha256', $this->disk()->path($path))] : null;

        return ['params' => $s['params'], 'msconf' => $ms, 'updated_at' => $s['updated_at']];
    }
}
