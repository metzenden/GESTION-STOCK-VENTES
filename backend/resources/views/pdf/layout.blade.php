<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10pt; color: #1f2933; margin: 0; }
        h1 { font-size: 16pt; margin: 0 0 4mm; }
        .header { display: flex; justify-content: space-between; border-bottom: 1px solid #cbd2d9; padding-bottom: 4mm; margin-bottom: 6mm; }
        .company { font-weight: bold; font-size: 12pt; }
        .muted { color: #616e7c; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2mm; border-bottom: 1px solid #e4e7eb; text-align: left; vertical-align: top; }
        th { background: #f5f7fa; font-weight: bold; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 6mm; width: 45%; margin-left: auto; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8pt; color: #616e7c; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <div class="company">{{ $company['trade_name'] ?? '' }}</div>
            <div class="muted">{{ $company['address'] ?? '' }}</div>
            <div class="muted">{{ $company['phone'] ?? '' }} {{ $company['email'] ?? '' }}</div>
        </div>
        <div>@yield('document-meta')</div>
    </div>

    @yield('content')

    <div class="footer">@yield('footer')</div>
</body>
</html>
