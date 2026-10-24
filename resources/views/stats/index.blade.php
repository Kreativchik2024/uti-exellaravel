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
                
                {{-- Период (ссылки) --}}
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Период</label>
                    <div class="flex gap-1">
                        @foreach([1 => '1 день', 7 => '7 дней', 30 => '30 дней'] as $p => $label)
                            <a href="{{ route('stats.index', ['period' => $p, 'user_id' => request('user_id'), 'mode' => request('mode')]) }}"
                               class="px-3 py-1 text-sm rounded border transition {{ $period === $p ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>

                {{-- Переключатель для исполнителя (Моя / Общая) --}}
                         {{-- Переключатель для исполнителя (Моя / Общая) --}}
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

                {{-- Селект для админа/мастера --}}
                @if(auth()->user()->isAdmin() || auth()->user()->isMaster())
                    <form method="GET" action="{{ route('stats.index') }}" class="flex items-end gap-2">
                        <input type="hidden" name="period" value="{{ $period }}">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Сотрудник</label>
                            <select name="user_id" onchange="this.form.submit()" class="border rounded px-3 py-1 text-sm bg-white min-w-[200px]">
                                <option value="">Общая статистика</option>
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
            Период: с <strong>{{ $from->format('d.m.Y') }}</strong> по <strong>{{ now()->format('d.m.Y') }}</strong>
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

        {{-- ИСПОЛНИТЕЛИ (показываем только если это не "моя" статистика) --}}
        @if(! empty($totals['perUser']) && (!auth()->user()->isExecutor() || request('mode') === 'all'))
            <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
                <h3 class="font-semibold text-lg mb-3 text-gray-800">Всего по исполнителям</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach($totals['perUser'] as $name => $count)
                        <span class="bg-indigo-50 text-indigo-700 border border-indigo-100 px-3 py-1 rounded-full text-sm font-medium">
                            {{ $name }}: <strong>{{ $count }}</strong>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

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
                                <th class="p-3 text-left font-semibold rounded-tr">Исполнители (кол-во записей)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($byPosts as $post => $data)
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 font-semibold text-gray-800">{{ $post }}</td>
                                    <td class="p-3 text-blue-700 font-bold">{{ $data['total'] }}</td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($data['users'] as $worker => $count)
                                                <span class="inline-block bg-gray-100 text-gray-700 px-2 py-0.5 rounded text-xs">
                                                    {{ $worker }}: {{ $count }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
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