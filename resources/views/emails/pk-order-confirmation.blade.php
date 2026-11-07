@php
    $tz = 'Europe/Istanbul';
    $tl = fn ($v) => number_format((float) $v, 2, ',', '.') . ' TL';
    $day = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d.m.Y') : '—';
    $at = fn ($v) => $v ? \Carbon\Carbon::parse($v, 'UTC')->timezone($tz)->format('d.m.Y H:i') : '—';
    $period = $order->period === 'yearly' ? 'Yıllık' : 'Aylık';
    $th = 'padding:6px 12px 6px 0;color:#64748b;font-weight:400;text-align:left;vertical-align:top;white-space:nowrap;';
    $td = 'padding:6px 0;color:#111827;vertical-align:top;';
    $h2 = 'font-size:16px;margin:28px 0 8px;';
    $link = 'color:#111827;';
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PKTakip Sipariş Onayı</title>
</head>
<body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#111827;">
    <div style="max-width:620px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:36px;">
        <div style="font-size:24px;font-weight:800;margin-bottom:24px;"><span style="color:#ffc107;">PK</span>Takip</div>
        <h1 style="font-size:24px;margin:0 0 12px;">Siparişiniz onaylandı</h1>
        <p style="font-size:15px;line-height:1.6;color:#64748b;">
            Ödemeniz alındı ve paketiniz tanımlandı. Bu e-posta, mesafeli satış sözleşmesinin teyidi olarak gönderilmiştir; saklamanızı öneririz.
        </p>

        <h2 style="{{ $h2 }}">Sipariş</h2>
        <table style="font-size:14px;line-height:1.5;border-collapse:collapse;">
            <tr><th style="{{ $th }}">Sipariş no</th><td style="{{ $td }}">{{ $order->merchant_oid }}</td></tr>
            <tr><th style="{{ $th }}">Tarih</th><td style="{{ $td }}">{{ $at($order->paid_at) }}</td></tr>
            <tr><th style="{{ $th }}">Paket</th><td style="{{ $td }}">{{ $packageName }} ({{ $period }})</td></tr>
            <tr><th style="{{ $th }}">Hizmet dönemi</th><td style="{{ $td }}">{{ $day($startsAt) }} – {{ $day($endsAt) }}</td></tr>
            <tr><th style="{{ $th }}">Paket bedeli</th><td style="{{ $td }}">{{ $tl($order->net_amount) }}</td></tr>
            <tr><th style="{{ $th }}">KDV (%20)</th><td style="{{ $td }}">{{ $tl($order->vat_amount) }}</td></tr>
            <tr><th style="{{ $th }}">Toplam</th><td style="{{ $td }}"><strong>{{ $tl($order->total_amount) }}</strong></td></tr>
        </table>
        <p style="font-size:14px;line-height:1.6;color:#111827;">Ödeme tek çekimdir; paket kendiliğinden yenilenmez.</p>

        <h2 style="{{ $h2 }}">Alıcı</h2>
        <table style="font-size:14px;line-height:1.5;border-collapse:collapse;">
            <tr><th style="{{ $th }}">Ad soyad</th><td style="{{ $td }}">{{ $order->name }}</td></tr>
            @if ($order->organization)
                <tr><th style="{{ $th }}">Kurum</th><td style="{{ $td }}">{{ $order->organization }}</td></tr>
            @endif
            <tr><th style="{{ $th }}">E-posta</th><td style="{{ $td }}">{{ $order->email }}</td></tr>
            <tr><th style="{{ $th }}">Telefon</th><td style="{{ $td }}">{{ $order->phone }}</td></tr>
            <tr><th style="{{ $th }}">Adres</th><td style="{{ $td }}">{{ $order->address }}</td></tr>
        </table>

        <h2 style="{{ $h2 }}">Satıcı</h2>
        <p style="font-size:14px;line-height:1.6;margin:0;">
            FIRETECH YANGIN TEKNOLOJİLERİ YAZILIM ARAŞTIRMA GELİŞTİRME MÜHENDİSLİK EĞİTİM VE DANIŞMANLIK SANAYİ VE TİCARET LİMİTED ŞİRKETİ<br>
            Kiremitçi Mah. Tamer Sk. Kiremitçi Sinan Bey Konutları 4. Etap Sitesi D1 Blok No: 29 İç Kapı No: 4 Osmangazi/Bursa<br>
            Vergi dairesi / no: Yıldırım / 3861091773 · MERSİS: 0386109177300001<br>
            Telefon: 0552 521 84 64 / 0530 717 38 54 · E-posta: <a href="mailto:bilgi@pktakip.com" style="{{ $link }}">bilgi@pktakip.com</a>
        </p>

        <h2 style="{{ $h2 }}">Cayma hakkı</h2>
        <p style="font-size:14px;line-height:1.6;margin:0;">
            Sipariş tarihinden itibaren 14 gün içinde hiçbir gerekçe göstermeden ve cezai şart ödemeden sözleşmeden cayabilirsiniz.
            Bunun için sipariş numaranızı (<strong>{{ $order->merchant_oid }}</strong>) belirterek
            <a href="mailto:bilgi@pktakip.com" style="{{ $link }}">bilgi@pktakip.com</a> adresine e-posta göndermeniz yeterlidir.
            Ayrıntılar: <a href="https://pktakip.com/iptal-iade-kosullari" style="{{ $link }}">İptal ve İade Koşulları</a>.
        </p>

        <h2 style="{{ $h2 }}">Sözleşmeler</h2>
        <p style="font-size:14px;line-height:1.6;margin:0;">
            <a href="https://pktakip.com/mesafeli-satis-sozlesmesi" style="{{ $link }}">Mesafeli Satış Sözleşmesi</a> ·
            <a href="https://pktakip.com/on-bilgilendirme-formu" style="{{ $link }}">Ön Bilgilendirme Formu</a> ·
            <a href="https://pktakip.com/iptal-iade-kosullari" style="{{ $link }}">İptal ve İade Koşulları</a><br>
            Bu siparişte Mesafeli Satış Sözleşmesi ve Ön Bilgilendirme Formu'nu {{ $at($order->agreements_accepted_at) }} tarihinde onayladınız{{ $order->agreements_version ? ' (metin sürümü ' . $order->agreements_version . ')' : '' }}.
        </p>
    </div>
</body>
</html>
