<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Статистика</h2>
            <a href="{{ route('stats.export', ['period' => $period, 'user_id' => request('user_id'), 'mode' => request('mode')]) }}"
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow transition">
                Скачать Excel
            </a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Блок фильтров --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-4">
            <div class="flex flex-wrap items-end gap-4">

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Период</label>
                    <div class="flex flex-wrap gap-1">
                        @foreach([1 => '1 день', 7 => '7 дней', 30 => '30 дней', 0 => 'Всё время'] as $p => $label)
                            <a href="{{ route('stats.index', ['period' => $p, 'user_id' => request('user_id'), 'mode' => request('mode')]) }}"
                               class="px-3 py-1 text-sm rounded border transition {{ $period === $p ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>

                @if(auth()->user()->isExecutor())
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Вид статистики</label>
                        <div style="display: flex; gap: 4px;">
                            <a href="{{ route('stats.index', ['period' => $period, 'mode' => 'my']) }}"
                               style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 32px; font-size: 14px; border-radius: 4px; border: 1px solid {{ request('mode') !== 'all' ? '#4f46e5' : '#d1d5db' }}; background-color: {{ request('mode') !== 'all' ? '#4f46e5' : '#ffffff' }}; color: {{ request('mode') !== 'all' ? '#ffffff' : '#374151' }}; text-decoration: none; font-weight: 500; box-sizing: border-box;">
                                Моя
                            </a>
                            <a href="{{ route('stats.index', ['period' => $period, 'mode' => 'all']) }}"
                               style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 32px; font-size: 14px; border-radius: 4px; border: 1px solid {{ request('mode') === 'all' ? '#4f46e5' : '#d1d5db' }}; background-color: {{ request('mode') === 'all' ? '#4f46e5' : '#ffffff' }}; color: {{ request('mode') === 'all' ? '#ffffff' : '#374151' }}; text-decoration: none; font-weight: 500; box-sizing: border-box;">
                                Общая
                            </a>
                        </div>
                    </div>
                @endif

                @if(auth()->user()->isAdmin() || auth()->user()->isMaster())
                    <form method="GET" action="{{ route('stats.index') }}" class="flex items-end gap-2">
                        <input type="hidden" name="period" value="{{ $period }}">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Сотрудник (автор)</label>
                            <select name="user_id" onchange="this.form.submit()" class="border rounded px-3 py-1 text-sm bg-white min-w-[200px]">
                                <option value="">Все авторы</option>
                                @foreach($usersList as $u)
                                    <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                @endif

            </div>
        </div>

        <div class="text-sm text-gray-600">
            @if($period === 0)
                Период: <strong>всё время</strong>
            @else
                Период: с <strong>{{ $from->format('d.m.Y') }}</strong> по <strong>{{ now()->format('d.m.Y') }}</strong>
            @endif
            @if($targetUser && $targetUser->isExecutor())
                <span class="ml-2 text-amber-700">(показаны записи: {{ $targetUser->name }})</span>
            @elseif(request('mode') === 'all' || !$targetUser)
                <span class="ml-2 text-amber-700">(общая статистика по всем)</span>
            @endif
        </div>

        {{-- СВОДКА ПО ВКЛАДКАМ --}}
        <div>
            <h3 class="font-semibold text-lg mb-4 text-gray-800">Сводка по вкладкам</h3>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;">
                @foreach($perSheet as $slug => $data)
                    <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition border border-gray-200 p-6 flex flex-col items-center justify-center text-center">
                        <div class="text-sm font-medium text-gray-500 mb-3 truncate w-full" title="{{ $data['title'] }}">
                            {{ $data['title'] }}
                        </div>
                        <div class="flex flex-col items-center justify-center">
                            <span class="text-5xl font-extrabold {{ $data['total'] > 0 ? 'text-blue-600' : 'text-gray-300' }} leading-none mb-1">
                                {{ $data['total'] }}
                            </span>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">
                                {{ $data['unit'] === 'записей' ? 'штук' : $data['unit'] }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- СВОДКА ПО ИСПОЛНИТЕЛЮ --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <h3 class="font-semibold text-lg mb-3 text-gray-800">Сводка по исполнителю</h3>

            <form method="GET" action="{{ route('stats.index') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="period" value="{{ $period }}">
                @if(request('user_id'))
                    <input type="hidden" name="user_id" value="{{ request('user_id') }}">
                @endif
                @if(request('mode'))
                    <input type="hidden" name="mode" value="{{ request('mode') }}">
                @endif

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Исполнитель</label>
                    <select name="worker" class="border rounded px-3 py-1 text-sm bg-white min-w-[220px]">
                        <option value="">— выберите —</option>
                        @foreach($workersList as $w)
                            <option value="{{ $w }}" @selected($worker === $w)>{{ $w }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Дата с</label>
                    <input type="date" name="worker_date_from" value="{{ request('worker_date_from') }}"
                           class="border rounded px-3 py-1 text-sm">
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Дата по</label>
                    <input type="date" name="worker_date_to" value="{{ request('worker_date_to') }}"
                           class="border rounded px-3 py-1 text-sm">
                </div>

                <div class="flex gap-2">
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1 text-sm rounded">Показать</button>
                    @if($worker || request('worker_date_from') || request('worker_date_to'))
                        <a href="{{ route('stats.index', array_filter([
                                'period' => $period,
                                'user_id' => request('user_id'),
                                'mode' => request('mode'),
                            ])) }}"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-1 text-sm rounded">Сброс</a>
                    @endif
                </div>
            </form>

            <p class="text-xs text-gray-400 mt-2">
                Только «Дата с» — поиск за этот день. Обе даты — период. Ничего не указано — берётся общий период сверху.
            </p>

            @if($worker !== '')
                <div class="mt-4">
                    <p class="text-sm text-gray-600 mb-2">
                        <strong>{{ $worker }}</strong>
                        <span class="text-gray-400">
                            @if(request('worker_date_from') && request('worker_date_to'))
                                · {{ \Carbon\Carbon::parse(request('worker_date_from'))->format('d.m.Y') }} — {{ \Carbon\Carbon::parse(request('worker_date_to'))->format('d.m.Y') }}
                            @elseif(request('worker_date_from'))
                                · {{ \Carbon\Carbon::parse(request('worker_date_from'))->format('d.m.Y') }}
                            @elseif(request('worker_date_to'))
                                · до {{ \Carbon\Carbon::parse(request('worker_date_to'))->format('d.m.Y') }}
                            @endif
                        </span>
                    </p>

                    @if(empty($workerSummary))
                        <p class="text-gray-500 text-sm">Ничего не найдено за выбранный период.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="p-3 text-left font-semibold rounded-tl">Категория</th>
                                        <th class="p-3 text-right font-semibold rounded-tr">Сделано</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($workerSummary as $slug => $data)
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3 text-gray-800">{{ $data['title'] }}</td>
                                            <td class="p-3 text-right">
                                                <span class="text-blue-700 font-bold">{{ $data['total'] }}</span>
                                                <span class="text-xs text-gray-400 ml-1">{{ $data['unit'] }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Детализация по постам (ПГП) --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <h3 class="font-semibold text-lg mb-3 text-gray-800">ПГП: Детализация по постам</h3>
            @if(empty($byPosts))
                <p class="text-gray-500 text-sm">Нет данных за период.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="p-3 text-left font-semibold rounded-tl">Пост</th>
                                <th class="p-3 text-left font-semibold">Всего бортов</th>
                                <th class="p-3 text-left font-semibold rounded-tr">Сдано</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($byPosts as $post => $data)
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 font-semibold text-gray-800">{{ $post }}</td>
                                    <td class="p-3 text-blue-700 font-bold">{{ $data['total'] }}</td>
                                    <td class="p-3 text-green-700 font-bold">{{ $data['delivered'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- График --}}
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <h3 class="font-semibold text-lg mb-4 text-gray-800">График по вкладкам</h3>
            <div class="relative h-64 w-full">
                <canvas id="statsChart"></canvas>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const ctx = document.getElementById('statsChart').getContext('2d');
            const labels = @json(collect($perSheet)->pluck('title'));
            const data = @json(collect($perSheet)->pluck('total'));

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Количество',
                        data: data,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f3f4f6' } },
                        x: { grid: { display: false } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        });
    </script>
</x-app-layout>