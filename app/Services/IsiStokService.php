<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Permintaan;
use App\Models\TambahStok;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Isi stok banyak bahan sekaligus dari halaman Stok (yang dicentang, atau semua yang habis/menipis).
 * Usulan jumlah tiap bahan = stok minimum + persen (Pengaturan persen_cadangan_minta, bawaan 20%), dalam satuan beli.
 *
 * Mode:
 *   minta   Admin Cabang: semua bahan menjadi satu permintaan ke Admin Utama (cabang tidak bisa menambah stok langsung);
 *   tambah  Admin Utama dan Admin Pusat: stok lokasi langsung bertambah lewat tambah stok.
 */
class IsiStokService
{
    public const MODE_MINTA = 'minta';
    public const MODE_TAMBAH = 'tambah';

    public const KETERANGAN_BAWAAN = 'Isi stok dari halaman Stok';

    public function __construct(
        private PantauanStokService $pantauan,
        private PermintaanService $permintaan,
        private TambahStokService $tambahStok,
        private NotifikasiService $notifikasi,
    ) {
    }

    public function mode(User $pengguna): string
    {
        return $pengguna->role === User::ROLE_ADMIN_CABANG ? self::MODE_MINTA : self::MODE_TAMBAH;
    }

    /**
     * Bahan untuk popup isi stok, urut dari yang paling gawat: bahan yang dicentang, atau (tanpa centang)
     * semua bahan aktif berlampu merah atau kuning.
     * diminta = sudah ada di permintaan yang berjalan (hanya mode minta), jadi tidak bisa dipilih lagi.
     *
     * @param  list<int>|null  $bahanIds  hanya bahan ini; null = semua
     * @return Collection<int, array{bahan: BahanBaku, saldo: float, minimum: float, status: string, lampu: string, usulan: ?float, diminta: bool}>
     */
    public function daftar(Cabang $lokasi, string $mode, ?array $bahanIds = null): Collection
    {
        $semua = $this->pantauan->daftar($lokasi, $bahanIds);
        $pilihan = $this->pilihan($lokasi, $mode, $semua);
        $persen = $this->persen();
        $urutan = array_flip(array_keys(PantauanStokService::TAMPILAN_STATUS));

        return $semua
            ->filter(fn (array $r) => array_key_exists($r['bahan']->id, $pilihan))
            ->filter(fn (array $r) => $bahanIds !== null || $r['lampu'] !== PantauanStokService::LAMPU_HIJAU)
            ->map(fn (array $r) => $r + [
                'usulan'  => $this->usulan($r['bahan'], $r['minimum'], $persen),
                'diminta' => $pilihan[$r['bahan']->id],
            ])
            ->sortBy(fn (array $r) => [$urutan[$r['status']], $r['bahan']->nama])
            ->values();
    }

    /**
     * Bahan yang bisa dicentang di halaman Stok: mode minta = aktif dan berlampu merah atau kuning;
     * mode tambah = semua bahan aktif (yang aman pun boleh ditambah). Tanpa centang, "Isi Semua" tetap hanya yang merah/kuning.
     *
     * @param  Collection<int, array>  $baris  baris PantauanStokService::daftar()
     * @return array<int, bool> [bahan id => sudah dalam permintaan berjalan]
     */
    public function pilihan(Cabang $lokasi, string $mode, Collection $baris): array
    {
        $diminta = $mode === self::MODE_MINTA ? $this->permintaan->sedangDiminta($lokasi)->flip() : collect();

        return $baris
            ->filter(fn (array $r) => $r['bahan']->aktif && ($mode === self::MODE_TAMBAH || $r['lampu'] !== PantauanStokService::LAMPU_HIJAU))
            ->mapWithKeys(fn (array $r) => [$r['bahan']->id => isset($diminta[$r['bahan']->id])])
            ->all();
    }

    /** Usulan isi dalam satuan beli: minimum + persen. Null bila minimum belum diatur. */
    public function usulan(BahanBaku $bahan, float $minimum, ?float $persen = null): ?float
    {
        if ($minimum <= 0) {
            return null;
        }

        $persen ??= $this->persen();

        return round($bahan->keSatuanBeli($minimum * (1 + $persen / 100)), 3);
    }

    public function persen(): float
    {
        return $this->permintaan->persenCadangan();
    }

    /**
     * Satu permintaan berisi semua bahan.
     *
     * @param  array<int, array{bahan_baku_id: int, qty: float}>  $baris  qty dalam satuan beli
     *
     * @throws DomainException bila ada bahan yang masih dalam permintaan berjalan
     */
    public function minta(User $pengaju, Cabang $lokasi, array $baris): Permintaan
    {
        $sedang = $this->permintaan->sedangDiminta($lokasi)->flip();
        $dobel = collect($baris)->filter(fn (array $b) => isset($sedang[$b['bahan_baku_id']]))->pluck('bahan_baku_id');

        if ($dobel->isNotEmpty()) {
            $nama = BahanBaku::query()->whereKey($dobel->all())->orderBy('nama')->pluck('nama')->implode(', ');

            throw new DomainException("Masih dalam permintaan yang belum selesai: {$nama}. Hapus centangnya lalu kirim lagi.");
        }

        return $this->permintaan->ajukan($pengaju, $lokasi, [
            'baris' => collect($baris)->map(fn (array $b) => [
                'bahan_baku_id' => $b['bahan_baku_id'],
                'qty'           => $b['qty'],
                'alasan'        => 'Stok habis atau menipis',
            ])->values()->all(),
        ]);
    }

    /**
     * Tambah stok langsung semua bahan dalam satu transaksi; Admin Utama menerima satu pemberitahuan ringkas.
     *
     * @param  array<int, array{bahan_baku_id: int, qty: float}>  $baris  qty dalam satuan beli
     * @return Collection<int, TambahStok>
     */
    public function tambah(User $oleh, Cabang $lokasi, array $baris, ?string $keterangan): Collection
    {
        $bahan = BahanBaku::query()
            ->whereKey(collect($baris)->pluck('bahan_baku_id')->all())
            ->with('satuanBeli:id,nama')
            ->get()
            ->keyBy('id');

        $dokumen = DB::transaction(fn () => collect($baris)->map(fn (array $b) => $this->tambahStok->tambah(
            $oleh, $lokasi, $bahan[$b['bahan_baku_id']], (float) $b['qty'],
            $keterangan ?? self::KETERANGAN_BAWAAN, beriTahu: false,
        ))->values());

        if (! $oleh->isAdminUtama()) {
            $this->notifikasi->keAdminUtama(
                'Stok ditambah langsung: '.$lokasi->nama,
                $dokumen->count().' bahan · '.$dokumen->first()->nomor.($dokumen->count() > 1 ? ' – '.$dokumen->last()->nomor : '').' · '.$oleh->nama,
                route('admin-utama.stok', ['lokasi' => $lokasi->id], false)
            );
        }

        return $dokumen;
    }
}
