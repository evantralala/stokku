<?php

namespace App\Support;

use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Kiriman;
use App\Models\Opname;
use App\Models\PenyesuaianStok;
use App\Models\PeriodeBuku;
use App\Models\PenjualanHarian;
use App\Models\StokMutasi;
use App\Models\TambahStok;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Nomor dan tautan dokumen sumber untuk sekumpulan mutasi, sesuai halaman yang boleh dibuka peran pengguna.
 * Dokumen yang tidak punya halaman untuk peran itu tetap ditampilkan nomornya tanpa tautan.
 */
final class TautanDokumen
{
    /** [peran => [dokumen_tipe => nama route]]; pemakaian dapur ditangani terpisah lewat penjualan harian. */
    private const RUTE = [
        User::ROLE_ADMIN_UTAMA => [
            BarangMasuk::DOKUMEN_TIPE  => 'admin-utama.persetujuan.barang-masuk',
            BarangKeluar::DOKUMEN_TIPE => 'admin-utama.persetujuan.barang-keluar',
            Opname::DOKUMEN_TIPE       => 'admin-utama.persetujuan.opname',
        ],
        User::ROLE_ADMIN_PUSAT => [
            BarangMasuk::DOKUMEN_TIPE  => 'pusat.barang-masuk.lihat',
            BarangKeluar::DOKUMEN_TIPE => 'pusat.barang-keluar.lihat',
            Opname::DOKUMEN_TIPE       => 'pusat.opname.lihat',
        ],
        User::ROLE_ADMIN_CABANG => [
            BarangKeluar::DOKUMEN_TIPE => 'cabang.barang-keluar.lihat',
            Opname::DOKUMEN_TIPE       => 'cabang.opname.lihat',
        ],
    ];

    /** Kiriman dibuka dari sisi pengirim atau penerima, sesuai lokasi mutasinya. [peran => [pengirim, penerima]] */
    private const RUTE_KIRIMAN = [
        User::ROLE_ADMIN_PUSAT  => ['pusat.kiriman.lihat', 'pusat.terima-barang.lihat'],
        User::ROLE_ADMIN_CABANG => ['cabang.kirim-barang.lihat', 'cabang.terima-barang.lihat'],
    ];

    private const MODEL = [
        BarangMasuk::DOKUMEN_TIPE  => BarangMasuk::class,
        BarangKeluar::DOKUMEN_TIPE => BarangKeluar::class,
        Kiriman::DOKUMEN_TIPE      => Kiriman::class,
        Opname::DOKUMEN_TIPE       => Opname::class,
        TambahStok::DOKUMEN_TIPE   => TambahStok::class,
        PenyesuaianStok::DOKUMEN_TIPE => PenyesuaianStok::class,
        PeriodeBuku::DOKUMEN_TIPE  => PeriodeBuku::class,
    ];

    /**
     * @param  Collection<int, StokMutasi>  $mutasi
     * @return array<int, array{nomor: string, url: ?string}> [mutasi id => ...]
     */
    public static function untuk(User $pengguna, Collection $mutasi): array
    {
        $rute = self::RUTE[$pengguna->role] ?? [];
        $dokumen = [];

        foreach ($mutasi->groupBy('dokumen_tipe') as $tipe => $daftar) {
            $kelas = self::MODEL[$tipe] ?? null;

            if ($kelas) {
                $dokumen[$tipe] = (in_array(SoftDeletes::class, class_uses_recursive($kelas), true) ? $kelas::withTrashed() : $kelas::query())
                    ->whereKey($daftar->pluck('dokumen_id')->unique()->all())
                    ->get()
                    ->keyBy('id');
            }
        }

        $penjualan = PenjualanHarian::query()
            ->whereIn('barang_keluar_id', $mutasi->where('dokumen_tipe', BarangKeluar::DOKUMEN_TIPE)->pluck('dokumen_id')->unique()->all())
            ->pluck('id', 'barang_keluar_id');

        $hasil = [];

        foreach ($mutasi as $m) {
            $doc = $dokumen[$m->dokumen_tipe][$m->dokumen_id] ?? null;
            $url = null;

            if ($doc && $m->jenis === StokMutasi::JENIS_PAKAI_DAPUR) {
                $idPenjualan = $penjualan[$doc->id] ?? null;
                $url = $idPenjualan && $pengguna->isAdminCabang() ? route('cabang.penjualan.lihat', $idPenjualan) : null;
            } elseif ($doc instanceof Kiriman && isset(self::RUTE_KIRIMAN[$pengguna->role])) {
                $url = route(self::RUTE_KIRIMAN[$pengguna->role][(int) $m->cabang_id === (int) $doc->dari_cabang_id ? 0 : 1], $doc);
            } elseif ($doc && isset($rute[$m->dokumen_tipe]) && Route::has($rute[$m->dokumen_tipe])) {
                $url = route($rute[$m->dokumen_tipe], $doc);
            }

            $hasil[$m->id] = ['nomor' => $doc?->nomor ?? '—', 'url' => $url];
        }

        return $hasil;
    }
}
