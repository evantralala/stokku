<?php

namespace App\Services;

use App\Mail\LaporanHarianMail;
use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\BarangMasukDetail;
use App\Models\Cabang;
use App\Models\EmailLaporan;
use App\Models\Kiriman;
use App\Models\User;
use App\Support\Angka;
use App\Support\BukuExcel;
use App\Support\DaftarLaporanEmail as Daftar;
use App\Support\Identitas;
use App\Support\Periode;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Email laporan harian (pukul 23.59, perintah laporan:kirim-harian) sesuai pengaturan di menu Akun tiap pengguna:
 * badan email berisi ringkasan, detailnya di satu file Excel terlampir (satu lembar per laporan).
 * Laporan cabang dan pusat memakai lokasi akun; laporan Admin Utama mencakup semua lokasi aktif.
 */
class LaporanHarianService
{
    private int $nomorTabel = 0;

    public function __construct(
        private EksporDaftarService $ekspor,
        private PantauanStokService $pantauan,
        private LaporanService $laporan,
        private AntreanPersetujuanService $antrean,
    ) {
    }

    /** Pengaturan akun; akun yang belum pernah mengatur mendapat centang bawaan perannya (belum aktif). */
    public function pengaturan(User $pengguna): EmailLaporan
    {
        return $pengguna->emailLaporan()->first() ?? new EmailLaporan([
            'user_id' => $pengguna->id,
            'aktif'   => false,
            'alamat'  => [$pengguna->email],
            'laporan' => Daftar::bawaan($pengguna->role),
        ]);
    }

    /** @param  array{aktif: bool, alamat: list<string>, laporan: list<string>}  $data */
    public function simpan(User $pengguna, array $data): EmailLaporan
    {
        return EmailLaporan::updateOrCreate(['user_id' => $pengguna->id], [
            'aktif'   => $data['aktif'],
            'alamat'  => array_values(array_unique($data['alamat'])),
            'laporan' => Daftar::saring($pengguna->role, $data['laporan']),
        ]);
    }

    /**
     * Kirim laporan semua akun aktif yang menyalakan email harian. Akun yang sudah dikirimi hari itu dilewati, kecuali $ulang.
     * Kegagalan satu akun dicatat di log dan tidak menghentikan akun lain.
     *
     * @return array{terkirim: int, gagal: int}
     */
    public function kirimSemua(?Carbon $tanggal = null, bool $ulang = false): array
    {
        $tanggal ??= now();
        $hasil = ['terkirim' => 0, 'gagal' => 0];

        $daftar = EmailLaporan::query()
            ->where('aktif', true)
            ->whereHas('user', fn ($q) => $q->where('aktif', true))
            ->with('user.cabang')
            ->get();

        foreach ($daftar as $pengaturan) {
            if (! $ulang && $pengaturan->terakhir_dikirim?->isSameDay($tanggal)) {
                continue;
            }

            try {
                $this->kirim($pengaturan->user, $pengaturan, $tanggal, true);
                $hasil['terkirim']++;
            } catch (Throwable $e) {
                $hasil['gagal']++;
                Log::error('Email laporan harian gagal untuk '.$pengaturan->user->email.': '.$e->getMessage());
            }
        }

        return $hasil;
    }

    /**
     * Kirim laporan satu akun. $catat = tandai tanggal laporan itu sudah terkirim (pengiriman terjadwal); tombol Kirim Sekarang
     * di menu Akun tidak menandai, jadi laporan malam itu tetap terkirim.
     */
    public function kirim(User $pengguna, ?EmailLaporan $pengaturan = null, ?Carbon $tanggal = null, bool $catat = false): void
    {
        $pengaturan ??= $this->pengaturan($pengguna);
        $tanggal ??= now();

        if ($pengaturan->alamat === []) {
            throw new DomainException('Isi minimal satu email tujuan dulu.');
        }

        $isi = $this->susun($pengguna, Daftar::saring($pengguna->role, $pengaturan->laporan ?? []), $tanggal);

        Mail::to($pengaturan->alamat)->send(new LaporanHarianMail($isi['subjek'], $isi['ringkasan'], $isi['lampiran'], $isi['nama_file']));

        if ($catat && $pengaturan->exists) {
            $pengaturan->forceFill(['terakhir_dikirim' => $tanggal->copy()])->save();
        }
    }

