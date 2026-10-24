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
            <h2 class="font-semibold text-xl text-gray-800"><?php echo e($cfg['title']); ?></h2>
            <a href="<?php echo e(route('records.create', $sheet)); ?>" class="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 transition">
                + Добавить
            </a>
        </div>
     <?php $__env->endSlot(); ?>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <?php if(session('ok')): ?>
            <div class="bg-green-100 text-green-800 p-3 rounded"><?php echo e(session('ok')); ?></div>
        <?php endif; ?>

        
        <form method="GET" action="<?php echo e(route('records.index', $sheet)); ?>" class="bg-white p-3 rounded shadow flex gap-2 flex-wrap items-center">
            <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Поиск по S/N, ФИО, номеру борта, IP и т.д."
                   class="border rounded px-3 py-2 flex-1 min-w-[200px] text-sm">
            <button class="bg-gray-800 text-white px-4 py-2 rounded text-sm hover:bg-gray-700 transition">Найти</button>
            <?php if($q !== ''): ?>
                <a href="<?php echo e(route('records.index', $sheet)); ?>" class="text-sm text-gray-500 hover:text-gray-700 underline">Сбросить</a>
            <?php endif; ?>
        </form>

        
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                <tr>
                    <?php $__currentLoopData = $cfg['list_columns']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $col): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <th class="p-2 text-left"><?php echo e($cfg['fields'][$col]['label'] ?? $col); ?></th>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    <th class="p-2 text-left">Исполнители</th>
                    <th class="p-2 text-left">Автор</th>
                    <th class="p-2 text-left">Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $records; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rec): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr class="border-t">
                        <?php $__currentLoopData = $cfg['list_columns']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $col): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <td class="p-2">
                                <?php if(($cfg['fields'][$col]['type'] ?? '') === 'date' && $rec->$col): ?>
                                    <?php echo e(\Carbon\Carbon::parse($rec->$col)->format('d.m.Y')); ?>

                                <?php else: ?>
                                    <?php echo e($rec->$col); ?>

                                <?php endif; ?>
                            </td>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <td class="p-2 text-sm">
                            <?php echo e(collect([$rec->worker1,$rec->worker2,$rec->worker3,$rec->worker4,$rec->worker5])->filter()->join(', ')); ?>

                        </td>
                        <td class="p-2 text-xs text-gray-500"><?php echo e($rec->creator?->name ?? '—'); ?></td>
                        <td class="p-2 whitespace-nowrap space-x-2">
                            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $rec)): ?>
                                <a href="<?php echo e(route('records.edit', [$sheet, $rec->id])); ?>"
                                   class="text-blue-600 underline">Ред.</a>
                            <?php endif; ?>
                            <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('delete', $rec)): ?>
                                <form method="POST" action="<?php echo e(route('records.destroy', [$sheet, $rec->id])); ?>"
                                      class="inline" onsubmit="return confirm('Удалить запись?')">
                                    <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                                    <button class="text-red-600 underline">Удалить</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="100" class="p-4 text-center text-gray-500">
                        <?php if($q !== ''): ?>
                            По запросу «<?php echo e($q); ?>» ничего не найдено.
                        <?php else: ?>
                            Нет записей
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div><?php echo e($records->links()); ?></div>
    </div>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $attributes = $__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__attributesOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54)): ?>
<?php $component = $__componentOriginal9ac128a9029c0e4701924bd2d73d7f54; ?>
<?php unset($__componentOriginal9ac128a9029c0e4701924bd2d73d7f54); ?>
<?php endif; ?><?php /**PATH C:\OSPanel\home\exellaravel\resources\views/records/index.blade.php ENDPATH**/ ?>