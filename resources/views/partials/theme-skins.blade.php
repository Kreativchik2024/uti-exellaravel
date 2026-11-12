{{-- Оформленные темы (выбор — в переключателе тем; режимы — public/js/theme.js).
     Правила срабатывают только при html[data-ui-theme="…"], остальным темам не мешают.
     Фоновые картинки темы — все файлы из public/img/themes/<тема>/ (jpg, png, webp),
     сменяются сами (public/js/skin-bg.js). Добавить картинку = положить файл в папку. --}}
@php
    $skinPools = [];
    foreach (['peaky', 'blueprint', 'minions'] as $skin) {
        $files = array_filter(glob(public_path("img/themes/$skin/*")) ?: [], fn ($f) => preg_match('/\.(jpe?g|png|webp)$/i', $f));
        natsort($files);
        $skinPools[$skin] = array_values(array_map(
            fn ($f) => asset("img/themes/$skin/" . rawurlencode(basename($f))) . '?v=' . filemtime($f),
            $files
        ));
    }
@endphp
@foreach (['base', 'peaky', 'blueprint', 'minions'] as $skin)
<link rel="stylesheet" href="{{ asset("css/themes/$skin.css") }}?v={{ filemtime(public_path("css/themes/$skin.css")) }}">
@endforeach
<script>window.UI_SKIN_POOLS = @json($skinPools);</script>
<script src="{{ asset('js/skin-bg.js') }}?v={{ filemtime(public_path('js/skin-bg.js')) }}" defer></script>
