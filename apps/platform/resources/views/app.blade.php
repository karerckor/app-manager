<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script>
            // Runs before first paint: reading the stored theme here is what stops
            // the light palette flashing in front of a dark-theme user.
            (function () {
                try {
                    var stored = localStorage.getItem('app-manager-theme');
                    var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (dark) document.documentElement.classList.add('dark');
                    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                } catch (e) {
                    // Private mode can throw on localStorage. Light is the safe default.
                }
            })();
        </script>
        <title inertia>{{ config('app.name', 'App Manager') }}</title>
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
