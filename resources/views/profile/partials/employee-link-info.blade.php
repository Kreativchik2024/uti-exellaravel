<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Привязка к сотруднику') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Сотрудник, под которым вы фигурируете в записях. Привязку выполняет администратор.') }}
        </p>
    </header>

    <div class="mt-4 text-sm">
        @if(auth()->user()->employee)
            <p class="text-gray-800">
                {{ __('Вы привязаны к сотруднику:') }}
                <strong>{{ auth()->user()->employee->short_name }}</strong>
            </p>
        @else
            <p class="text-amber-700">
                {{ __('Не привязан ни к одному сотруднику. Обратитесь к администратору.') }}
            </p>
        @endif
    </div>
</section>