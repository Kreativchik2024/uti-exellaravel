@props(['class' => ''])

{{-- Логотип: WebP с PNG-фолбэком и растровым srcset (1x/2x), ~9 КБ вместо 1,5 МБ --}}
<picture>
    <source type="image/webp"
            srcset="{{ asset('images/uti1-128.webp') }} 1x, {{ asset('images/uti1-256.webp') }} 2x">
    <img src="{{ asset('images/uti1-128.png') }}"
         srcset="{{ asset('images/uti1-128.png') }} 1x, {{ asset('images/uti1-256.png') }} 2x"
         width="128" height="128"
         alt="Логотип"
         fetchpriority="high" decoding="async"
         {{ $attributes->merge(['class' => $class . ' object-contain']) }}>
</picture>
