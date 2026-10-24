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
            <h2 class="font-semibold text-xl text-gray-800">Главная</h2>
            <?php if(auth()->user()->canManageContent()): ?>
                <a href="<?php echo e(route('news.create')); ?>" class="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 transition">
                    + Новость
                </a>
            <?php endif; ?>
        </div>
     <?php $__env->endSlot(); ?>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
        
        <?php if(session('ok')): ?>
            <div class="bg-green-100 text-green-800 p-3 rounded mb-6"><?php echo e(session('ok')); ?></div>
        <?php endif; ?>

        
        <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start;">
            
            
            <div style="flex: 1 1 600px; min-width: 0; display: flex; flex-direction: column; gap: 1rem;">
                <div id="news-feed" class="space-y-4">
                    <?php echo $__env->make('news._items', ['news' => $news], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                </div>

                <div id="news-sentinel" class="py-4 text-center text-sm text-gray-400"
                     data-has-more="<?php echo e($news->hasMorePages() ? '1' : '0'); ?>">
                    <?php if($news->hasMorePages()): ?>
                        Загрузка...
                    <?php endif; ?>
                </div>
            </div>

            
            <div style="flex: 0 0 280px; width: 100%; max-width: 280px; position: sticky; top: 1rem;">
                <div class="bg-white rounded-lg shadow border border-gray-100 overflow-hidden">
                    
                    
                    <div class="p-3 bg-gray-50 border-b border-gray-100">
                        <div class="flex gap-1">
                            <?php $__currentLoopData = [1 => '1 день', 7 => '7 дней', 30 => '30 дней']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <a href="<?php echo e(route('dashboard', ['stats_period' => $p])); ?>"
                                   style="flex: 1; text-align: center; padding: 4px 0; font-size: 12px; border-radius: 4px; border: 1px solid <?php echo e(($statsPeriod ?? 30) === $p ? '#4f46e5' : '#d1d5db'); ?>; background-color: <?php echo e(($statsPeriod ?? 30) === $p ? '#4f46e5' : '#ffffff'); ?>; color: <?php echo e(($statsPeriod ?? 30) === $p ? '#ffffff' : '#374151'); ?>; text-decoration: none; font-weight: 500;">
                                    <?php echo e($label); ?>

                                </a>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </div>
                    </div>

                    
                    <div class="p-2">
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px;">
                            <?php $__currentLoopData = $perSheet; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slug => $data): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="bg-gray-50 rounded border border-gray-200 p-2 flex flex-col items-center justify-center text-center hover:bg-white hover:shadow-sm transition">
                                    <div class="text-[11px] font-medium text-gray-600 mb-1 truncate w-full leading-tight" title="<?php echo e($data['title']); ?>">
                                        <?php echo e($data['title']); ?>

                                    </div>
                                    <div class="flex flex-col items-center justify-center">
                                        <span class="text-lg font-bold <?php echo e($data['total'] > 0 ? 'text-blue-600' : 'text-gray-300'); ?> leading-none">
                                            <?php echo e($data['total']); ?>

                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    
    <script>
        (function () {
            const feed     = document.getElementById('news-feed');
            const sentinel = document.getElementById('news-sentinel');
            if (!feed || !sentinel) return;

            let loading = false;
            let page    = 2;
            let hasMore = sentinel.dataset.hasMore === '1';

            async function loadNext() {
                if (loading || !hasMore) return;
                loading = true;
                try {
                    const statsPeriod = new URLSearchParams(window.location.search).get('stats_period') || '30';
                    const res = await fetch(`<?php echo e(url('/')); ?>?page=${page}&json=1&stats_period=${statsPeriod}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    if (data.html) feed.insertAdjacentHTML('beforeend', data.html);
                    hasMore = data.has_more;
                    page++;
                    if (!hasMore) {
                        sentinel.textContent = '— больше новостей нет —';
                        sentinel.dataset.hasMore = '0';
                    }
                } catch (e) {
                    sentinel.textContent = 'Ошибка загрузки: ' + e.message;
                } finally {
                    loading = false;
                }
            }

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) loadNext();
                });
            }, { rootMargin: '300px' });

            observer.observe(sentinel);
        })();
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
<?php endif; ?><?php /**PATH C:\OSPanel\home\exellaravel\resources\views/dashboard.blade.php ENDPATH**/ ?>