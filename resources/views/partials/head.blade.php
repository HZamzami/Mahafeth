<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? 'Mahafeth' }}</title>

<link rel="icon" href="/favicon.svg" type="image/svg+xml" />
<link rel="icon" href="/favicon.ico" sizes="any" />

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
@if (app()->getLocale() === 'ar')
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans-arabic:400,500,600,700" rel="stylesheet" />
    <style>
        body { font-family: 'IBM Plex Sans Arabic', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; }
    </style>
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
