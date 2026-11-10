<?php

namespace App\Services;

use App\Models\BarangKeluar;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\PenjualanDetail;
use App\Models\PenjualanHarian;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penjualan harian cabang dan pemakaian bahan yang dihasilkannya.
 *
 * Pemakaian = porsi x takaran resep yang berlaku di tanggal penjualan. Dicatat sebagai barang keluar
 * jenis pemakaian yang langsung disahkan (tidak perlu persetujuan Admin Utama). Stok boleh menjadi minus:
 * itu tanda stok sistem dan fisik tidak cocok, dan akan terlihat di peringatan dini serta dikoreksi opname.
 * Perubahan penjualan dicatat sebagai mutasi pembalik + mutasi baru, sehingga buku besar tetap utuh.
 * Penjualan dicatat Admin Cabang, atau diimpor dari Excel oleh Admin Utama.
 */
class PenjualanService
{
    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
        private LogPerubahanService $log,
    ) {
    }

    /**
     * Penjualan yang dicatat manual oleh Admin Cabang.
     *
     * @param  array<int|string, int|string|null>  $porsi  [menu_id => porsi]
     */
    public function catat(User $pencatat, Cabang $lokasi, string $tanggal, array $porsi): PenjualanHarian
    {
        return DB::transaction(function () use ($pencatat, $lokasi, $tanggal, $porsi) {
            $lokasi = Cabang::whereKey($lokasi->id)->lockForUpdate()->firstOrFail();

            if ($this->cari($lokasi, $tanggal)) {
                throw new DomainException('Penjualan tanggal ini sudah dicatat. Buka dan ubah catatan tersebut.');
            }

            return $this->buat($lokasi, $tanggal, $porsi, $pencatat);
        });
    }

    /**
     * Ganti jumlah porsi penjualan. Pemakaian lama dibalik lewat mutasi positif, lalu pemakaian baru dicatat.
     * Admin Cabang: catatannya sendiri sebelum batas waktu. Dari halaman Catatan Penjualan Admin Utama ($hakPenuh, juga
     * Admin Pusat yang diberi menu itu): kapan pun untuk membetulkan salah input; pencatat aslinya tetap, perubahan
     * tercatat atas nama yang mengubah dan cabang diberi tahu.
     */
    public function ubah(PenjualanHarian $penjualan, User $pencatat, array $porsi, bool $hakPenuh = false): PenjualanHarian
    {
        $hakPenuh = $hakPenuh || $pencatat->isAdminUtama();

        $hasil = DB::transaction(function () use ($penjualan, $pencatat, $porsi, $hakPenuh) {
            $penjualan = PenjualanHarian::whereKey($penjualan->id)->lockForUpdate()->firstOrFail();

            if (! $hakPenuh && ! $penjualan->bisaDiubah()) {
                throw new DomainException($penjualan->dicatatCabang()
                    ? 'Penjualan ini sudah melewati batas waktu perubahan.'
                    : 'Penjualan ini diimpor Admin Utama, jadi tidak bisa diubah dari cabang.');
            }

            if (app(PeriodeBukuService::class)->penjualanTertutup($penjualan->cabang_id, $penjualan->tanggal->toDateString())) {
                throw new DomainException(PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP);
            }

            $lama = $penjualan->detail()->with('menu:id,nama')->get()->mapWithKeys(fn ($d) => [$d->menu->nama => (int) $d->porsi]);

            $this->gantiIsi($penjualan, $porsi, $pencatat, gantiPencatat: ! $hakPenuh);

            $baru = $penjualan->detail()->with('menu:id,nama')->get()->mapWithKeys(fn ($d) => [$d->menu->nama => (int) $d->porsi]);
            $berubah = $lama->keys()->merge($baru->keys())->unique()
                ->filter(fn (string $menu) => ($lama[$menu] ?? 0) !== ($baru[$menu] ?? 0))
                ->mapWithKeys(fn (string $menu) => [$menu => [($lama[$menu] ?? 0), ($baru[$menu] ?? 0)]]);

            if ($berubah->isNotEmpty()) {
                $penjualan->loadMissing('cabang:id,nama');
                $this->log->catat(
                    $pencatat,
                    LogPerubahan::KATEGORI_SISTEM,
                    'penjualan.ubah',
                    'Penjualan '.$penjualan->cabang->nama.' '.$penjualan->tanggal->translatedFormat('j M Y').' diubah: '
                        .$berubah->map(fn (array $ubah, string $menu) => "{$menu} {$ubah[0]} → {$ubah[1]}")->implode(', ').' porsi.',
                    $berubah->map(fn (array $ubah, string $menu) => ['label' => $menu, 'lama' => $ubah[0].' porsi', 'baru' => $ubah[1].' porsi'])->values()->all(),
                    $penjualan,
                );
            }

            return [$penjualan, $berubah];
        });

        [$penjualan, $berubah] = $hasil;

        if ($berubah->isNotEmpty()) {
            $this->beriTahuPerubahan($penjualan, $pencatat, $berubah, $hakPenuh);
        }

        return $penjualan;
    }

    /** Diubah dari halaman Admin Utama: cabang diberi tahu. Diubah cabang, atau oleh Admin Pusat: Admin Utama diberi tahu. */
    private function beriTahuPerubahan(PenjualanHarian $penjualan, User $pencatat, \Illuminate\Support\Collection $berubah, bool $hakPenuh): void
    {
        $penjualan->loadMissing('cabang:id,nama');
        $rincian = $berubah->map(fn (array $ubah, string $menu) => "{$menu} {$ubah[0]} → {$ubah[1]}")->take(3)->implode(', ')
            .($berubah->count() > 3 ? ', …' : '').' · '.$pencatat->nama;
        $tanggal = $penjualan->tanggal->translatedFormat('j M Y');

        if ($hakPenuh) {
            $this->notifikasi->keLokasi($penjualan->cabang_id, "Penjualan {$tanggal} diubah {$pencatat->label_role}", $rincian,
                route('cabang.penjualan.lihat', $penjualan, false));
        }

        if (! $pencatat->isAdminUtama()) {
            $this->notifikasi->keAdminUtama("Penjualan {$penjualan->cabang->nama} {$tanggal} diubah", $rincian,
                route('admin-utama.penjualan.lihat', $penjualan, false));
        }
    }

    /**
     * Penjualan dari impor Excel Admin Utama: dibuat, atau isinya diganti bila tanggal itu sebelumnya juga diimpor.
     * Penjualan yang dicatat Admin Cabang tidak pernah diganti impor. Tidak terikat batas waktu ubah milik Admin Cabang.
     *
     * @throws DomainException bila tanggal itu sudah dicatat Admin Cabang
     *
     * @param  array<int, int>  $porsi  [menu_id => porsi]
     */
    public function terapkanImpor(Cabang $lokasi, string $tanggal, array $porsi, User $oleh): PenjualanHarian
    {
        return DB::transaction(function () use ($lokasi, $tanggal, $porsi, $oleh) {
            $lokasi = Cabang::whereKey($lokasi->id)->lockForUpdate()->firstOrFail();

            $ada = $this->cari($lokasi, $tanggal, kunci: true);

            if ($ada && $ada->dicatatCabang()) {
                throw new DomainException("Penjualan {$lokasi->nama} tanggal ini sudah dicatat cabang, jadi tidak diganti.");
            }

            if ($ada) {
                $this->gantiIsi($ada, $porsi, $oleh);

                return $ada;
            }

            return $this->buat($lokasi, $tanggal, $porsi, $oleh);
        });
    }

    /** Batalkan penjualan: seluruh pemakaian dibalik, dokumen pemakaian ditandai batal, penjualan dihapus lunak. */
    public function batalkan(PenjualanHarian $penjualan, ?User $oleh): void
    {
        DB::transaction(function () use ($penjualan, $oleh) {
            $penjualan = PenjualanHarian::whereKey($penjualan->id)->lockForUpdate()->firstOrFail();
            $dokumen = BarangKeluar::whereKey($penjualan->barang_keluar_id)->lockForUpdate()->firstOrFail();

            $this->balikPemakaian($dokumen, $oleh);
            $this->stok->sahkan(BarangKeluar::DOKUMEN_TIPE, $dokumen->id, bolehMinus: true);

            $dokumen->update(['status' => BarangKeluar::STATUS_BATAL]);
            $penjualan->detail()->delete();
            $penjualan->delete();
        });
    }

    private function cari(Cabang $lokasi, string $tanggal, bool $kunci = false): ?PenjualanHarian
    {
        return PenjualanHarian::query()
            ->diLokasi($lokasi->id)
            ->whereDate('tanggal', $tanggal)
            ->when($kunci, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function buat(Cabang $lokasi, string $tanggal, array $porsi, ?User $pencatat): PenjualanHarian
    {
        $dokumen = BarangKeluar::create([
            'nomor'        => NomorDokumen::berikutnya('barang_keluar', BarangKeluar::AWALAN_NOMOR, $lokasi, Carbon::parse($tanggal)),
            'cabang_id'    => $lokasi->id,
            'tanggal'      => $tanggal,
            'jenis'        => BarangKeluar::JENIS_PEMAKAIAN,
            'dicatat_oleh' => $pencatat?->id,
            'status'       => BarangKeluar::STATUS_DISETUJUI,
        ]);

        $penjualan = PenjualanHarian::create([
            'cabang_id'        => $lokasi->id,
            'tanggal'          => $tanggal,
            'barang_keluar_id' => $dokumen->id,
            'dicatat_oleh'     => $pencatat?->id,
        ]);

        $this->isiPenjualan($penjualan, $dokumen, $pencatat, $porsi);

        return $penjualan;
    }

    private function gantiIsi(PenjualanHarian $penjualan, array $porsi, ?User $pencatat, bool $gantiPencatat = true): void
    {
        $dokumen = BarangKeluar::whereKey($penjualan->barang_keluar_id)->lockForUpdate()->firstOrFail();

        $this->balikPemakaian($dokumen, $pencatat);
        $dokumen->detail()->delete();

        if ($gantiPencatat && $pencatat !== null && $penjualan->dicatat_oleh !== $pencatat->id) {
            $penjualan->update(['dicatat_oleh' => $pencatat->id]);
        }

        $this->isiPenjualan($penjualan, $dokumen, $pencatat, $porsi);
    }

    /**
     * Mutasi positif sebesar pemakaian yang tercatat, supaya buku besar tetap utuh. Disahkan oleh pemanggil.
     */
    private function balikPemakaian(BarangKeluar $dokumen, ?User $oleh): void
    {
        $pembalik = $dokumen->detail()->get()->map(fn (BarangKeluarDetail $d) => [
            'cabang_id'         => $dokumen->cabang_id,
            'bahan_baku_id'     => $d->bahan_baku_id,
            'jumlah'            => (float) $d->qty,
            'dokumen_detail_id' => $d->id,
        ])->all();

        if ($pembalik !== []) {
            $this->stok->catat($pembalik, StokMutasi::JENIS_PAKAI_DAPUR, BarangKeluar::DOKUMEN_TIPE, $dokumen->id, $oleh?->id);
        }
    }

    private function isiPenjualan(PenjualanHarian $penjualan, BarangKeluar $dokumen, ?User $pencatat, array $porsi): void
    {
        $porsi = collect($porsi)->map(fn ($p) => (int) $p)->filter(fn (int $p) => $p > 0);

        if ($porsi->isEmpty()) {
            throw new DomainException('Isi jumlah porsi untuk minimal satu menu.');
        }

        $menu = Menu::query()->aktif()->whereKey($porsi->keys()->all())->get();
        $tanggal = $penjualan->tanggal->toDateString();
        $pemakaian = [];

        // Satu baris per menu (unik); baris lama dipakai ulang, menu yang tidak lagi terjual dihapus lunak.
        $penjualan->detail()->whereNotIn('menu_id', $menu->pluck('id'))->delete();

        foreach ($menu as $m) {
            $detail = PenjualanDetail::withTrashed()->firstOrNew([
                'penjualan_harian_id' => $penjualan->id,
                'menu_id'             => $m->id,
            ]);
            $detail->fill([
                'porsi'              => $porsi[$m->id],
                'takaran_resep_json' => PenjualanDetail::salinResep($m, $tanggal),
            ]);
            $detail->deleted_at = null;
            $detail->save();

            foreach ($detail->pemakaianBahan() as $bahanId => $jumlah) {
                $pemakaian[$bahanId] = round(($pemakaian[$bahanId] ?? 0) + $jumlah, 3);
            }
        }

        $mutasi = [];

        foreach ($pemakaian as $bahanId => $jumlah) {
            if ($jumlah <= 0) {
                continue;
            }

            $keluar = $dokumen->detail()->create([
                'bahan_baku_id' => $bahanId,
                'qty'           => $jumlah,
                'sebab'         => BarangKeluarDetail::SEBAB_DIPAKAI,
                'penjelasan'    => 'Pemakaian dari penjualan '.$penjualan->tanggal->translatedFormat('j M Y'),
            ]);

            $mutasi[] = [
                'cabang_id'         => $dokumen->cabang_id,
                'bahan_baku_id'     => $bahanId,
                'jumlah'            => -$jumlah,
                'dokumen_detail_id' => $keluar->id,
            ];
        }

        if ($mutasi !== []) {
            $this->stok->catat($mutasi, StokMutasi::JENIS_PAKAI_DAPUR, BarangKeluar::DOKUMEN_TIPE, $dokumen->id, $pencatat?->id);
        }

        $this->stok->sahkan(BarangKeluar::DOKUMEN_TIPE, $dokumen->id, bolehMinus: true);

        $this->beritahuBilaMinus($dokumen, array_keys($pemakaian));
    }

    /** Stok yang menjadi minus karena pemakaian adalah tanda stok sistem tidak cocok dengan fisik. */
    private function beritahuBilaMinus(BarangKeluar $dokumen, array $bahanIds): void
    {
        $minus = DB::table('stok_saldo')
            ->join('bahan_baku', 'bahan_baku.id', '=', 'stok_saldo.bahan_baku_id')
            ->where('stok_saldo.cabang_id', $dokumen->cabang_id)
            ->whereIn('stok_saldo.bahan_baku_id', $bahanIds)
            ->where('stok_saldo.saldo', '<', 0)
            ->orderBy('bahan_baku.nama')
            ->pluck('bahan_baku.nama');

        if ($minus->isEmpty()) {
            return;
        }

        $lokasi = $dokumen->cabang;

        $this->notifikasi->keAdminUtama(
            'Stok minus di '.$lokasi->nama,
            $minus->count().' bahan: '.$minus->implode(', ').'. Pemakaian dapur melebihi stok tercatat.',
            route('admin-utama.stok', ['lokasi' => $lokasi->id, 'status' => PantauanStokService::STATUS_MINUS], false)
        );
    }
}