    /**
     * Isi email: subjek, ringkasan per laporan, dan isi file Excel.
     *
     * @param  list<string>  $laporan
     * @return array{subjek: string, ringkasan: list<array{judul: string, baris: list<string>}>, lampiran: string, nama_file: string}
     */
    public function susun(User $pengguna, array $laporan, Carbon $tanggal): array
    {
        if ($laporan === []) {
            throw new DomainException('Centang minimal satu laporan dulu.');
        }

        $lokasi = $pengguna->isAdminUtama() ? null : $pengguna->cabang;

        if (! $pengguna->isAdminUtama() && $lokasi === null) {
            throw new DomainException('Akun ini belum punya lokasi, jadi laporannya tidak bisa dibuat.');
        }

        $periode = Periode::antara($tanggal, $tanggal);
        $buku = BukuExcel::baru('Laporan Harian');
        $ringkasan = [];

        foreach ($laporan as $kunci) {
            $ringkasan[] = match ($kunci) {
                Daftar::STOK            => $this->stok($buku, $lokasi),
                Daftar::PENJUALAN       => $this->penjualan($buku, $lokasi, $periode),
                Daftar::DITERIMA        => $this->kiriman($buku, $lokasi, $periode, true),
                Daftar::KIRIMAN         => $this->kirimanPusat($buku, $lokasi, $periode),
                Daftar::MASUK           => $this->barangMasuk($buku, $lokasi, $periode),
                Daftar::RUSAK           => $this->rusak($buku, $lokasi, $periode),
                Daftar::MENIPIS         => $this->menipis($buku),
                Daftar::PENJUALAN_SEMUA => $this->penjualanSemua($buku, $periode),
                Daftar::MENUNGGU        => $this->menunggu($buku),
                Daftar::STOK_SEMUA      => $this->stokSemua($buku),
                Daftar::RUSAK_OPNAME    => $this->rusakOpname($buku, $periode),
            };
        }

        $buku->setActiveSheetIndex(0);
        $tempat = $lokasi?->nama ?? 'Semua Lokasi';
        $hari = $tanggal->translatedFormat('j F Y');

        return [
            'subjek'    => 'Laporan Harian '.$tempat.' · '.$hari.' · '.Identitas::aplikasi(),
            'ringkasan' => $ringkasan,
            'lampiran'  => $this->isiBerkas($buku),
            'nama_file' => Str::slug('laporan harian '.($lokasi?->kode ?? 'semua').' '.$tanggal->toDateString()).'.xlsx',
        ];
    }

    private function stok(Spreadsheet $buku, Cabang $lokasi): array
    {
        $this->salinLembar($buku, $this->ekspor->stok($lokasi), 'Stok');
        $lampu = PantauanStokService::hitungPerLampu($this->pantauan->daftar($lokasi));

        return ['judul' => 'Stok '.$lokasi->nama, 'baris' => [
            ($lampu[PantauanStokService::LAMPU_MERAH] ?? 0).' habis/minus · '.($lampu[PantauanStokService::LAMPU_KUNING] ?? 0)
                .' menipis · '.($lampu[PantauanStokService::LAMPU_HIJAU] ?? 0).' aman',
        ]];
    }

    private function penjualan(Spreadsheet $buku, Cabang $lokasi, Periode $periode): array
    {
        $this->salinLembar($buku, $this->ekspor->penjualan($lokasi, $periode), 'Penjualan');
        $menu = $this->laporan->penjualanMenu($lokasi, $periode);

        return ['judul' => 'Penjualan '.$lokasi->nama, 'baris' => [
            $menu->isEmpty() ? 'Belum ada penjualan tercatat.' : $menu->sum('porsi').' porsi dari '.$menu->count().' menu',
        ]];
    }

