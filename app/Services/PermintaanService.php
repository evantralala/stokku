<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Permintaan bahan dari cabang ke Gudang Pusat, manual maupun otomatis.
 * Permintaan tidak mengubah stok; stok berpindah saat kiriman dibuat dan diterima.
 */
class PermintaanService
{
    /** Rentang hari untuk menghitung rata-rata pemakaian harian. */
    public const HARI_RATA_RATA = 7;

    private const BATAS_JAM_BAWAAN = 24;

    /** Persen tambahan usulan isi stok (minimum + persen) bila belum ada di pengaturan. */
    public const PERSEN_CADANGAN_BAWAAN = 20;

    public function __construct(private NotifikasiService $notifikasi)
    {
    }

    /**
     * @param  array{baris: array<int, array{bahan_baku_id: int|string, qty: float|string, alasan?: string|null}>}  $data
     *         qty dalam satuan beli
     */
    public function ajukan(?User $pengaju, Cabang $lokasi, array $data, string $sumber = Permintaan::SUMBER_MANUAL): Permintaan
    {
        return DB::transaction(function () use ($pengaju, $lokasi, $data, $sumber) {
            $sekarang = now();
            $batasJam = (int) Pengaturan::ambil('batas_jam_persetujuan', self::BATAS_JAM_BAWAAN);

            $permintaan = Permintaan::create([
                'nomor'           => NomorDokumen::berikutnya('permintaan', Permintaan::AWALAN_NOMOR, $lokasi, $sekarang),
                'cabang_id'       => $lokasi->id,
                'diajukan_oleh'   => $pengaju?->id,
                'diajukan_pada'   => $sekarang,
                'batas_keputusan' => $sekarang->copy()->addHours($batasJam),
                'sumber'          => $sumber,
                'status'          => Permintaan::STATUS_MENUNGGU,
            ]);

            $bahan = BahanBaku::query()
                ->whereKey(collect($data['baris'])->pluck('bahan_baku_id')->all())
                ->get(['id', 'isi_per_satuan_beli'])
                ->keyBy('id');

            $kondisi = $this->kondisiStok($lokasi, $bahan->keys()->all());

            foreach ($data['baris'] as $b) {
                $id = (int) $b['bahan_baku_id'];

                $permintaan->detail()->create([
                    'bahan_baku_id'    => $id,
                    'qty_diminta'      => $bahan[$id]->keSatuanDasar($b['qty']),
                    'sisa_saat_itu'    => $kondisi[$id]['saldo'] ?? 0,
                    'minimum_saat_itu' => $kondisi[$id]['minimum'] ?? 0,
                    'status'           => PermintaanDetail::STATUS_MENUNGGU,
                    'alasan'           => $b['alasan'] ?? null,
                ]);
            }

            $this->notifikasi->keAdminUtama(
                'Permintaan baru dari '.$lokasi->nama,
                count($data['baris']).' barang · '.$permintaan->nomor.($sumber === Permintaan::SUMBER_OTOMATIS ? ' · dibuat otomatis' : '')
                    .' · putuskan sebelum '.$permintaan->batas_keputusan->translatedFormat('j M, H.i'),
                route('admin-utama.persetujuan.permintaan', $permintaan, false)
            );

            return $permintaan;
        });
    }

    public function batalkan(Permintaan $permintaan): Permintaan
    {
        return DB::transaction(function () use ($permintaan) {
            $permintaan = Permintaan::whereKey($permintaan->id)->lockForUpdate()->firstOrFail();

            if (! $permintaan->bisaDibatalkan()) {
                throw new DomainException('Permintaan ini sudah diproses Admin Utama, jadi tidak bisa dibatalkan.');
            }

            $permintaan->update(['status' => Permintaan::STATUS_BATAL]);
            $this->notifikasi->selesaikan(route('admin-utama.persetujuan.permintaan', $permintaan, false));

            return $permintaan;
        });
    }

    /** Persen tambahan dari stok minimum untuk usulan isi stok dan permintaan otomatis. */
    public function persenCadangan(): float
    {
        return (float) Pengaturan::ambil('persen_cadangan_minta', self::PERSEN_CADANGAN_BAWAAN);
    }

    /** Stok yang dituju saat cabang meminta barang: stok minimum ditambah persen cadangan (satuan dasar). */
    public function targetStok(float $minimum): float
    {
        return $minimum * (1 + $this->persenCadangan() / 100);
    }

