<?php if (isset($component)) { $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54 = $attributes; } ?>
<?php $component = App\View\Components\AppLayout::resolve([] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('app-layout'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\App\View\Components\AppLayout::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
     <?php $__env->slot('header', null, []); ?> 
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Статистика</h2>
            <a href="<?php echo e(route('stats.export', ['period' => $period, 'user_id' => request('user_id'), 'mode' => request('mode')])); ?>"
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow transition">
                Скачать Excel
            </a>
        </div>
     <?php $__env->endSlot(); ?>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        
        <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-4">
            <div class="flex flex-wrap items-end gap-4">
                
                
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Период</label>
                    <div class="flex gap-1">
                        <?php $__currentLoopData = [1 => '1 день', 7 => '7 дней', 30 => '30 дней']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <a href="<?php echo e(route('stats.index', ['period' => $p, 'user_id' => request('user_id'), 'mode' => request('mode')])); ?>"
                               class="px-3 py-1 text-sm rounded border transition <?php echo e($period === $p ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'); ?>">
                                <?php echo e($label); ?>

                            </a>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                </div>

                
                         
                <?php if(auth()->user()->isExecutor()): ?>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Вид статистики</label>
                        <div style="display: flex; gap: 4px;">
                            <a href="<?php echo e(route('stats.index', ['period' => $period, 'mode' => 'my'])); ?>"
                               style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 32px; font-size: 14px; border-radius: 4px; border: 1px solid <?php echo e(request('mode') !== 'all' ? '#4f46e5' : '#d1d5db'); ?>; background-color: <?php echo e(request('mode') !== 'all' ? '#4f46e5' : '#ffffff'); ?>; color: <?php echo e(request('mode') !== 'all' ? '#ffffff' : '#374151'); ?>; text-decoration: none; font-weight: 500; box-sizing: border-box;">
                                Моя
                            </a>
                            <a href="<?php echo e(route('stats.index', ['period' => $period, 'mode' => 'all'])); ?>"
                               style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 32px; font-size: 14px; border-radius: 4px; border: 1px solid <?php echo e(request('mode') === 'all' ? '#4f46e5' : '#d1d5db'); ?>; background-color: <?php echo e(request('mode') === 'all' ? '#4f46e5' : '#ffffff'); ?>; color: <?php echo e(request('mode') === 'all' ? '#ffffff' : '#374151'); ?>; text-decoration: none; font-weight: 500; box-sizing: border-box;">
                                Общая
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                
                <?php if(auth()->user()->isAdmin() || auth()->user()->isMaster()): ?>
                    <form method="GET" action="<?php echo e(route('stats.index')); ?>" class="flex items-end gap-2">
                        <input type="hidden" name="period" value="<?php echo e($period); ?>">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Сотрудник</label>
                            <select name="user_id" onchange="this.form.submit()" class="border rounded px-3 py-1 text-sm bg-white min-w-[200px]">
                                <option value="">Общая статистика</option>
                                <?php $__currentLoopData = $usersList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option value="<?php echo e($u->id); ?>" <?php if(request('user_id') == $u->id): echo 'selected'; endif; ?>><?php echo e($u->name); ?></option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </select>
                        </div>
                    </form>
                <?php endif; ?>
                
            </div>
        </div>

        <div class="text-sm text-gray-600">
            Период: с <strong><?php echo e($from->format('d.m.Y')); ?></strong> по <strong><?php echo e(now()->format('d.m.Y')); ?></strong>
            <?php if($targetUser && $targetUser->isExecutor()): ?>
                <span class="ml-2 text-amber-700">(показаны записи: <?php echo e($targetUser->name); ?>)</span>
            <?php elseif(request('mode') === 'all' || !$targetUser): ?>
                <span class="ml-2 text-amber-700">(общая статистика по всем)</span>
            <?php endif; ?>
        </div>

        
        <div>
            <h3 class="font-semibold text-lg mb-4 text-gray-800">Сводка по вкладкам</h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;">
                <?php $__currentLoopData = $perSheet; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slug => $data): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition border border-gray-200 p-6 flex flex-col items-center justify-center text-center">
                        <div class="text-sm font-medium text-gray-500 mb-3 truncate w-full" title="<?php echo e($data['title']); ?>">
                            <?php echo e($data['title']); ?>

                        </div>
                        <div class="flex flex-col items-center justify-center">
                            <span class="text-5xl font-extrabold <?php echo e($data['total'] > 0 ? 'text-blue-600' : 'text-gray-300'); ?> leading-none mb-1">
                                <?php echo e($data['total']); ?>

                            </span>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">
                                <?php echo e($data['unit'] === 'записей' ? 'штук' : $data['unit']); ?>

                            </span>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>

        
        <?php if(! empty($totals['perUser']) && (!auth()->user()->isExecutor() || request('mode') === 'all')): ?>
            <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
                <h3 class="font-semibold text-lg mb-3 text-gray-800">Всего по исполнителям</h3>
                <div class="flex flex-wrap gap-2">
                    <?php $__currentLoopData = $totals['perUser']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $name => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <span class="bg-indigo-50 text-indigo-700 border border-indigo-100 px-3 py-1 rounded-full text-sm font-medium">
                            <?php echo e($name); ?>: <strong><?php echo e($count); ?></strong>
                        </span>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            </div>
        <?php endif; ?>

        
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            <h3 class="font-semibold text-lg mb-3 text-gray-800">ПГП: Детализация по постам</h3>
            <?php if(empty($byPosts)): ?>
                <p class="text-gray-500 text-sm">Нет данных за период.</p>
            <?php else: ?>
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
                            <?php $__currentLoopData = $byPosts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $post => $data): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3 font-semibold text-gray-800"><?php echo e($post); ?></td>
                                    <td class="p-3 text-blue-700 font-bold"><?php echo e($data['total']); ?></td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap gap-1">
                                            <?php $__currentLoopData = $data['users']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $worker => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                <span class="inline-block bg-gray-100 text-gray-700 px-2 py-0.5 rounded text-xs">
                                                    <?php echo e($worker); ?>: <?php echo e($count); ?>

                                                </span>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        
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
            const labels = <?php echo json_encode(collect($perSheet)->pluck('title'), 15, 512) ?>;
            const data = <?php echo json_encode(collect($perSheet)->pluck('total'), 15, 512) ?>;

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
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $attributes = $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $component = $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?><?php /**PATH C:\OSPanel\home\exellaravel\resources\views/stats/index.blade.php ENDPATH**/ ?>