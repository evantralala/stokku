<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Pengaturan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keadaan stok satu lokasi: saldo, minimum, rata-rata pemakaian, perkiraan cukup berapa hari, dan status.
 *
 * Status (urut dari paling gawat):
 *   minus   saldo < 0 (stok sistem tidak cocok dengan fisik)
 *   habis   saldo = 0
 *   kurang  di bawah stok minimum
 *   waspada sampai minimum + ambang persen (Pengaturan ambang_stok_menipis)
 *   aman    selebihnya
 *
 * Lampu (traffic light): merah = minus/habis, kuning = kurang/waspada, hijau = aman.
 * Indikator di layar memakai label lampu (Habis, Menipis, Aman); label status menjadi rincian.
 */
class PantauanStokService
{
    public const STATUS_MINUS = 'minus';
    public const STATUS_HABIS = 'habis';
    public const STATUS_KURANG = 'kurang';
    public const STATUS_WASPADA = 'waspada';
    public const STATUS_AMAN = 'aman';

    public const LAMPU_MERAH = 'merah';
    public const LAMPU_KUNING = 'kuning';
    public const LAMPU_HIJAU = 'hijau';

    /** [label, kelas lampu] per status, urut dari paling gawat. */
    public const TAMPILAN_STATUS = [
        self::STATUS_MINUS   => ['Minus', 'lampu-merah'],
        self::STATUS_HABIS   => ['Habis', 'lampu-merah'],
        self::STATUS_KURANG  => ['Di Bawah Minimum', 'lampu-kuning'],
        self::STATUS_WASPADA => ['Hampir Minimum', 'lampu-kuning'],
        self::STATUS_AMAN    => ['Aman', 'lampu-hijau'],
    ];

    /** Lampu per status. */
    public const LAMPU_STATUS = [
        self::STATUS_MINUS   => self::LAMPU_MERAH,
        self::STATUS_HABIS   => self::LAMPU_MERAH,
        self::STATUS_KURANG  => self::LAMPU_KUNING,
        self::STATUS_WASPADA => self::LAMPU_KUNING,
        self::STATUS_AMAN    => self::LAMPU_HIJAU,
    ];

    /** [label, kelas lampu] per lampu, urut dari paling gawat. */
    public const TAMPILAN_LAMPU = [
        self::LAMPU_MERAH  => ['Habis', 'lampu-merah'],
        self::LAMPU_KUNING => ['Menipis', 'lampu-kuning'],
        self::LAMPU_HIJAU  => ['Aman', 'lampu-hijau'],
    ];

    private const AMBANG_BAWAAN = 20;

    public function __construct(private PermintaanService $permintaan, private StokService $stok)
    {
    }

    /**
     * Semua bahan aktif (ditambah bahan nonaktif yang masih punya stok) di satu lokasi.
     *
     * menunggu = mutasi yang belum disahkan (mis. barang masuk menunggu persetujuan), belum termasuk saldo.
     *
     * @param  list<int>|null  $bahanIds  hanya bahan ini; null = semua
     * @return Collection<int, array{bahan: BahanBaku, saldo: float, menunggu: float, minimum: float, pakai_per_hari: float, cukup_hari: ?float, status: string, lampu: string}>
     */
    public function daftar(Cabang $lokasi, ?array $bahanIds = null): Collection
    {
        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->pluck('saldo', 'bahan_baku_id');
        $minimum = BahanBakuMinimum::query()->where('cabang_id', $lokasi->id)->pluck('minimum', 'bahan_baku_id');
        $menunggu = $this->stok->menunggu($lokasi->id);
        $ambang = (float) Pengaturan::ambil('ambang_stok_menipis', self::AMBANG_BAWAAN);

        $bahan = BahanBaku::query()
            ->where(fn ($q) => $q->where('aktif', true)->orWhereIn('id', $saldo->filter(fn ($s) => (float) $s != 0.0)->keys()))
            ->when($bahanIds !== null, fn ($q) => $q->whereKey($bahanIds))
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama', 'satuanDasar:id,nama'])
            ->orderBy('nama')
            ->get();

        $pakai = $this->permintaan->rataRataPemakaian($lokasi, $bahan->pluck('id')->all());

        return $bahan->map(function (BahanBaku $b) use ($saldo, $minimum, $menunggu, $pakai, $ambang) {
            $sisa = round((float) ($saldo[$b->id] ?? 0), 3);
            $min = (float) ($minimum[$b->id] ?? 0);
            $perHari = round((float) ($pakai[$b->id] ?? 0), 3);
            $status = self::status($sisa, $min, $ambang);

            return [
                'bahan'          => $b,
                'saldo'          => $sisa,
                'menunggu'       => $menunggu[$b->id] ?? 0.0,
                'minimum'        => $min,
                'pakai_per_hari' => $perHari,
                'cukup_hari'     => $perHari > 0 ? max(0, round($sisa / $perHari, 1)) : null,
                'status'         => $status,
                'lampu'          => self::lampu($status),
            ];
        });
    }

