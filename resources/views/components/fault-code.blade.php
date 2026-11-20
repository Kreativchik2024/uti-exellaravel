{{-- Код(ы) неисправности с подсказкой-расшифровкой по наведению. Несколько кодов («A; B») — отдельными метками.
     Атрибуты (class, style) — как у обычного span. --}}
@props(['code' => null])
@php $codes = \App\Services\FaultCodes::split($code); $descs = app(\App\Services\FaultCodeDescriptions::class); @endphp
@foreach($codes as $c)
    @php $desc = $descs->describe($c); @endphp
    <span {{ $attributes->merge(['class' => 'ui-tip font-mono text-xs font-semibold px-1.5 py-0.5 rounded-md']) }}
          @if($desc) data-tip="{{ $desc }}" tabindex="0" @endif>{{ $c }}</span>
@endforeach