    private function kiriman(Spreadsheet $buku, Cabang $lokasi, Periode $periode, bool $masuk): array
    {
        $this->salinLembar($buku, $this->ekspor->kiriman($lokasi, $periode, $masuk), $masuk ? 'Kiriman Masuk' : 'Kiriman Keluar');

        $jumlah = Kiriman::query()
            ->when($masuk, fn ($q) => $q->ke($lokasi->id), fn ($q) => $q->dari($lokasi->id))
            ->where('status', '!=', Kiriman::STATUS_DISIAPKAN)
            ->whereBetween('dikirim_pada', [$periode->dari, $periode->sampai])
            ->count();

        return ['judul' => $masuk ? 'Barang diterima' : 'Kiriman keluar', 'baris' => [$jumlah.' kiriman']];
    }

    private function kirimanPusat(Spreadsheet $buku, Cabang $lokasi, Periode $periode): array
    {
        $keluar = $this->kiriman($buku, $lokasi, $periode, false);
        $masuk = $this->kiriman($buku, $lokasi, $periode, true);

        return ['judul' => 'Kiriman', 'baris' => ['Keluar: '.$keluar['baris'][0], 'Masuk: '.$masuk['baris'][0]]];
    }

    private function barangMasuk(Spreadsheet $buku, Cabang $lokasi, Periode $periode): array
    {
        $detail = BarangMasukDetail::query()
            ->whereHas('barangMasuk', fn ($q) => $q->diLokasi($lokasi->id)
                ->whereDate('tanggal', '>=', $periode->dariTanggal())->whereDate('tanggal', '<=', $periode->sampaiTanggal()))
            ->with(['barangMasuk:id,nomor,tanggal,status', 'bahanBaku.satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (BarangMasukDetail $d) => [$d->barangMasuk->nomor, $d->id])
            ->values();

        $sumber = BukuExcel::baru('Barang Masuk');
        BukuExcel::lembar($sumber, 'Barang Masuk', 'Barang Masuk '.$lokasi->nama, 'Periode: '.$periode->label(),
            ['Tanggal' => BukuExcel::TANGGAL, 'Nomor' => null, 'Bahan' => null, 'Satuan' => null, 'Jumlah' => BukuExcel::JUMLAH,
                'Keterangan' => null, 'Status' => null],
            $detail->map(fn (BarangMasukDetail $d) => [
                $d->barangMasuk->tanggal->copy(), $d->barangMasuk->nomor, $d->bahanBaku->nama, $d->bahanBaku->satuanBeli->nama,
                $d->bahanBaku->keSatuanBeli((float) $d->qty), $d->keterangan, $d->barangMasuk->label_status,
            ]));
        $this->salinLembar($buku, $sumber, 'Barang Masuk');

        $dokumen = $detail->pluck('barang_masuk_id')->unique()->count();

        return ['judul' => 'Barang masuk', 'baris' => [$dokumen.' dokumen, '.$detail->count().' baris barang']];
    }

    private function rusak(Spreadsheet $buku, Cabang $lokasi, Periode $periode): array
    {
        $this->salinLembar($buku, $this->ekspor->barangRusak($lokasi, $periode), 'Barang Rusak');

        $jumlah = BarangKeluar::query()->diLokasi($lokasi->id)->rusak()
            ->whereDate('tanggal', '>=', $periode->dariTanggal())->whereDate('tanggal', '<=', $periode->sampaiTanggal())
            ->where('status', '!=', BarangKeluar::STATUS_BATAL)
            ->count();

        return ['judul' => 'Barang rusak', 'baris' => [$jumlah.' laporan']];
    }

    private function menipis(Spreadsheet $buku): array
    {
        $baris = collect();
        $ringkas = [];

        foreach ($this->lokasiAktif() as $lokasi) {
            $daftar = $this->pantauan->daftar($lokasi)->filter(fn (array $r) => $r['lampu'] !== PantauanStokService::LAMPU_HIJAU);
            $lampu = PantauanStokService::hitungPerLampu($daftar);
            $ringkas[] = $lokasi->nama.': '.($lampu[PantauanStokService::LAMPU_MERAH] ?? 0).' habis/minus, '
                .($lampu[PantauanStokService::LAMPU_KUNING] ?? 0).' menipis';

            foreach ($daftar->sortBy(fn (array $r) => [$r['lampu'] === PantauanStokService::LAMPU_MERAH ? 0 : 1, $r['bahan']->nama]) as $r) {
                $b = $r['bahan'];
                $baris->push([
                    $lokasi->nama, $b->kategori->nama, $b->nama, $b->satuanBeli->nama, $b->keSatuanBeli($r['saldo']),
                    $r['minimum'] > 0 ? $b->keSatuanBeli($r['minimum']) : null, PantauanStokService::teks($r['status']),
                ]);
            }
        }

        $sumber = BukuExcel::baru('Stok Menipis');
        BukuExcel::lembar($sumber, 'Stok Menipis', 'Stok Menipis & Habis', 'Semua lokasi aktif',
            ['Lokasi' => null, 'Kategori' => null, 'Bahan' => null, 'Satuan' => null, 'Stok' => BukuExcel::JUMLAH,
                'Minimum' => BukuExcel::JUMLAH, 'Status' => null],
            $baris, teksKosong: 'Semua stok aman.');
        $this->salinLembar($buku, $sumber, 'Stok Menipis');

        return ['judul' => 'Stok menipis & habis', 'baris' => $ringkas === [] ? ['Belum ada lokasi aktif.'] : $ringkas];
    }

    private function penjualanSemua(Spreadsheet $buku, Periode $periode): array
    {
        $baris = collect();
        $ringkas = [];

        foreach ($this->lokasiAktif()->where('jenis', Cabang::JENIS_CABANG) as $lokasi) {
            $menu = $this->laporan->penjualanMenu($lokasi, $periode);
            $ringkas[] = $lokasi->nama.': '.($menu->isEmpty() ? 'belum ada penjualan' : $menu->sum('porsi').' porsi');
            $menu->each(fn ($m) => $baris->push([$lokasi->nama, $m->kategori, $m->nama, $m->porsi]));
        }

        $sumber = BukuExcel::baru('Penjualan');
        BukuExcel::lembar($sumber, 'Penjualan', 'Penjualan Semua Cabang', 'Periode: '.$periode->label(),
            ['Cabang' => null, 'Kategori Menu' => null, 'Menu' => null, 'Porsi' => BukuExcel::BULAT],
            $baris, ['Porsi'], 'Belum ada penjualan tercatat.');
        $this->salinLembar($buku, $sumber, 'Penjualan');

        return ['judul' => 'Penjualan semua cabang', 'baris' => $ringkas === [] ? ['Belum ada cabang aktif.'] : $ringkas];
    }

    private function menunggu(Spreadsheet $buku): array
    {
        $antrean = $this->antrean->daftar();

        $sumber = BukuExcel::baru('Menunggu Persetujuan');
        BukuExcel::lembar($sumber, 'Menunggu', 'Menunggu Persetujuan', 'Saat laporan dibuat',
            ['Jenis' => null, 'Nomor' => null, 'Lokasi' => null, 'Diajukan' => BukuExcel::WAKTU, 'Batas' => BukuExcel::WAKTU,
                'Mendesak' => null, 'Keterangan' => null],
            $antrean->map(fn (array $a) => [
                AntreanPersetujuanService::JENIS[$a['jenis']] ?? $a['jenis'], $a['nomor'], $a['lokasi'], $a['diajukan']->copy(),
                $a['batas']?->copy(), $a['mendesak'] ? 'Ya' : null, $a['keterangan'],
            ]), teksKosong: 'Tidak ada yang menunggu persetujuan.');
        $this->salinLembar($buku, $sumber, 'Menunggu');

        $mendesak = $antrean->where('mendesak', true)->count();

        return ['judul' => 'Menunggu persetujuan', 'baris' => [
            $antrean->count().' dokumen'.($mendesak > 0 ? ', '.$mendesak.' mendesak' : ''),
        ]];
    }

    private function stokSemua(Spreadsheet $buku): array
    {
        $lokasi = $this->lokasiAktif();

        foreach ($lokasi as $l) {
            $this->salinLembar($buku, $this->ekspor->stok($l), 'Stok '.$l->kode);
        }

        return ['judul' => 'Stok lengkap', 'baris' => [$lokasi->count().' lokasi, satu lembar per lokasi di lampiran']];
    }

    private function rusakOpname(Spreadsheet $buku, Periode $periode): array
    {
        $rusak = $this->laporan->barangRusak(null, $periode)['per_bahan'];
        $opname = $this->laporan->selisihOpname(null, $periode);

        $sumber = BukuExcel::baru('Rusak & Opname');
        BukuExcel::lembar($sumber, 'Barang Rusak', 'Barang Rusak Disetujui', 'Periode: '.$periode->label(),
            ['Bahan' => null, 'Satuan' => null, 'Berapa Kali' => BukuExcel::BULAT, 'Jumlah Terbuang' => BukuExcel::JUMLAH],
            $rusak->map(fn ($r) => [$r->bahan, $r->satuan, (int) $r->kali, (float) $r->qty / max((float) $r->isi, 0.000001)]),
            teksKosong: 'Tidak ada barang rusak yang disetujui.');
        BukuExcel::lembar($sumber, 'Selisih Opname', 'Selisih Opname Disetujui', 'Periode: '.$periode->label(),
            ['Nomor' => null, 'Lokasi' => null, 'Bahan' => null, 'Satuan' => null, 'Stok Sistem' => BukuExcel::JUMLAH,
                'Hitung Fisik' => BukuExcel::JUMLAH, 'Selisih' => BukuExcel::JUMLAH, 'Catatan' => null],
            $opname->map(fn ($r) => [
                $r->nomor, $r->lokasi, $r->bahan, $r->satuan, $r->sistem / $r->isi, $r->fisik / $r->isi, ($r->fisik - $r->sistem) / $r->isi, $r->catatan,
            ]), teksKosong: 'Tidak ada selisih opname yang disetujui.');
        $this->salinLembar($buku, $sumber, 'Barang Rusak');
        $this->salinLembar($buku, $sumber, 'Selisih Opname');

        return ['judul' => 'Barang rusak & selisih opname', 'baris' => [
            $rusak->count().' bahan rusak · '.$opname->count().' bahan berselisih',
        ]];
    }

    /** @return Collection<int, Cabang> lokasi aktif, Gudang Pusat lebih dulu */
    private function lokasiAktif(): Collection
    {
        return Cabang::query()->aktif()->get()
            ->sortBy(fn (Cabang $c) => [$c->jenis === Cabang::JENIS_PUSAT ? 0 : 1, $c->nama])
            ->values();
    }

    /** Pindahkan lembar pertama (atau lembar bernama) dari buku sumber ke buku laporan dengan nama unik. */
    private function salinLembar(Spreadsheet $buku, Spreadsheet $sumber, string $nama): void
    {
        $lembar = $sumber->getSheetByName($nama) ?? $sumber->getSheet(0);
        $judul = Str::limit($nama, 31, '');
        $n = 2;

        while ($buku->sheetNameExists($judul)) {
            $judul = Str::limit($nama, 27, '').' '.$n++;
        }

        $lembar->setTitle($judul);
        $buku->addExternalSheet($lembar);

        // Nama tabel Excel harus unik dalam satu buku; tiap buku sumber memberi nama Tabel1, Tabel2, ...
        foreach ($lembar->getTableCollection() as $tabel) {
            $tabel->setName('Laporan'.(++$this->nomorTabel));
        }
    }

    private function isiBerkas(Spreadsheet $buku): string
    {
        $berkas = tempnam(sys_get_temp_dir(), 'laporan');

        try {
            (new Xlsx($buku))->save($berkas);

            return (string) file_get_contents($berkas);
        } finally {
            @unlink($berkas);
        }
    }
}
