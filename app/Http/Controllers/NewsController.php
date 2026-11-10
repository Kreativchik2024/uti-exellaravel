<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsImage;
use App\Services\SectionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class NewsController extends Controller
{
    public function __construct(private SectionContext $sections) {}

    /** Новость видна, если она общая или с доступного участка. */
    private function canSee(News $news): bool
    {
        return $news->section_id === null
            || $this->sections->available()->contains('id', $news->section_id);
    }

    /** Менять общую новость может только админ; участковую — с доступом к участку. */
    private function authorizeSection(News $news): void
    {
        abort_unless(
            $news->section_id === null ? (bool) auth()->user()?->isAdmin() : $this->canSee($news),
            403, 'Нет доступа к новостям этого участка'
        );
    }

    /** Проверка выбранного в форме участка: null («для всех») — только админ. */
    private function sectionFromRequest(Request $r): ?int
    {
        $id = $r->input('section_id');
        if ($id === null || $id === '' || $id === 'all') {
            abort_unless(auth()->user()->isAdmin(), 403, 'Новость для всех участков может опубликовать только администратор');
            return null;
        }
        abort_unless($this->sections->available()->contains('id', (int) $id), 403, 'Нет доступа к участку');
        return (int) $id;
    }

    public function index(Request $r)
    {
        $news = News::with(['author','images', 'section'])
            ->forSection($this->sections->current())
            ->published()
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(20);

        return view('news.index', compact('news'));
    }

    public function show(News $news)
    {
        // Черновики и отложенные новости видят только мастер/админ
        $published = $news->is_published && (!$news->published_at || $news->published_at->lte(now()));
        if (!$published && !auth()->user()?->canManageContent()) {
            abort(404);
        }
        abort_unless($this->canSee($news), 404);

        $news->load(['author', 'images', 'section']);

        return view('news.show', compact('news'));
    }

    public function create()
    {
        Gate::authorize('create', News::class);
        return view('news.create', ['sections' => $this->sections->available(), 'currentSection' => $this->sections->current()]);
    }

    public function store(Request $r)
    {
        Gate::authorize('create', News::class);

        $data = $r->validate([
            'title'      => 'nullable|string|max:200',
            'body'       => 'required|string|max:5000',
            'images'     => 'nullable|array|max:10',
            'images.*'   => 'nullable|image|max:10240',
            'captions'   => 'nullable|array',
            'captions.*' => 'nullable|string|max:255',
            'is_pinned'  => 'nullable|boolean',
        ]);

        $news = News::create([
            'section_id'   => $this->sectionFromRequest($r),
            'title'        => $data['title'] ?? Str::limit(strip_tags($data['body']), 60),
            'body'         => $data['body'],
            'is_pinned'    => $r->boolean('is_pinned'),
            'is_published' => true,
            'author_id'    => auth()->id(),
            'published_at' => now(),
        ]);

        $this->attachImages($news, $r->file('images', []), $r->input('captions', []));

        \App\Models\AuditLog::write('news', 'created', 'Новость: ' . $news->title, ['subject_type' => 'news', 'subject_id' => $news->id, 'section_id' => $news->section_id]);
        return redirect()->route('dashboard')->with('ok', 'Новость опубликована');
    }

    public function edit(News $news)
    {
        Gate::authorize('update', $news);
        $this->authorizeSection($news);
        $news->load('images');
        return view('news.edit', ['news' => $news, 'sections' => $this->sections->available(), 'currentSection' => $this->sections->current()]);
    }

    public function update(Request $r, News $news)
    {
        Gate::authorize('update', $news);
        $this->authorizeSection($news);

        $data = $r->validate([
            'title'               => 'nullable|string|max:200',
            'body'                => 'required|string|max:5000',
            'images'              => 'nullable|array|max:10',
            'images.*'            => 'nullable|image|max:10240',
            'captions'            => 'nullable|array',
            'captions.*'          => 'nullable|string|max:255',
            'existing_captions'   => 'nullable|array',
            'existing_captions.*' => 'nullable|string|max:255',
            'is_pinned'           => 'nullable|boolean',
        ]);

        $news->update([
            'section_id' => $this->sectionFromRequest($r),
            'title'     => $data['title'] ?? Str::limit(strip_tags($data['body']), 60),
            'body'      => $data['body'],
            'is_pinned' => $r->boolean('is_pinned'),
        ]);

        if ($r->has('existing_captions')) {
            foreach ($r->input('existing_captions', []) as $imageId => $caption) {
                $img = $news->images()->find($imageId);
                if ($img) {
                    $img->update(['caption' => $caption]);
                }
            }
        }

        $this->attachImages($news, $r->file('images', []), $r->input('captions', []));

        \App\Models\AuditLog::write('news', 'updated', 'Новость: ' . $news->title, ['subject_type' => 'news', 'subject_id' => $news->id, 'section_id' => $news->section_id]);
        return redirect()->route('news.index')->with('ok', 'Новость обновлена');
    }

    public function destroy(News $news)
    {
        Gate::authorize('delete', $news);
        $this->authorizeSection($news);

        foreach ($news->images as $img) {
            Storage::disk('local')->delete([$img->path, $img->thumb_path]);
        }
        \App\Models\AuditLog::write('news', 'deleted', 'Новость: ' . $news->title, ['subject_type' => 'news', 'subject_id' => $news->id, 'section_id' => $news->section_id]);
        $news->delete();

        return redirect()->route('news.index')->with('ok', 'Новость удалена');
    }

    public function destroyImage(News $news, NewsImage $image)
    {
        Gate::authorize('update', $news);
        $this->authorizeSection($news);
        abort_unless((int) $image->news_id === (int) $news->id, 404);

        Storage::disk('local')->delete([$image->path, $image->thumb_path]);
        $image->delete();

        return back()->with('ok', 'Картинка удалена');
    }

    public function latest(Request $request)
    {
        $after = (int) $request->query('after', 0);

        $news = News::query()
            ->forSection($this->sections->current())
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('id')
            ->first();

        if (!$news || (int) $news->id <= $after) {
            return response()->json(null)->header('Cache-Control', 'no-store');
        }

        $cover = $news->images()->orderBy('sort_order')->first();

        return response()->json([
            'id'        => $news->id,
            'title'     => $news->title ?: Str::limit(strip_tags($news->body), 80),
            'body'      => Str::limit(strip_tags($news->body ?? ''), 600),
            'cover_url' => $cover ? route('media.show', ['path' => $cover->thumb_path ?? $cover->path]) : null,
            'url'       => route('news.show', $news),
        ])->header('Cache-Control', 'no-store');
    }

    private function attachImages(News $news, array $files, array $captions = []): void
    {
        $start = (int) ($news->images()->max('sort_order') ?? 0);

        foreach ($files as $i => $file) {
            if (! $file) continue;

            $path  = $file->store("news/{$news->id}", 'local');
            $thumb = "news/{$news->id}/thumb_".basename($path);

            $image = Image::decode(storage_path("app/private/{$path}"));
            $image->scaleDown(width: 400);
            $image->save(storage_path("app/private/{$thumb}"));

            $news->images()->create([
                'path'       => $path,
                'thumb_path' => $thumb,
                'caption'    => $captions[$i] ?? null,
                'sort_order' => $start + $i,
            ]);
        }
    }
}