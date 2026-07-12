<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @inertiaHead
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="bg-[var(--color-surface)] text-[var(--color-text-secondary)] antialiased">
        @inertia
    </body>
</html>
