<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @inertiaHead
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="bg-[--color-surface] text-[--color-text-secondary] antialiased">
        @inertia
    </body>
</html>
