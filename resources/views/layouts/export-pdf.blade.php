<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <style>
        @page { margin: 18mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #ffffff;
            color: #111827;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 12px;
            line-height: 1.5;
        }
        main { max-width: 190mm; margin: 0 auto; }
        header { border-bottom: 1px solid #d1d5db; padding-bottom: 16px; margin-bottom: 20px; }
        section { break-inside: avoid; border-bottom: 1px solid #e5e7eb; padding: 16px 0; }
        h1 { margin: 0; font-size: 24px; line-height: 1.2; }
        h2 { margin: 0 0 10px; font-size: 15px; }
        h3 { margin: 0; font-size: 12px; }
        p { margin: 0; }
        a { color: #111827; text-decoration: none; }
        .muted { color: #4b5563; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .metric { border: 1px solid #e5e7eb; padding: 8px; }
        .item { padding: 8px 0; border-top: 1px solid #f3f4f6; }
        .item:first-child { border-top: 0; }
        .label { font-size: 10px; color: #6b7280; text-transform: uppercase; }
        .value { margin-top: 2px; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
