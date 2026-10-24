<?php $__currentLoopData = $news; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <article class="bg-white p-4 rounded shadow <?php echo e($item->is_pinned ? 'ring-2 ring-blue-300' : ''); ?>">
        <header class="mb-2">
            <strong><?php echo e($item->author?->name ?? '—'); ?></strong>
            <time class="text-sm text-gray-500 ml-2"><?php echo e($item->published_at->diffForHumans()); ?></time>
            <?php if($item->is_pinned): ?>
                <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">закреплено</span>
            <?php endif; ?>
        </header>
        <?php if($item->title): ?><h3 class="font-semibold mb-1"><?php echo e($item->title); ?></h3><?php endif; ?>
        <p class="whitespace-pre-line"><?php echo e($item->body); ?></p>
        <?php if($item->images->count()): ?>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php $__currentLoopData = $item->images; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $img): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <a href="<?php echo e(route('media.show', ['path' => $img->path])); ?>" target="_blank">
                        <img src="<?php echo e(route('media.show', ['path' => $img->thumb_path])); ?>"
                             class="w-32 h-32 object-cover rounded border" loading="lazy">
                    </a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        <?php endif; ?>
    </article>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php /**PATH C:\OSPanel\home\exellaravel\resources\views/news/_items.blade.php ENDPATH**/ ?>