<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class NewsController extends Controller
{
    public function index(Request $r)
    {
        $news = News::with(['author','images'])
            ->published()
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->paginate(20);

        return view('news.index', compact('news'));
    }

    public function create()
    {
        Gate::authorize('create', News::class);
        return view('news.create');
    }

    public function store(Request $r)
    {
        Gate::authorize('create', News::class);

        $data = $r->validate([
            'title'    => 'nullable|string|max:200',
            'body'     => 'required|string|max:5000',
            'images.*' => 'nullable|image|max:10240',
            'is_pinned'=> 'nullable|boolean',
        ]);

        $news = News::create([
            'title'        => $data['title'] ?? Str::limit(strip_tags($data['body']), 60),
            'body'         => $data['body'],
            'is_pinned'    => $r->boolean('is_pinned'),
            'author_id'    => auth()->id(),
            'published_at' => now(),
        ]);

        $this->attachImages($news, $r->file('images', []));

        return redirect()->route('dashboard')->with('ok', 'Новость опубликована');
    }

    public function edit(News $news)
    {
        Gate::authorize('update', $news);
        $news->load('images');
        return view('news.edit', compact('news'));
    }

    public function update(Request $r, News $news)
    {
        Gate::authorize('update', $news);

        $data = $r->validate([
            'title'    => 'nullable|string|max:200',
            'body'     => 'required|string|max:5000',
            'images.*' => 'nullable|image|max:10240',
            'is_pinned'=> 'nullable|boolean',
        ]);

        $news->update([
            'title'     => $data['title'] ?? Str::limit(strip_tags($data['body']), 60),
            'body'      => $data['body'],
            'is_pinned' => $r->boolean('is_pinned'),
        ]);

        $this->attachImages($news, $r->file('images', []));

        return redirect()->route('news.index')->with('ok', 'Новость обновлена');
    }

    public function destroy(News $news)
    {
        Gate::authorize('delete', $news);

        foreach ($news->images as $img) {
            Storage::disk('local')->delete([$img->path, $img->thumb_path]);
        }
        $news->delete();

        return back()->with('ok', 'Новость удалена');
    }

    public function destroyImage(News $news, NewsImage $image)
    {
        Gate::authorize('update', $news);
        abort_unless($image->news_id === $news->id, 404);

        Storage::disk('local')->delete([$image->path, $image->thumb_path]);
        $image->delete();

        return back()->with('ok', 'Картинка удалена');
    }

    private function attachImages(News $news, array $files): void
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
                'sort_order' => $start + $i,
            ]);
        }
    }
}