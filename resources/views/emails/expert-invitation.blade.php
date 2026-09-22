<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PKTakip Uzman Daveti</title>
</head>
<body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#111827;">
    <div style="max-width:620px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:36px;">
        <div style="font-size:24px;font-weight:800;margin-bottom:24px;"><span style="color:#ffc107;">PK</span>Takip</div>
        <h1 style="font-size:24px;margin:0 0 12px;">Uzman hesabınız hazır</h1>
        <p style="font-size:15px;line-height:1.6;color:#64748b;">
            PKTakip üzerinde <strong>{{ $invitation->tenant->name }}</strong> uzman hesabı oluşturuldu.
            Hesabınızı aktifleştirmek ve kendi şifrenizi belirlemek için aşağıdaki butona tıklayın.
        </p>
        <p style="margin:28px 0;">
            <a href="{{ $acceptUrl }}" style="display:inline-block;background:#ffc107;color:#111827;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:8px;">Hesabımı Aktifleştir</a>
        </p>
        <p style="font-size:13px;line-height:1.5;color:#94a3b8;">
            Bu bağlantı 48 saat geçerlidir. Bağlantıyı siz istemediyseniz bu e-postayı yok sayabilirsiniz.
        </p>
    </div>
</body>
</html>
