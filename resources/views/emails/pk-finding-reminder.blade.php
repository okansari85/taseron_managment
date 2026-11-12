@php
    $finding->loadMissing(['equipment.type:id,name', 'installation.type:id,name', 'location:id,name']);
    $subject = $finding->equipment
        ? trim(($finding->equipment->name ?: $finding->equipment->type?->name) . ' ' . ($finding->equipment->code ?? ''))
        : ($finding->installation?->name ?: $finding->installation?->type?->name);
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PKTakip Bulgu Takip</title>
</head>
<body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#111827;">
    <div style="max-width:620px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:36px;">
        <div style="font-size:24px;font-weight:800;margin-bottom:24px;"><span style="color:#ffc107;">PK</span>Takip</div>
        <h1 style="font-size:22px;margin:0 0 12px;">
            @if ($kind === 'gecti') Bulgunun termini geçti @else Bulgunun terminine {{ $days }} gün kaldı @endif
        </h1>
        <p style="font-size:15px;line-height:1.6;color:#64748b;">Sorumlusu olduğunuz bulgu:</p>
        <table style="width:100%;border-collapse:collapse;font-size:14px;line-height:1.5;">
            <tr><td style="padding:6px 0;color:#64748b;width:130px;vertical-align:top;">Lokasyon</td><td style="padding:6px 0;">{{ $finding->location?->name ?? '—' }}</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;vertical-align:top;">Ekipman / Tesisat</td><td style="padding:6px 0;">{{ $subject ?: '—' }}</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;vertical-align:top;">Bulgu</td><td style="padding:6px 0;">{{ $finding->text }}</td></tr>
            @if ($finding->work)
                <tr><td style="padding:6px 0;color:#64748b;vertical-align:top;">Yapılacak iş</td><td style="padding:6px 0;">{{ $finding->work }}</td></tr>
            @endif
            <tr><td style="padding:6px 0;color:#64748b;vertical-align:top;">Termin</td><td style="padding:6px 0;font-weight:700;">{{ $finding->due_date?->format('d.m.Y') }}</td></tr>
        </table>
        <p style="margin:28px 0;">
            <a href="{{ $url }}" style="display:inline-block;background:#ffc107;color:#111827;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:8px;">Bulgu Takip'te aç</a>
        </p>
    </div>
</body>
</html>
