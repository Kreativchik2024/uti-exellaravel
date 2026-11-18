{{-- Статус исследования цветной меткой: не обработан — красный, в работе — серый, в разбор — жёлтый, в конвейер — зелёный --}}
@props(['value' => null])
@php $s = \App\Models\ResearchRecord::STATUSES[$value ?: 'new'] ?? \App\Models\ResearchRecord::STATUSES['new']; @endphp
<x-status-pill :value="$s['label']" :tone="$s['tone']" {{ $attributes }} />
