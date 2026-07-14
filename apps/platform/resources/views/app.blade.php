<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'App Manager') }}</title>
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body class="bg-slate-950 text-slate-100 antialiased">
        @inertia
    </body>
</html>
