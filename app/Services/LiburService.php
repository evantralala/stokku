<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\PenjualanHarian;
use App\Models\PenjualanLibur;
use App\Models\User;
use App\Support\Periode;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hari libur cabang. Tanggal yang ditandai libur tidak diingatkan sebagai "penjualan belum dicatat"
 * (dashboard cabang dan peringatan Admin Utama). Bila penjualan tanggal itu dicatat, tanda libur dihapus
 * (PenjualanService). Siapa yang boleh menandai diatur Admin Utama di Hak Akses (menu Libur).
 */
class LiburService
{
    public function __construct(private LogPerubahanService $log)
    {
    }

    /** Batas hari ke belakang: sama dengan penjualan cabang; Admin Utama sama dengan impor penjualannya. */
    public static function batasMundur(?User $oleh): int
    {
        return $oleh?->isAdminUtama() ? PenjualanHarian::BATAS_MUNDUR_IMPOR_HARI : PenjualanHarian::BATAS_MUNDUR_HARI;
    }

    /** @throws DomainException bila penjualan tanggal itu sudah dicatat atau periodenya sudah tutup buku */
    public function tandai(User $oleh, Cabang $lokasi, string $tanggal): PenjualanLibur
    {
        return DB::transaction(function () use ($oleh, $lokasi, $tanggal) {
            $lokasi = Cabang::whereKey($lokasi->id)->lockForUpdate()->firstOrFail();

            if ($lokasi->isPusat()) {
                throw new DomainException('Libur hanya untuk cabang.');
            }

            if (PenjualanHarian::query()->diLokasi($lokasi->id)->whereDate('tanggal', $tanggal)->exists()) {
                throw new DomainException('Penjualan tanggal ini sudah dicatat, jadi tidak bisa ditandai libur.');
            }

            if (app(PeriodeBukuService::class)->penjualanTertutup($lokasi->id, $tanggal)) {
                throw new DomainException(PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP);
            }

            $ada = PenjualanLibur::query()->diLokasi($lokasi->id)->whereDate('tanggal', $tanggal)->first();
            if ($ada) {
                return $ada;
            }

            $libur = PenjualanLibur::create(['cabang_id' => $lokasi->id, 'tanggal' => $tanggal, 'dicatat_oleh' => $oleh->id]);

            $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'libur.tandai',
                $lokasi->nama.' libur '.Carbon::parse($tanggal)->translatedFormat('j M Y').'.', [], $libur);

            return $libur;
        });
    }

    /** @throws DomainException bila periodenya sudah tutup buku */
    public function batal(User $oleh, PenjualanLibur $libur): void
    {
        if (app(PeriodeBukuService::class)->penjualanTertutup($libur->cabang_id, $libur->tanggal->toDateString())) {
            throw new DomainException(PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP);
        }

        $libur->loadMissing('cabang:id,nama');
        $libur->delete();

        $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'libur.batal',
            'Libur '.$libur->cabang->nama.' '.$libur->tanggal->translatedFormat('j M Y').' dibatalkan.', [], $libur);
    }

    /** Hapus tanda libur karena penjualan tanggal itu dicatat. Dipanggil di dalam transaksi pencatatan penjualan. */
    public function hapusKarenaBuka(int $cabangId, string $tanggal): void
    {
        PenjualanLibur::query()->diLokasi($cabangId)->whereDate('tanggal', $tanggal)->delete();
    }

    /**
     * Tanggal libur (Y-m-d) dalam rentang, per cabang.
     *
     * @param  list<int>  $cabangId
     * @return array<int, array<string, true>> [cabang_id => [Y-m-d => true]]
     */
    public function tanggalLibur(array $cabangId, string $dari, string $sampai): array
    {
        return PenjualanLibur::query()
            ->whereIn('cabang_id', $cabangId)
            ->whereDate('tanggal', '>=', $dari)->whereDate('tanggal', '<=', $sampai)
            ->get(['cabang_id', 'tanggal'])
            ->groupBy('cabang_id')
            ->map(fn (Collection $daftar) => $daftar->mapWithKeys(fn (PenjualanLibur $l) => [$l->tanggal->toDateString() => true])->all())
            ->all();
    }

    /** Hari ini (hari operasional) cabang ini libur? */
    public function libur(Cabang $lokasi, string $tanggal): bool
    {
        return PenjualanLibur::query()->diLokasi($lokasi->id)->whereDate('tanggal', $tanggal)->exists();
    }

    /** Jumlah hari libur dalam periode (satu cabang, atau semua cabang = jumlah hari-cabang). */
    public function jumlah(?Cabang $lokasi, Periode $periode): int
    {
        return PenjualanLibur::query()
            ->when($lokasi, fn ($q) => $q->diLokasi($lokasi->id))
            ->whereDate('tanggal', '>=', $periode->dariTanggal())
            ->whereDate('tanggal', '<=', $periode->sampaiTanggal())
            ->count();
    }

    /**
     * Baris daftar penjualan satu halaman ditambah hari libur yang tanggalnya jatuh di halaman itu, urut tanggal terbaru.
     * Rentang halaman: dari tanggal terakhir di halaman (atau tanpa batas bila halaman terakhir) sampai sebelum
     * tanggal terakhir halaman sebelumnya (atau tanpa batas bila halaman pertama).
     *
     * @return Collection<int, PenjualanHarian|PenjualanLibur>
     */
    public function sisipkan(LengthAwarePaginator $daftar, ?int $lokasiId): Collection
    {
        $penjualan = collect($daftar->items());
        $bawah = $daftar->hasMorePages() ? $penjualan->last()?->tanggal?->toDateString() : null;
        $atas = $daftar->onFirstPage() ? null : PenjualanHarian::query()
            ->when($lokasiId, fn ($q) => $q->diLokasi($lokasiId))
            ->orderByDesc('tanggal')->orderBy('cabang_id')
            ->skip(($daftar->currentPage() - 1) * $daftar->perPage() - 1)->take(1)
            ->value('tanggal');

        $libur = PenjualanLibur::query()
            ->when($lokasiId, fn ($q) => $q->diLokasi($lokasiId))
            ->when($bawah, fn ($q) => $q->whereDate('tanggal', '>=', $bawah))
            ->when($atas, fn ($q) => $q->whereDate('tanggal', '<', Carbon::parse($atas)->toDateString()))
            ->with(['cabang:id,nama', 'pencatat:id,nama'])
            ->get();

        return $penjualan->concat($libur)
            ->sortBy([fn ($a, $b) => $b->tanggal <=> $a->tanggal, fn ($a, $b) => $a->cabang_id <=> $b->cabang_id])
            ->values();
    }
}
