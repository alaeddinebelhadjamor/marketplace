<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Relevé {{ $statement->period }} — {{ $seller->shop_title }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .header { border-bottom: 3px solid #c70a0a; padding-bottom: 10px; margin-bottom: 18px; }
        .brand { font-size: 20px; font-weight: bold; color: #c70a0a; }
        .muted { color: #6b7280; }
        h1 { font-size: 16px; margin: 4px 0 0; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .lines th { background: #f3f4f6; text-align: left; padding: 6px; border-bottom: 1px solid #d1d5db; font-size: 10px; }
        .lines td { padding: 5px 6px; border-bottom: 1px solid #eef0f3; font-size: 10px; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 16px; width: 55%; margin-left: 45%; }
        .totals td { padding: 5px 6px; }
        .totals .net td { font-weight: bold; font-size: 13px; border-top: 2px solid #1f2937; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; }
        .paid { background: #dcfce7; color: #166534; }
        .pending { background: #fef3c7; color: #92400e; }
        .footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
<div class="header">
    <div class="brand">Mytek Marketplace</div>
    <h1>Relevé de paiement vendeur — {{ $periodLabel }}</h1>
</div>

<table class="meta">
    <tr>
        <td style="width:55%">
            <strong>{{ $seller->shop_title }}</strong><br>
            {{ $seller->firstname }} {{ $seller->lastname }}<br>
            {{ $seller->email }}<br>
            @if($seller->address){{ $seller->address }}, {{ $seller->zipcode }} {{ $seller->governorate }}<br>@endif
            @if($seller->tax_id)Matricule fiscal : {{ $seller->tax_id }}@endif
        </td>
        <td>
            Relevé n° <strong>{{ $statement->id }}</strong><br>
            Période : {{ $statement->period }}<br>
            Généré le : {{ now()->format('d/m/Y H:i') }}<br>
            Statut :
            <span class="badge {{ $statement->status === 'paid' ? 'paid' : 'pending' }}">
                {{ $statement->status === 'paid' ? 'Payé le '.$statement->paid_at?->format('d/m/Y') : 'En attente de paiement' }}
            </span>
        </td>
    </tr>
</table>

<h3 style="margin-top:20px">Détail des ventes</h3>
<table class="lines">
    <thead>
    <tr><th>Date</th><th>Commande</th><th>SKU</th><th>Produit</th><th class="num">Qté</th><th class="num">Prix unit.</th><th class="num">Total</th></tr>
    </thead>
    <tbody>
    @forelse($orders as $o)
        <tr>
            <td>{{ $o->effectiveDate()?->format('d/m/Y') }}</td>
            <td>{{ $o->order_id }}</td>
            <td>{{ $o->sku }}</td>
            <td>{{ \Illuminate\Support\Str::limit($o->product_name, 45) }}</td>
            <td class="num">{{ $o->qty }}</td>
            <td class="num">{{ number_format((float) $o->price, 2, ',', ' ') }} DT</td>
            <td class="num">{{ number_format($o->lineTotal(), 2, ',', ' ') }} DT</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Aucune vente sur la période.</td></tr>
    @endforelse
    </tbody>
</table>

<table class="totals">
    <tr><td>Commandes</td><td class="num">{{ $statement->orders_count }}</td></tr>
    <tr><td>Articles vendus</td><td class="num">{{ $statement->items_qty }}</td></tr>
    <tr><td>Chiffre d'affaires brut</td><td class="num">{{ number_format($statement->gross, 2, ',', ' ') }} DT</td></tr>
    <tr><td>Commission Mytek ({{ rtrim(rtrim(number_format($statement->commission_rate * 100, 2, ',', ''), '0'), ',') }} %)</td><td class="num">− {{ number_format($statement->commission, 2, ',', ' ') }} DT</td></tr>
    <tr class="net"><td>Net à verser</td><td class="num">{{ number_format($statement->net, 2, ',', ' ') }} DT</td></tr>
</table>

<div class="footer">Mytek Marketplace — document généré automatiquement, les montants sont exprimés en dinars tunisiens (DT).</div>
</body>
</html>
