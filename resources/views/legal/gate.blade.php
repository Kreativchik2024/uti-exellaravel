{{-- Отдаётся вместо любой страницы, пока нет согласия с правилами (App\Http\Middleware\EnsureConsent) --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}" data-refresh-url="{{ route('csrf.refresh') }}">
    <script src="{{ asset('js/csrf.js') }}?v={{ filemtime(public_path('js/csrf.js')) }}"></script>
    <script src="{{ asset('js/theme.js') }}?v={{ filemtime(public_path('js/theme.js')) }}"></script>
    <title>{{ config('app.name', 'УТИ') }} — согласие</title>
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/dark-theme.css') }}?v={{ filemtime(public_path('css/dark-theme.css')) }}">
</head>
<body class="font-sans antialiased ui-legal-page">
    @include('partials.consent')
</body>
</html>
