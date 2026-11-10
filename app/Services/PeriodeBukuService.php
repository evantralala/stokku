<?php

namespace App\Services;

use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Kiriman;
use App\Models\LogPerubahan;
use App\Models\Opname;
use App\Models\PenjualanHarian;
use App\Models\PeriodeBuku;
use App\Models\Permintaan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\Angka;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Tutup buku (Mulai Periode Baru): transaksi periode berjalan diarsipkan ke Excel + cadangan database,
 * lalu dihapus. Data master, akun, pengaturan, dan log perubahan tetap. Stok terakhir tiap lokasi menjadi
 * stok awal periode baru (mutasi stok_awal yang langsung sah, lewat StokService).
 *
 * Hanya bisa bila tidak ada dokumen yang masih berjalan (persetujuan, permintaan, kiriman di jalan, opname,
 * dokumen dikembalikan), supaya tidak ada dokumen yang terputus di tengah jalan.
 * Setelah ditutup, transaksi bertanggal sebelum hari penutupan ditolak, dan penjualan cabang pada tanggal
 * yang sudah ikut diarsipkan tidak bisa dicatat lagi (stoknya sudah berkurang).
 */
class PeriodeBukuService
{
    public const KATA_KONFIRMASI = 'TUTUP BUKU';

    public const PESAN_TANGGAL_TERTUTUP = 'Tanggal ini sudah masuk periode buku yang ditutup. Pilih tanggal mulai hari tutup buku.';

    public const PESAN_PENJUALAN_TERTUTUP = 'Penjualan tanggal ini sudah tercatat di periode buku yang ditutup.';

    public const PESAN_BELUM_TERPASANG = 'Periode Buku belum terpasang di server. Hubungi pengelola sistem.';

    private const KUNCI_CACHE = 'periode-buku-terakhir';

    private ?bool $siap = null;

    public function __construct(
        private PengosongTransaksiService $pengosong,
        private CadanganService $cadangan,
        private ArsipPeriodeService $arsip,
        private StokService $stok,
        private AntreanPersetujuanService $antrean,
        private LogPerubahanService $log,
    ) {}

    /** @return list<string> alasan tutup buku belum bisa dilakukan; kosong = siap */
    public function penghalang(): array
    {
        $alasan = [];
        $hitung = [
            'dokumen menunggu persetujuan'       => $this->antrean->daftar()->count(),
            'permintaan belum selesai'           => Permintaan::query()->where('status', Permintaan::STATUS_MENUNGGU)->count(),
            'kiriman belum diterima cabang'      => Kiriman::query()->whereIn('status', [Kiriman::STATUS_DISIAPKAN, Kiriman::STATUS_DIKIRIM])->count(),
            'opname belum selesai'               => Opname::query()->belumSelesai()->count(),
            'dokumen dikembalikan untuk diperbaiki' => BarangMasuk::query()->where('status', BarangMasuk::STATUS_REVISI)->count()
                + BarangKeluar::query()->where('status', BarangKeluar::STATUS_REVISI)->count(),
        ];

        foreach ($hitung as $label => $n) {
            if ($n > 0) {
                $alasan[] = Angka::tampil($n).' '.$label;
            }
        }

        if ($alasan === [] && StokMutasi::query()->menunggu()->exists()) {
            $alasan[] = 'Masih ada perubahan stok yang belum disahkan';
        }

        return $alasan;
    }

    /**
     * Tabel periode_buku sudah ada. Bila migration belum dijalankan (mis. tepat setelah pembaruan kode),
     * Pengaturan dan transaksi tetap jalan seperti belum pernah tutup buku.
     */
    public function siap(): bool
    {
        return $this->siap ??= Schema::hasTable((new PeriodeBuku)->getTable());
    }

    public function terakhir(): ?PeriodeBuku
    {
        if (! $this->siap()) {
            return null;
        }

        return Cache::rememberForever(self::KUNCI_CACHE, fn () => PeriodeBuku::query()->latest('selesai')->latest('id')->first() ?? false) ?: null;
    }

    /** @return Collection<int, PeriodeBuku> terbaru dulu */
    public function daftar(): Collection
    {
        if (! $this->siap()) {
            return new Collection;
        }

        return PeriodeBuku::query()->with('penutup:id,nama')->latest('selesai')->latest('id')->get();
    }

    /** Awal periode berjalan: saat tutup buku terakhir, atau transaksi pertama bila belum pernah tutup buku. */
    public function mulaiBerjalan(): ?Carbon
    {
        $terakhir = $this->terakhir();

        if ($terakhir) {
            return $terakhir->selesai->copy();
        }

        $pertama = StokMutasi::query()->min('dibuat_pada');

        return $pertama ? Carbon::parse($pertama) : null;
    }

    /** Usul nama periode, mis. "Oktober 2026" atau "Sep – Okt 2026". */
    public function usulNama(): string
    {
        $mulai = $this->mulaiBerjalan() ?? now();
        $selesai = now();

        if ($mulai->isSameMonth($selesai)) {
            return $selesai->translatedFormat('F Y');
        }

        return $mulai->isSameYear($selesai)
            ? $mulai->translatedFormat('M').' – '.$selesai->translatedFormat('M Y')
            : $mulai->translatedFormat('M Y').' – '.$selesai->translatedFormat('M Y');
    }

    /** Tanggal transaksi (Y-m-d) sebelum hari tutup buku terakhir sudah masuk periode yang ditutup. */
    public function tanggalTertutup(string $tanggal): bool
    {
        $terakhir = $this->terakhir();

        return $terakhir !== null && $tanggal < $terakhir->selesai->toDateString();
    }

