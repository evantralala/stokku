<?php

namespace App\Services;

use App\Models\BahanBakuMinimum;
use App\Models\BarangMasuk;
use App\Models\BarangMasukDetail;
use App\Models\Cabang;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Support\AturanWajar;
use App\Support\DaftarKontrol;
use Illuminate\Support\Collection;

/**
 * Menilai apakah dokumen di antrean persetujuan "wajar" (lihat App\Support\AturanWajar).
 * Hasil per dokumen: ['wajar' => bool, 'massal' => bool (jenisnya boleh disetujui sekaligus), 'alasan' => ?string].
 */
class CekWajarService
{
    /** @var array<int, true>|null lokasi id yang punya peringatan dini, dihitung sekali per instance */
    private ?array $lokasiBerperingatan = null;

    public function __construct(
        private PeringatanDiniService $peringatan,
        private PermintaanService $permintaan,
        private StokService $stok,
        private KontrolSistemService $kontrol,
    ) {
    }

    /** Tambahkan kunci 'cek' ke setiap baris antrean (butuh kunci jenis, id, lokasi, lokasi_id). */
    public function tandai(Collection $antrean): Collection
    {
        return $antrean->map(fn (array $a) => $a + ['cek' => $this->periksa($a['jenis'], $a['id'], $a['lokasi_id'], $a['lokasi'])]);
    }

    /** @return array{wajar: bool, massal: bool, alasan: ?string} */
    public function periksa(string $jenis, int $id, int $lokasiId, string $namaLokasi): array
    {
        if (! AturanWajar::bolehMassal($jenis)) {
            return ['wajar' => false, 'massal' => false, 'alasan' => 'Cek satu per satu'];
        }

        if (isset($this->lokasiBerperingatan()[$lokasiId])) {
            return $this->tidak('Ada peringatan di '.$namaLokasi);
        }

        $alasan = match ($jenis) {
            Permintaan::DOKUMEN_TIPE  => $this->alasanPermintaan($id),
            BarangMasuk::DOKUMEN_TIPE => $this->alasanBarangMasuk($id),
        };

        return $alasan === null ? ['wajar' => true, 'massal' => true, 'alasan' => null] : $this->tidak($alasan);
    }

    private function tidak(string $alasan): array
    {
        return ['wajar' => false, 'massal' => true, 'alasan' => $alasan];
    }

    private function lokasiBerperingatan(): array
    {
        if ($this->lokasiBerperingatan === null) {
            $this->lokasiBerperingatan = AturanWajar::TINGKAT_PERINGATAN !== [] && $this->kontrol->modulNyala(DaftarKontrol::MODUL_PERINGATAN_DINI)
                ? $this->peringatan->daftar()
                    ->whereIn('tingkat', AturanWajar::TINGKAT_PERINGATAN)
                    ->pluck('lokasi_id')->unique()->mapWithKeys(fn ($id) => [$id => true])->all()
                : [];
        }

        return $this->lokasiBerperingatan;
    }

    /** Alasan permintaan tidak wajar, atau null bila wajar. */
    private function alasanPermintaan(int $id): ?string
    {
        $permintaan = Permintaan::query()->with(['cabang', 'detail' => fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU), 'detail.bahanBaku'])->find($id);

        if (! $permintaan || $permintaan->detail->isEmpty()) {
            return 'Sudah diputuskan';
        }

        $lokasi = $permintaan->cabang;
        $pusat = Cabang::pusatAktifSelain(null);

        if (! $pusat) {
            return 'Gudang Pusat belum ada';
        }

        $bahanIds = $permintaan->detail->pluck('bahan_baku_id')->all();
        $minimum = BahanBakuMinimum::query()->where('cabang_id', $lokasi->id)->whereIn('bahan_baku_id', $bahanIds)->pluck('minimum', 'bahan_baku_id');

        foreach ($permintaan->detail as $d) {
            $bahan = $d->bahanBaku;
            $diminta = (float) $d->qty_diminta;

            if ($this->stok->saldo($pusat->id, $bahan->id) < $diminta) {
                return 'Stok pusat kurang: '.$bahan->nama;
            }

            $min = (float) ($minimum[$bahan->id] ?? $d->minimum_saat_itu ?? 0);
            $target = $this->permintaan->targetStok($min);
            $kebutuhan = max(0, $target - $this->stok->saldo($lokasi->id, $bahan->id));
            $batasBeli = ceil(round($bahan->keSatuanBeli($kebutuhan) * (1 + (float) $bahan->toleransi_persen / 100), 3));

            if ($bahan->keSatuanBeli($diminta) > $batasBeli + 0.0005) {
                return $bahan->nama.' melebihi kebutuhan';
            }
        }

        return null;
    }

    /** Alasan barang masuk tidak wajar, atau null bila wajar. */
    private function alasanBarangMasuk(int $id): ?string
    {
        $dokumen = BarangMasuk::query()->with('detail.bahanBaku')->find($id);

        if (! $dokumen || $dokumen->detail->isEmpty()) {
            return 'Sudah diputuskan';
        }

        foreach ($dokumen->detail as $d) {
            $bahan = $d->bahanBaku;
            $biasa = BarangMasukDetail::query()
                ->join('barang_masuk', 'barang_masuk.id', '=', 'barang_masuk_detail.barang_masuk_id')
                ->where('barang_masuk.cabang_id', $dokumen->cabang_id)
                ->where('barang_masuk.status', BarangMasuk::STATUS_DISETUJUI)
                ->where('barang_masuk_detail.bahan_baku_id', $bahan->id)
                ->orderByDesc('barang_masuk_detail.id')
                ->limit(AturanWajar::RIWAYAT_BARANG_MASUK)
                ->pluck('barang_masuk_detail.qty');

            if ($biasa->isEmpty()) {
                return $bahan->nama.' baru pertama masuk';
            }

            if ((float) $d->qty > (float) $biasa->max() * (1 + (float) $bahan->toleransi_persen / 100) + 0.0005) {
                return $bahan->nama.' lebih banyak dari biasa';
            }
        }

        return null;
    }
}
