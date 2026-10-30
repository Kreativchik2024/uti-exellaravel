<?php

namespace App\Http\Controllers;

use App\Models\FirmwareCategory;
use App\Models\FirmwareVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FirmwareController extends Controller
{
    public function index(Request $r)
    {
        $tab = $r->input('tab', 'actual');
        abort_unless(in_array($tab, ['actual','deprecated','archive']), 404);

        $cat = $r->input('category');
        $q   = $r->input('q');

        $versions = FirmwareVersion::with(['category','uploader'])
            ->where('status', $tab)
            ->when($cat, fn($x) => $x->whereHas('category', fn($c) => $c->where('slug', $cat)))
            ->when($q, fn($x) => $x->where(fn($w) => $w
                ->where('version', 'like', "%{$q}%")
                ->orWhere('notes', 'like', "%{$q}%")))
            ->latest('uploaded_at')
            ->paginate(30)
            ->withQueryString();

        $categories = FirmwareCategory::orderBy('sort_order')->get();

        return view('firmware.index', compact('tab','versions','categories','cat','q'));
    }

    public function create()
    {
        Gate::authorize('create', FirmwareVersion::class);
        $categories = FirmwareCategory::orderBy('sort_order')->get();
        return view('firmware.create', compact('categories'));
    }

    public function store(Request $r)
    {
        Gate::authorize('create', FirmwareVersion::class);

        $data = $r->validate([
            'category_id' => ['nullable', 'exists:firmware_categories,id'],
            'new_category'=> ['nullable', 'string', 'max:100'],
            'version'     => ['required', 'string', 'max:80'],
            'file'        => ['required', 'file', 'max:512000'],
            'notes'       => ['nullable', 'string'],
            'make_actual' => ['nullable', 'boolean'],
        ]);

        if (!$r->filled('category_id') && !$r->filled('new_category')) {
            throw ValidationException::withMessages([
                'category_id' => ['Выберите категорию из списка или впишите новую.'],
            ]);
        }

        // Логика создания новой категории на лету
        if ($r->filled('new_category')) {
            $title = trim($data['new_category']);
            $slug  = Str::slug($title);
            if (empty($slug)) $slug = 'cat-' . time();
            
            // Если категория с таким slug уже есть — берем её
            $cat = FirmwareCategory::firstOrCreate(
                ['slug' => $slug],
                [
                    'title'      => $title,
                    'is_system'  => false,
                    'sort_order' => 99,
                ]
            );
        } else {
            $cat = FirmwareCategory::findOrFail($data['category_id']);
        }

        $path = $r->file('file')->store("firmware/{$cat->slug}", 'local');

        $version = FirmwareVersion::create([
            'category_id' => $cat->id,
            'version'     => $data['version'],
            'status'      => $r->boolean('make_actual') ? 'actual' : 'deprecated',
            'file_path'   => $path,
            'file_size'   => $r->file('file')->getSize(),
            'sha256'      => hash_file('sha256', $r->file('file')->getRealPath()),
            'notes'       => $data['notes'] ?? null,
            'uploaded_by' => auth()->id(),
            'uploaded_at' => now(),
        ]);

        return redirect()->route('firmware.index', ['tab' => $version->status])
            ->with('ok', 'Файл загружен' . ($r->filled('new_category') ? ' (категория создана)' : ''));
    }

    public function status(Request $r, FirmwareVersion $version)
    {
        Gate::authorize('update', $version);

        $data = $r->validate([
            'status' => ['required', Rule::in(['actual','deprecated','archive'])],
        ]);

        $version->update(['status' => $data['status']]);

        return back()->with('ok', 'Статус обновлён');
    }

    public function download(FirmwareVersion $version)
    {
        Gate::authorize('download', $version);

        $full = storage_path("app/private/{$version->file_path}");
        abort_unless(is_file($full), 404, 'Файл не найден на диске');

        $ext  = pathinfo($version->file_path, PATHINFO_EXTENSION) ?: 'bin';
        $name = "{$version->category->slug}_{$version->version}.{$ext}";

        return response()->download($full, $name);
    }

    public function destroy(FirmwareVersion $version)
    {
        Gate::authorize('delete', $version);

        Storage::disk('local')->delete($version->file_path);
        $version->delete();

        return back()->with('ok', 'Прошивка удалена');
    }
}