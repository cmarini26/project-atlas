<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap">
        @inertiaHead
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="bg-[var(--color-surface)] text-[var(--color-text-secondary)] antialiased">
        @inertia
    </body>
</html>
