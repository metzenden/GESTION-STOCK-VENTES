{{-- Document commercial générique (facture, devis, avoir, bon de livraison, reçu). Les mentions affichées sont celles saisies par l'entreprise (VR-02). --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $number }}</title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #0f172a; margin: 24px; }
  header { display: flex; justify-content: space-between; margin-bottom: 20px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .muted { color: #475569; }
  .party { border: 1px solid #cbd5e1; padding: 8px 10px; border-radius: 4px; min-width: 220px; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th { text-align: left; background: #f1f5f9; padding: 6px; border-bottom: 1px solid #cbd5e1; }
  td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  .num { text-align: right; white-space: nowrap; }
  .totals { margin-left: auto; width: 45%; margin-top: 12px; }
  .totals td { border: none; padding: 3px 6px; }
  .totals tr.grand td { font-weight: bold; font-size: 13px; border-top: 1px solid #0f172a; }
  .notice { margin-top: 16px; padding: 8px; background: #fef3c7; }
  footer { margin-top: 28px; font-size: 9px; color: #475569; white-space: pre-line; }
</style>
</head>
<body>
<header>
  <div>
    <h1>{{ $company['trade_name'] ?? '' }}</h1>
    @if(!empty($company['legal_name']))<div>{{ $company['legal_name'] }}</div>@endif
    <div class="muted">{{ trim(($company['address'] ?? '').' '.($company['city'] ?? '')) }}</div>
    <div class="muted">{{ $company['phone'] ?? '' }} {{ $company['email'] ?? '' }}</div>
    @if(!empty($company['ninea']))<div class="muted">NINEA : {{ $company['ninea'] }}</div>@endif
    @if(!empty($company['rccm']))<div class="muted">RCCM : {{ $company['rccm'] }}</div>@endif
  </div>
  <div>
    <h1>{{ $title }}</h1>
    <div><strong>{{ $number }}</strong></div>
    @foreach($meta as $label => $value)<div class="muted">{{ $label }} : {{ $value }}</div>@endforeach
  </div>
</header>

@if(!empty($party))
<div class="party">
  <div class="muted">{{ $party['label'] }}</div>
  @foreach($party['lines'] as $line)@if($line !== null && $line !== '')<div>{{ $line }}</div>@endif @endforeach
</div>
@endif

<table>
  <thead><tr>@foreach($columns as $i => $col)<th class="{{ $i > 0 ? 'num' : '' }}">{{ $col }}</th>@endforeach</tr></thead>
  <tbody>
  @foreach($rows as $row)
    <tr>@foreach($row as $i => $cell)<td class="{{ $i > 0 ? 'num' : '' }}">{{ $cell }}</td>@endforeach</tr>
  @endforeach
  </tbody>
</table>

@if(!empty($totals))
<table class="totals">
  @foreach($totals as $label => $value)
    <tr class="{{ $loop->last ? 'grand' : '' }}"><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>
  @endforeach
</table>
@endif

@if(!empty($notice))<div class="notice">{{ $notice }}</div>@endif

<footer>@foreach($texts as $text)@if(!empty($text)){{ $text }}

@endif @endforeach</footer>
</body>
</html>
