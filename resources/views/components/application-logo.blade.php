@props(['class' => ''])

<img src="{{ asset('images/uti1.png') }}" alt="Логотип" {{ $attributes->merge(['class' => $class . ' object-contain']) }}>