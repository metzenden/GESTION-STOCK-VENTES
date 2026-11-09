@extends('pdf.layout')

@section('content')
    <h1>Feuille d'inventaire {{ $inventory['number'] }}</h1>
    <p>
        {{ $company?->tradeName }} — dépôt <strong>{{ $warehouse->name }}</strong> ({{ $warehouse->code }})<br>
        Figé le : {{ $inventory['snapshot_at'] ? \Carbon\CarbonImmutable::parse($inventory['snapshot_at'])->setTimezone($company?->timezone ?? 'Africa/Dakar')->format('d/m/Y H:i') : 'non démarré' }}
    </p>
    <table>
        <thead>
            <tr><th>Référence</th><th>Article</th><th>SKU</th><th>Lot</th><th style="width: 22%">Quantité comptée</th></tr>
        </thead>
        <tbody>
        @foreach ($inventory['lines'] ?? [] as $line)
            <tr>
                <td>{{ $line['variant']['reference'] ?? '' }}</td>
                <td>{{ $line['variant']['product_name'] ?? '' }} @if(($line['variant']['label'] ?? '') !== ($line['variant']['product_name'] ?? '')) — {{ $line['variant']['label'] }} @endif</td>
                <td>{{ $line['variant']['sku'] ?? '' }}</td>
                <td>{{ $line['lot_number'] ?? '—' }}</td>
                <td></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p style="margin-top: 24px">Compté par : ______________________ &nbsp;&nbsp; Date : ____ / ____ / ________ &nbsp;&nbsp; Signature : ______________</p>
@endsection
