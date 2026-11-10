{{-- Email laporan harian. Variabel dari LaporanHarianMail: $subjek, $ringkasan (list of ['judul', 'baris']). --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjek }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;">
        <tr>
            <td style="padding:24px 24px 8px;">
                <h1 style="margin:0 0 4px;font-size:18px;">{{ $subjek }}</h1>
                <p style="margin:0;font-size:13px;color:#6b7280;">Rincian lengkap ada di file Excel terlampir.</p>
            </td>
        </tr>
        @foreach ($ringkasan as $bagian)
            <tr>
                <td style="padding:12px 24px;border-top:1px solid #e5e7eb;">
                    <h2 style="margin:0 0 6px;font-size:15px;">{{ $bagian['judul'] }}</h2>
                    @foreach ($bagian['baris'] as $baris)
                        <p style="margin:0 0 4px;font-size:14px;line-height:1.5;">{{ $baris }}</p>
                    @endforeach
                </td>
            </tr>
        @endforeach
        <tr>
            <td style="padding:16px 24px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">
                Email otomatis dari {{ \App\Support\Identitas::aplikasi() }}. Untuk berhenti atau mengganti tujuan, buka menu Akun.
            </td>
        </tr>
    </table>
</body>
</html>
