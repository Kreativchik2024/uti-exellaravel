@props([
    'title'    => null,
    'subtitle' => null,
])

<section {{ $attributes->class(['bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6']) }}>
    @if($title || $subtitle)
        <header class="mb-4">
            @if($title)
                <h2 class="text-lg font-semibold text-gray-900">{{ $title }}</h2>
            @endif
            @if($subtitle)
                <p class="text-sm text-gray-500 mt-1">{{ $subtitle }}</p>
            @endif
        </header>
    @endif

    <div class="space-y-4">
        {{ $slot }}
    </div>
</section>