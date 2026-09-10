<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $storeSettings->displayName() }}</title>

    <link rel="manifest" href="{{ route('manifest') }}">
    <meta name="theme-color" content="{{ $storeSettings->brandPrimary() }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $storeSettings->displayName() }}">

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style data-store-branding>
        :root {
            --brand-primary: {{ $storeSettings->brandPrimary() }};
            --brand-accent: {{ $storeSettings->brandAccent() }};
        }
    </style>
    @livewireStyles
</head>
<body class="bg-stone-50 text-stone-950 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
