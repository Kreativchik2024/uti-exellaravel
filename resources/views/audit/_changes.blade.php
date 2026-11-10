{{-- Изменения одной записи журнала: поле — было → стало. Переменные: $log, $labels --}}
@if($log->changes)
    <dl class="ui-audit__changes">
        @foreach($log->changes as $field => $pair)
            @php
                [$old, $new] = array_pad((array) $pair, 2, null);
                $label = $labels[$log->subject_type][$field] ?? $field;
            @endphp
            <div>
                <dt>{{ $label }}</dt>
                <dd>
                    @if($log->action === 'deleted')
                        <span>{{ $old === null || $old === '' ? '—' : $old }}</span>
                    @elseif($log->action === 'created')
                        <span>{{ $new === null || $new === '' ? '—' : $new }}</span>
                    @else
                        <span class="ui-audit__old">{{ $old === null || $old === '' ? '—' : $old }}</span>
                        <span aria-hidden="true">→</span>
                        <span class="ui-audit__new">{{ $new === null || $new === '' ? '—' : $new }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif
