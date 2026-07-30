<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Board</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{-- overflow-hidden only from the tablet up: the board pins itself to the
     viewport there, but a phone has to scroll the page normally. --}}
<body class="bg-gray-100 md:overflow-hidden">
    {{ $slot }}
    @livewireScripts
</body>
</html>
