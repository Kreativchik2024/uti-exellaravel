<?php

namespace App\Http\Controllers;

use App\Models\FirmwareCategory;
use App\Models\FirmwareVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class FirmwareCategoryController extends Controller
{
    public function index()
    {
        Gate::authorize('manageCategories', FirmwareVersion::class);
        $categories = FirmwareCategory::orderBy('sort_order')->get();
        return view('firmware.categories', compact('categories'));
    }

    public function store(Request $r)
    {
        Gate::authorize('manageCategories', FirmwareVersion::class);

        $data = $r->validate([
            'title'      => ['required', 'string', 'max:120'],
            'slug'       => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/', 'unique:firmware_categories,slug'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $slug = $data['slug'] ?: Str::slug($data['title'], '_');
        if (! $slug || FirmwareCategory::where('slug', $slug)->exists()) {
            return back()->withErrors(['slug' => 'Slug пустой или уже занят'])->withInput();
        }

        FirmwareCategory::create([
            'slug'       => $slug,
            'title'      => $data['title'],
            'is_system'  => false,
            'sort_order' => $data['sort_order'] ?? 100,
        ]);

        return back()->with('ok', 'Категория добавлена');
    }

    public function destroy(FirmwareCategory $category)
    {
        Gate::authorize('manageCategories', FirmwareVersion::class);

        if ($category->is_system) {
            return back()->withErrors(['general' => 'Системную категорию удалить нельзя']);
        }
        if ($category->versions()->exists()) {
            return back()->withErrors(['general' => 'Сначала удалите все версии этой категории']);
        }
        $category->delete();

        return back()->with('ok', 'Категория удалена');
    }
}