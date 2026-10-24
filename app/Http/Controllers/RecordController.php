<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordController extends Controller
{
    private function cfg(string $sheet): array
    {
        $cfg = config("sheets.map.$sheet");
        abort_unless($cfg, 404, "Вкладка {$sheet} не найдена");
        return $cfg;
    }

    public function index(Request $request, string $sheet)
    {
        $cfg = $this->cfg($sheet);

        $query = $cfg['model']::query();

        // Если исполнитель — показываем только его записи
        if (auth()->user()->isExecutor()) {
            $query->where('created_by', auth()->id());
        }

        // П О И С К
        $q = trim($request->input('q', ''));
        if ($q !== '') {
            // Собираем текстовые поля из конфига
            $searchableFields = [];
            foreach ($cfg['fields'] as $fieldName => $fieldCfg) {
                if (in_array($fieldCfg['type'], ['text', 'textarea', 'string'])) {
                    $searchableFields[] = $fieldName;
                }
            }

            if (!empty($searchableFields)) {
                $query->where(function ($w) use ($searchableFields, $q) {
                    foreach ($searchableFields as $field) {
                        $w->orWhere($field, 'like', "%{$q}%");
                    }
                });
            }
        }

        $records = $query->latest('id')->paginate(50)->withQueryString();

        return view('records.index', compact('sheet','cfg','records', 'q'));
    }

    public function create(string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);
        return view('records.create', compact('sheet','cfg'));
    }

    public function store(Request $r, string $sheet)
    {
        $cfg = $this->cfg($sheet);
        Gate::authorize('create', $cfg['model']);

        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $data  = $r->validate($rules);
        $data['created_by'] = auth()->id();

        $cfg['model']::create($data);

        return redirect()->route('records.index', $sheet)->with('ok', 'Запись добавлена');
    }

    public function edit(string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('update', $record);
        return view('records.edit', compact('sheet','cfg','record'));
    }

    public function update(Request $r, string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('update', $record);

        $rules = collect($cfg['fields'])->mapWithKeys(fn($f, $k) => [$k => $f['rules']])->all();
        $data  = $r->validate($rules);
        $data['updated_by'] = auth()->id();

        $record->update($data);

        return redirect()->route('records.index', $sheet)->with('ok', 'Запись обновлена');
    }

    public function destroy(string $sheet, int $id)
    {
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);
        Gate::authorize('delete', $record);
        $record->delete();
        return back()->with('ok', 'Запись удалена');
    }
}