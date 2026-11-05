<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=20261105">
        <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=20261105">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=20261105">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

        <script>
            try {
                const palette = localStorage.getItem('mcare-color-theme');
                document.documentElement.dataset.colorTheme = palette === 'medical' ? 'medical' : 'warm';
                const mode = localStorage.getItem('appearance');
                document.documentElement.classList.toggle('dark', mode === 'dark' || ((!mode || mode === 'system') && matchMedia('(prefers-color-scheme: dark)').matches));
            } catch (_) {}
        </script>
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
