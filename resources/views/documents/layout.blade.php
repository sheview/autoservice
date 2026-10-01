{{-- Base of every PDF document (rendered by Gotenberg/Chromium, A4, margins set by the Gotenberg client). --}}
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    {{-- Thai font from Google Fonts; Gotenberg's own Noto fonts are the fallback when it has no internet. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', 'Noto Sans Thai', 'Noto Sans', sans-serif; font-size: 10.5pt; line-height: 1.45; color: #000; margin: 0; }
        h1, h2, h3 { margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 8px; }
        .company { font-size: 14pt; font-weight: 700; }
        .doc-title { font-size: 12pt; font-weight: 600; }
        .doc-no { font-family: monospace; font-size: 13pt; font-weight: 700; text-align: right; }
        .muted { color: #555; }
        .facts { display: grid; grid-template-columns: 1fr 1fr; column-gap: 28px; row-gap: 5px; margin-top: 12px; }
        .fact { display: flex; gap: 8px; }
        .fact .label { width: 95px; flex-shrink: 0; color: #555; }
        .fact .value { flex: 1; border-bottom: 1px dotted #999; min-height: 1.45em; }
        .times { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 10px; font-size: 9.5pt; }
        .box { border: 1px solid #999; padding: 4px 8px; }
        section { margin-top: 14px; }
        section h2 { font-size: 10.5pt; font-weight: 600; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; vertical-align: top; text-align: left; }
        th { font-weight: 600; background: #f3f3f3; }
        .num { text-align: right; }
        .center { text-align: center; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-top: 36px; text-align: center; page-break-inside: avoid; }
        .sign-line { border-bottom: 1px solid #000; height: 40px; margin: 0 24px; }
        .footer { margin-top: 20px; font-size: 8.5pt; color: #666; display: flex; justify-content: space-between; }
        .pre { white-space: pre-line; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    @yield('content')

    <div class="footer">
        <span>{{ __('document.page_note') }}</span>
        <span>{{ __('document.printed_at', ['at' => \App\Modules\Document\Support\ThaiDate::format(now(), true)]) }}</span>
    </div>
</body>
</html>