    public static function status(float $saldo, float $minimum, float $ambangPersen): string
    {
        return match (true) {
            $saldo < 0                                              => self::STATUS_MINUS,
            $saldo == 0.0                                           => self::STATUS_HABIS,
            $minimum > 0 && $saldo < $minimum                       => self::STATUS_KURANG,
            $minimum > 0 && $saldo <= $minimum * (1 + $ambangPersen / 100) => self::STATUS_WASPADA,
            default                                                 => self::STATUS_AMAN,
        };
    }

    public static function lampu(string $status): string
    {
        return self::LAMPU_STATUS[$status] ?? self::LAMPU_HIJAU;
    }

    /** Label indikator satu status: Habis, Menipis, atau Aman. */
    public static function label(string $status): string
    {
        return self::TAMPILAN_LAMPU[self::lampu($status)][0];
    }

    /** Rincian status bila berbeda dari labelnya, mis. "Minus" atau "Di bawah minimum"; null untuk habis dan aman. */
    public static function rincian(string $status): ?string
    {
        $rincian = self::TAMPILAN_STATUS[$status][0] ?? null;

        return $rincian === null || $rincian === self::label($status) ? null : $rincian;
    }

    /** Teks status lengkap untuk Excel dan PDF, mis. "Menipis (di bawah minimum)". */
    public static function teks(string $status): string
    {
        $rincian = self::rincian($status);

        return self::label($status).($rincian ? ' ('.mb_strtolower($rincian).')' : '');
    }

    /** Kelas CSS lampu untuk satu status, mis. "lampu-merah". */
    public static function kelasLampu(string $status): string
    {
        return self::TAMPILAN_LAMPU[self::lampu($status)][1];
    }

    /** Jumlah bahan per lampu, urut sesuai TAMPILAN_LAMPU. */
    public static function hitungPerLampu(Collection $daftar): array
    {
        $jumlah = $daftar->countBy(fn ($r) => self::lampu($r['status']));

        return collect(self::TAMPILAN_LAMPU)->keys()->mapWithKeys(fn ($l) => [$l => $jumlah[$l] ?? 0])->all();
    }

    /**
     * Baris pantauan dikelompokkan per kategori, urut kategori.urutan lalu nama. Urutan baris di dalam kelompok dipertahankan.
     *
     * @return Collection<int, array{kategori: \App\Models\Kategori, baris: Collection, lampu: array<string, int>}>
     */
    public static function kelompokkanPerKategori(Collection $daftar): Collection
    {
        return $daftar
            ->groupBy(fn ($r) => $r['bahan']->kategori_id)
            ->map(fn (Collection $baris) => [
                'kategori' => $baris->first()['bahan']->kategori,
                'baris'    => $baris->values(),
                'lampu'    => self::hitungPerLampu($baris),
            ])
            ->sortBy(fn ($k) => [$k['kategori']->urutan, $k['kategori']->nama])
            ->values();
    }
}
