<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmailLaporanRequest;
use App\Services\LaporanHarianService;
use App\Support\DaftarLaporanEmail;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/** Halaman akun milik pengguna yang sedang masuk (semua peran). */
class AkunController extends Controller
{
    public function index(Request $request, LaporanHarianService $laporan): View
    {
        return view('akun.index', [
            'email'          => $laporan->pengaturan($request->user()),
            'pilihanLaporan' => DaftarLaporanEmail::pilihan($request->user()->role),
            'jamKirim'       => str_replace(':', '.', DaftarLaporanEmail::JAM_KIRIM),
        ]);
    }

    public function simpanEmail(EmailLaporanRequest $request, LaporanHarianService $laporan): RedirectResponse
    {
        $email = $laporan->simpan($request->user(), $request->dataTervalidasi());

        return redirect()->to(route('akun').'#email-laporan')->with('sukses', $email->aktif
            ? 'Email laporan harian dinyalakan. Dikirim setiap hari pukul '.str_replace(':', '.', DaftarLaporanEmail::JAM_KIRIM).'.'
            : 'Pengaturan email tersimpan. Email laporan harian mati.');
    }

    /** Kirim laporan hari ini sekarang ke email tujuan yang tersimpan, untuk mencoba. */
    public function kirimEmail(Request $request, LaporanHarianService $laporan): RedirectResponse
    {
        $tujuan = redirect()->to(route('akun').'#email-laporan');
        $pengguna = $request->user();

        $pengaturan = $pengguna->emailLaporan()->first();

        if ($pengaturan === null) {
            return $tujuan->with('galat', 'Simpan pengaturan email dulu, lalu coba kirim.');
        }

        try {
            $laporan->kirim($pengguna, $pengaturan);
        } catch (DomainException $e) {
            return $tujuan->with('galat', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Kirim email laporan gagal untuk '.$pengguna->email.': '.$e->getMessage());

            return $tujuan->with('galat', 'Email gagal dikirim. Pengaturan pengirim email di server (MAIL_* di .env) perlu dicek.');
        }

        return $tujuan->with('sukses', 'Laporan hari ini dikirim ke '.implode(', ', $pengaturan->alamat).'.');
    }

    public function ubahPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password_lama' => ['required', 'string', 'current_password'],
            'password_baru' => ['required', 'string', Password::min(8), 'confirmed', 'different:password_lama'],
        ], [
            'password_lama.current_password' => 'Password lama salah.',
            'password_baru.confirmed'        => 'Ulangi password baru belum sama. Ketik ulang dengan teliti.',
            'password_baru.different'        => 'Password baru harus berbeda dari password lama.',
        ], [
            'password_lama' => 'password lama',
            'password_baru' => 'password baru',
        ]);

        // Perangkat lain ikut keluar; perangkat ini tetap masuk dengan sesi baru.
        $request->user()->aturPassword($data['password_baru'], $request->session()->getId());
        $request->session()->regenerate();

        return redirect()
            ->route('akun')
            ->with('sukses', 'Password berhasil diganti. Perangkat lain yang memakai akun ini sudah dikeluarkan.');
    }
}
