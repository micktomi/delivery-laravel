<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1c1917">
    <title>Kitchen Board</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{-- overflow-hidden only from the tablet up: the board pins itself to the
     viewport there, but a phone has to scroll the page normally. --}}
<body class="bg-stone-100 antialiased md:overflow-hidden">
    {{ $slot }}
    @livewireScripts
</body>
</html>