    /**
     * Bahan aktif yang stoknya di bawah minimum dan belum sedang diminta.
     * Jumlah usulan = target - saldo, dibulatkan ke atas ke satuan beli utuh.
     * Target = stok minimum + persen cadangan (Pengaturan "Cadangan isi stok").
     *
     * @return Collection<int, array{bahan: BahanBaku, saldo: float, minimum: float, qty_dasar: float, qty_beli: float}>
     */
    public function usulan(Cabang $lokasi): Collection
    {
        $minimum = BahanBakuMinimum::query()
            ->where('cabang_id', $lokasi->id)
            ->where('minimum', '>', 0)
            ->whereHas('bahanBaku', fn ($q) => $q->aktif())
            ->pluck('minimum', 'bahan_baku_id');

        if ($minimum->isEmpty()) {
            return collect();
        }

        $sedangDiminta = $this->sedangDiminta($lokasi);
        $saldo = $this->saldo($lokasi, $minimum->keys()->all());

        $bahan = BahanBaku::query()
            ->whereKey($minimum->keys()->diff($sedangDiminta)->all())
            ->with('satuanBeli:id,nama')
            ->orderBy('nama')
            ->get(['id', 'nama', 'satuan_beli_id', 'isi_per_satuan_beli']);

        return $bahan
            ->map(function (BahanBaku $b) use ($minimum, $saldo) {
                $min = (float) $minimum[$b->id];
                $sisa = (float) ($saldo[$b->id] ?? 0);

                if ($sisa >= $min) {
                    return null;
                }

                $target = $this->targetStok($min);
                $qtyBeli = ceil(round($b->keSatuanBeli($target - $sisa), 3));

                return [
                    'bahan'     => $b,
                    'saldo'     => $sisa,
                    'minimum'   => $min,
                    'qty_beli'  => (float) $qtyBeli,
                    'qty_dasar' => $b->keSatuanDasar($qtyBeli),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Buat permintaan otomatis untuk setiap cabang aktif yang punya bahan di bawah minimum.
     *
     * @return array<string, int> [nama cabang => jumlah barang yang diminta]
     */
    public function buatOtomatis(): array
    {
        $hasil = [];

        Cabang::query()->cabang()->aktif()->orderBy('nama')->get()->each(function (Cabang $lokasi) use (&$hasil) {
            $usulan = $this->usulan($lokasi);

            if ($usulan->isEmpty()) {
                return;
            }

            $this->ajukan(null, $lokasi, [
                'baris'     => $usulan->map(fn (array $u) => [
                    'bahan_baku_id' => $u['bahan']->id,
                    'qty'           => $u['qty_beli'],
                    'alasan'        => 'Stok di bawah minimum',
                ])->all(),
            ], Permintaan::SUMBER_OTOMATIS);

            $hasil[$lokasi->nama] = $usulan->count();
        });

        return $hasil;
    }

    /**
     * Saldo dan minimum per bahan (satuan dasar) di satu lokasi.
     *
     * @param  list<int>  $bahanIds
     * @return array<int, array{saldo: float, minimum: float}>
     */
    public function kondisiStok(Cabang $lokasi, array $bahanIds): array
    {
        $saldo = $this->saldo($lokasi, $bahanIds);
        $minimum = BahanBakuMinimum::query()
            ->where('cabang_id', $lokasi->id)
            ->whereIn('bahan_baku_id', $bahanIds)
            ->pluck('minimum', 'bahan_baku_id');

        return collect($bahanIds)
            ->mapWithKeys(fn (int $id) => [$id => [
                'saldo'   => (float) ($saldo[$id] ?? 0),
                'minimum' => (float) ($minimum[$id] ?? 0),
            ]])
            ->all();
    }

    /** Bahan yang masih menunggu keputusan atau sudah disetujui tapi belum terkirim penuh. */
    public function sedangDiminta(Cabang $lokasi): Collection
    {
        return PermintaanDetail::query()
            ->whereHas('permintaan', fn ($q) => $q->diLokasi($lokasi->id)->where('status', Permintaan::STATUS_MENUNGGU))
            ->where(fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU)->orWhere(fn ($q) => $q->masihUtang()))
            ->distinct()
            ->pluck('bahan_baku_id');
    }

    private function saldo(Cabang $lokasi, array $bahanIds): Collection
    {
        return DB::table('stok_saldo')
            ->where('cabang_id', $lokasi->id)
            ->whereIn('bahan_baku_id', $bahanIds)
            ->pluck('saldo', 'bahan_baku_id');
    }

    /**
     * Rata-rata pemakaian dapur per hari (satuan dasar) selama HARI_RATA_RATA hari terakhir.
     *
     * @param  list<int>  $bahanIds
     * @return Collection<int, float> [bahan_baku_id => pemakaian per hari]
     */
    public function rataRataPemakaian(Cabang $lokasi, array $bahanIds): Collection
    {
        return StokMutasi::query()
            ->where('cabang_id', $lokasi->id)
            ->whereIn('bahan_baku_id', $bahanIds)
            ->where('jenis', StokMutasi::JENIS_PAKAI_DAPUR)
            ->sah()
            ->where('dibuat_pada', '>=', Carbon::now()->subDays(self::HARI_RATA_RATA))
            ->groupBy('bahan_baku_id')
            ->selectRaw('bahan_baku_id, sum(jumlah) as total')
            ->pluck('total', 'bahan_baku_id')
            ->map(fn ($total) => abs((float) $total) / self::HARI_RATA_RATA);
    }
}
