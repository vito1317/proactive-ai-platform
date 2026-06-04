<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'PAI 主動式 AI 平台') }}</title>

    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
    @inertia
</body>
</html>
