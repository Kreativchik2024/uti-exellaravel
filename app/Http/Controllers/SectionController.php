<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\User;
use App\Services\SectionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SectionController extends Controller
{
    /** Выбор участка в шапке. */
    public function switch(Request $r, SectionContext $sections): RedirectResponse
    {
        $section = Section::active()->findOrFail((int) $r->input('section_id'));
        $sections->switchTo($section);   // 403, если нет доступа

        return redirect()->route('dashboard')->with('ok', "Участок: {$section->name}");
    }

    // ---------------------------------------------------------------- админка

    public function index(): View
    {
        $sections = Section::ordered()->withCount('users')->get();

        return view('sections.index', compact('sections'));
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $this->validated($r);
        $data['slug'] = $this->uniqueSlug($r->input('slug') ?: $data['name']);
        // Новый участок — в конец списка (если порядок не задан явно)
        if (!$r->filled('sort_order')) {
            $data['sort_order'] = (int) Section::max('sort_order') + 1;
        }

        $section = Section::create($data);
        // Админ, создавший участок, сразу в нём
        $section->users()->syncWithoutDetaching([$r->user()->id]);

        return back()->with('ok', "Участок «{$section->name}» создан. Код для config/sheets.php: {$section->slug}");
    }

    public function update(Request $r, Section $section): RedirectResponse
    {
        $data = $this->validated($r, $section);

        if (!$data['is_active'] && Section::active()->whereKeyNot($section->id)->doesntExist()) {
            return back()->withErrors(['general' => 'Нельзя отключить последний активный участок.']);
        }

        $section->update($data);

        return back()->with('ok', "Участок «{$section->name}» сохранён");
    }

    /** Назначение участков пользователю (страница «Пользователи»). */
    public function assign(Request $r, User $user): RedirectResponse
    {
        $ids = $r->validate([
            'sections'   => ['array'],
            'sections.*' => ['integer', Rule::exists('sections', 'id')],
        ])['sections'] ?? [];

        $user->sections()->sync($ids);

        return back()->with('ok', "Участки пользователя {$user->name} обновлены");
    }

    // ---------------------------------------------------------------- helpers

    private function validated(Request $r, ?Section $section = null): array
    {
        $data = $r->validate([
            'name'       => ['required', 'string', 'max:120', Rule::unique('sections', 'name')->ignore($section)],
            'slug'       => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'name.unique' => 'Участок с таким названием уже есть.',
            'slug.regex'  => 'Код — только латинские буквы, цифры и дефис.',
        ]);

        return [
            'name'         => trim($data['name']),
            'sort_order'   => (int) ($data['sort_order'] ?? 0),
            'is_active'    => $r->boolean('is_active', true),
            'has_firmware' => $r->boolean('has_firmware'),
        ];
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'section';
        $slug = $base;
        for ($i = 2; Section::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        return $slug;
    }
}
