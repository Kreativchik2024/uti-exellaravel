{{-- Код неисправности с подсказкой-расшифровкой по наведению. Атрибуты (class, style) — как у обычного span. --}}
@props(['code' => null])
@php $desc = app(\App\Services\FaultCodeDescriptions::class)->describe($code); @endphp
<span {{ $attributes->merge(['class' => 'ui-tip font-mono text-xs font-semibold px-1.5 py-0.5 rounded-md']) }}
      @if($desc) data-tip="{{ $desc }}" tabindex="0" @endif>{{ $code }}</span>