    /** Penjualan cabang pada tanggal ini sudah ikut diarsipkan (atau tanggalnya sebelum tutup buku). */
    public function penjualanTertutup(?int $cabangId, string $tanggal): bool
    {
        if ($this->tanggalTertutup($tanggal)) {
            return true;
        }

        $terakhir = $this->terakhir()?->penjualan_terakhir[(string) $cabangId] ?? null;

        return $terakhir !== null && $tanggal <= $terakhir;
    }

    public function tutup(string $nama, User $oleh): PeriodeBuku
    {
        $penghalang = $this->penghalang();

        if ($penghalang !== []) {
            throw new DomainException('Selesaikan dulu: '.mb_strtolower(implode(', ', $penghalang)).'.');
        }

        $nama = Str::squish($nama);
        $mulai = $this->mulaiBerjalan();
        $selesai = now();
        $ringkasan = $this->ringkasan();
        $saldo = DB::table('stok_saldo')->where('saldo', '!=', 0)->orderBy('cabang_id')->orderBy('bahan_baku_id')->get(['cabang_id', 'bahan_baku_id', 'saldo']);

        $periode = PeriodeBuku::create([
            'nama'               => $nama,
            'mulai'              => $mulai,
            'selesai'            => $selesai,
            'ringkasan'          => $ringkasan,
            'penjualan_terakhir' => PenjualanHarian::withTrashed()->groupBy('cabang_id')->selectRaw('cabang_id, MAX(tanggal) AS akhir')
                ->pluck('akhir', 'cabang_id')->map(fn ($t) => Carbon::parse($t)->toDateString())->mapWithKeys(fn ($t, $id) => [(string) $id => $t])->all(),
            'ditutup_oleh'       => $oleh->id,
        ]);

        try {
            $periode->update($this->simpanArsip($periode, $ringkasan, $oleh));
        } catch (\Throwable $e) {
            $this->hapusBerkas($periode);
            $periode->delete();

            throw $e;
        }

        $this->pengosong->kosongkan();

        if ($saldo->isNotEmpty()) {
            $this->stok->catat(
                $saldo->map(fn ($s) => ['cabang_id' => $s->cabang_id, 'bahan_baku_id' => $s->bahan_baku_id, 'jumlah' => $s->saldo]),
                StokMutasi::JENIS_STOK_AWAL,
                PeriodeBuku::DOKUMEN_TIPE,
                $periode->id,
                $oleh->id
            );
            $this->stok->sahkan(PeriodeBuku::DOKUMEN_TIPE, $periode->id, bolehMinus: true);
        }

        Cache::flush();

        $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'periode.tutup',
            'Tutup buku periode '.$nama.' ('.$periode->rentang().'). Arsip: '.$periode->berkas_excel.', '.$periode->berkas_cadangan.'.');

        return $periode->fresh();
    }

    /** Jalur lengkap berkas arsip ('excel' atau 'cadangan'), atau null bila tidak ada. */
    public function jalur(PeriodeBuku $periode, string $jenis): ?string
    {
        $nama = match ($jenis) {
            'excel'    => $periode->berkas_excel,
            'cadangan' => $periode->berkas_cadangan,
            default    => null,
        };
        $relatif = PeriodeBuku::FOLDER.'/'.$nama;

        return $nama && Storage::disk(PeriodeBuku::DISK)->exists($relatif) ? Storage::disk(PeriodeBuku::DISK)->path($relatif) : null;
    }

    public function catatUnduh(User $oleh, PeriodeBuku $periode, string $jenis): void
    {
        $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'periode.unduh',
            'Arsip periode '.$periode->nama.' diunduh ('.($jenis === 'excel' ? 'Excel' : 'cadangan database').').');
    }

    /** @return array<string, int> [label => jumlah] */
    private function ringkasan(): array
    {
        return [
            'Catatan masuk & keluar stok' => StokMutasi::query()->sah()->count(),
            'Hari penjualan (per cabang)' => PenjualanHarian::query()->count(),
            'Barang masuk'                => BarangMasuk::query()->count(),
            'Barang rusak & pemakaian'    => BarangKeluar::query()->count(),
            'Permintaan'                  => Permintaan::query()->count(),
            'Kiriman'                     => Kiriman::query()->count(),
            'Stock opname'                => Opname::query()->count(),
        ];
    }

    /** @return array{berkas_excel: string, berkas_cadangan: string} */
    private function simpanArsip(PeriodeBuku $periode, array $ringkasan, User $oleh): array
    {
        $disk = Storage::disk(PeriodeBuku::DISK);
        $disk->makeDirectory(PeriodeBuku::FOLDER);
        $dasar = 'periode-'.$periode->id.'-'.(Str::slug($periode->nama) ?: 'tanpa-nama');

        $excel = $dasar.'.xlsx';
        (new Xlsx($this->arsip->buat($periode->nama, $periode->mulai, $periode->selesai, $ringkasan, $oleh)))
            ->save($disk->path(PeriodeBuku::FOLDER.'/'.$excel));

        $namaCadangan = $this->cadangan->buat($oleh);
        $cadangan = $dasar.'.sql.gz';
        $disk->copy(CadanganService::FOLDER.'/'.$namaCadangan, PeriodeBuku::FOLDER.'/'.$cadangan);

        return ['berkas_excel' => $excel, 'berkas_cadangan' => $cadangan];
    }

    private function hapusBerkas(PeriodeBuku $periode): void
    {
        $disk = Storage::disk(PeriodeBuku::DISK);

        foreach ($disk->files(PeriodeBuku::FOLDER) as $f) {
            if (str_starts_with(basename($f), 'periode-'.$periode->id.'-')) {
                $disk->delete($f);
            }
        }
    }
}
