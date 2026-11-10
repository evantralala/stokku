<?php

namespace App\Services;

use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Opname;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Support\AturanWajar;
use Illuminate\Support\Collection;

/**
 * Antrean dokumen yang menunggu keputusan Admin Utama, dipakai halaman Persetujuan dan dashboard.
 * Setiap baris diberi hasil cek wajar (CekWajarService) di kunci 'cek'.
 */
class AntreanPersetujuanService
{
    /** [jenis => label] urut tampilan. */
    public const JENIS = [
        Permintaan::DOKUMEN_TIPE   => 'Permintaan',
        BarangMasuk::DOKUMEN_TIPE  => 'Barang Masuk',
        BarangKeluar::DOKUMEN_TIPE => 'Barang Rusak',
        Opname::DOKUMEN_TIPE       => 'Opname',
    ];

    /** Batas waktu yang tinggal sekian jam dianggap mendesak. */
    public const JAM_MENDESAK = 3;

    public function __construct(private CekWajarService $cekWajar)
    {
    }

    /**
     * @return Collection<int, array{jenis: string, id: int, kunci: string, nomor: string, lokasi: string, lokasi_id: int,
     *     diajukan: \Carbon\CarbonInterface, batas: ?\Carbon\CarbonInterface, mendesak: bool, otomatis: bool,
     *     keterangan: string, tautan: string, cek: array{wajar: bool, massal: bool, alasan: ?string}}>
     */
    public function daftar(string $jenis = ''): Collection
    {
        $antrean = collect()
            ->concat($jenis === '' || $jenis === Permintaan::DOKUMEN_TIPE ? $this->permintaan() : [])
            ->concat($jenis === '' || $jenis === BarangMasuk::DOKUMEN_TIPE ? $this->barangMasuk() : [])
            ->concat($jenis === '' || $jenis === BarangKeluar::DOKUMEN_TIPE ? $this->barangKeluar() : [])
            ->concat($jenis === '' || $jenis === Opname::DOKUMEN_TIPE ? $this->opname() : [])
            ->sortBy(fn (array $a) => [$a['mendesak'] ? 0 : 1, $a['batas']?->timestamp ?? PHP_INT_MAX, $a['diajukan']->timestamp])
            ->values();

        return $this->cekWajar->tandai($antrean);
    }

    /** Hanya dokumen wajar yang boleh disetujui sekaligus. */
    public function yangWajar(): Collection
    {
        return $this->daftar()->filter(fn (array $a) => $a['cek']['wajar'])->values();
    }

    /** Baris antrean yang mendesak: stok dikunci (opname) atau batas waktunya tinggal sedikit. */
    public static function mendesak(array $a): bool
    {
        return $a['mendesak'] || ($a['batas'] && $a['batas']->diffInHours(now(), true) < self::JAM_MENDESAK);
    }

    private function baris(string $jenis, $model, string $nomor, $lokasi, $diajukan, $batas, bool $mendesak, bool $otomatis, string $keterangan, string $tautan): array
    {
        return [
            'jenis'      => $jenis,
            'id'         => $model->id,
            'kunci'      => AturanWajar::kunci($jenis, $model->id),
            'nomor'      => $nomor,
            'lokasi'     => $lokasi->nama,
            'lokasi_id'  => $lokasi->id,
            'diajukan'   => $diajukan,
            'batas'      => $batas,
            'mendesak'   => $mendesak,
            'otomatis'   => $otomatis,
            'keterangan' => $keterangan,
            'tautan'     => $tautan,
        ];
    }

    private function permintaan(): Collection
    {
        return Permintaan::query()
            ->where('status', Permintaan::STATUS_MENUNGGU)
            ->whereHas('detail', fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU))
            ->withCount(['detail as menunggu_count' => fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU)])
            ->with('cabang:id,nama')
            ->get()
            ->map(fn (Permintaan $p) => $this->baris(
                Permintaan::DOKUMEN_TIPE, $p, $p->nomor, $p->cabang, $p->diajukan_pada, $p->batas_keputusan,
                false, $p->otomatis(),
                $p->menunggu_count.' barang', route('admin-utama.persetujuan.permintaan', $p),
            ));
    }

    private function barangMasuk(): Collection
    {
        return BarangMasuk::query()
            ->where('status', BarangMasuk::STATUS_MENUNGGU)
            ->withCount('detail')
            ->with('cabang:id,nama')
            ->get()
            ->map(fn (BarangMasuk $b) => $this->baris(
                BarangMasuk::DOKUMEN_TIPE, $b, $b->nomor, $b->cabang, $b->updated_at, null, false, false,
                $b->detail_count.' barang', route('admin-utama.persetujuan.barang-masuk', $b),
            ));
    }

    private function barangKeluar(): Collection
    {
        return BarangKeluar::query()
            ->rusak()
            ->where('status', BarangKeluar::STATUS_MENUNGGU)
            ->withCount('detail')
            ->with('cabang:id,nama')
            ->get()
            ->map(fn (BarangKeluar $b) => $this->baris(
                BarangKeluar::DOKUMEN_TIPE, $b, $b->nomor, $b->cabang, $b->updated_at, null, false, false,
                $b->detail_count.' barang', route('admin-utama.persetujuan.barang-keluar', $b),
            ));
    }

    private function opname(): Collection
    {
        return Opname::query()
            ->where('status', Opname::STATUS_DIKIRIM)
            ->withCount('detail')
            ->with('cabang:id,nama')
            ->get()
            ->map(fn (Opname $o) => $this->baris(
                Opname::DOKUMEN_TIPE, $o, $o->nomor, $o->cabang, $o->updated_at, null, true, false,
                $o->detail_count.' bahan · stok dikunci', route('admin-utama.persetujuan.opname', $o),
            ));
    }
}
