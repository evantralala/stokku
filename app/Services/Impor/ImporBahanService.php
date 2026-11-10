<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\BahanBakuRequest;
use App\Http\Requests\AdminUtama\KategoriRequest;
use App\Http\Requests\AdminUtama\SatuanRequest;
use App\Models\BahanBaku;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Satuan;
use App\Support\Angka;
use App\Support\ContohTemplat;
use App\Support\SatuanTunggal;
use Illuminate\Support\Str;

/**
 * Impor bahan baku dari Excel/CSV. Satu baris = satu bahan, dikenali dari namanya.
 * Satu kolom Satuan (mis. kg, liter, pcs); untuk kg dan liter resep memakai gram dan ml (SatuanTunggal).
 * File lama berkolom Satuan Dasar, Satuan Beli, dan Isi per Satuan Beli tetap terbaca.
 * Kategori dan satuan yang belum ada dibuat otomatis; bahan yang namanya sudah ada dilewati.
 */
class ImporBahanService extends ImporMaster
{
    private KumpulanMaster $kategori;
    private KumpulanMaster $satuan;
    private float $toleransiBawaan = 0;

    public function judul(): string
    {
        return 'Bahan baku';
    }

    protected function isiTemplat(): array
    {
        return array_map(fn (array $b) => [
            'nama' => $b[0], 'kategori' => $b[1], 'satuan' => $b[3],
        ], ContohTemplat::BAHAN);
    }

    public function kolom(): array
    {
        return [
            'nama'                => ['lebar' => 28, 'ket' => 'Wajib. Nama bahan, mis. Nori. Nama yang sudah ada dilewati.', 'contoh' => 'Beras Jepang'],
            'kategori'            => ['lebar' => 16, 'ket' => 'Wajib. Nama kategori, mis. Kering. Yang belum ada dibuat otomatis.', 'contoh' => 'Bahan Pokok'],
            'satuan'              => ['lebar' => 12, 'ket' => 'Wajib. Satuan stok, mis. kg, liter, pcs, botol. Untuk kg dan liter, resep ditulis dalam gram dan ml.', 'contoh' => 'kg'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['nama', 'kategori', ['satuan', 'satuan_dasar']];
    }

    /** Kolom file lama (tiga kolom satuan) dan toleransi tetap dibaca bila ada. */
    protected function kolomTambahan(): array
    {
        return ['satuan_dasar', 'satuan_beli', 'isi_per_satuan_beli', 'toleransi_persen'];
    }

    protected function jenisNama(): string
    {
        return 'nama:'.KamusImpor::BAHAN;
    }

    protected function maksNama(): int
    {
        return BahanBakuRequest::MAKS['nama'];
    }

    protected function barisEkspor(): array
    {
        return BahanBaku::query()
            ->with(['kategori:id,nama', 'satuanDasar:id,nama', 'satuanBeli:id,nama'])
            ->orderBy('nama')
            ->get()
            ->map(fn (BahanBaku $b) => [$b->nama, $b->kategori->nama, $b->satuanBeli->nama])
            ->all();
    }

    protected function namaAda(): array
    {
        return BahanBaku::query()->tampil()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function namaArsip(): array
    {
        return BahanBaku::query()->arsip()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function siapkan(): void
    {
        $this->kategori = new KumpulanMaster(Kategori::class, fn (string $nama) => Kategori::create([
            'nama'   => $nama,
            'urutan' => Kategori::urutanBerikutnya(),
            'aktif'  => true,
        ]));
        $this->satuan = new KumpulanMaster(Satuan::class, fn (string $nama) => Satuan::create(['nama' => $nama, 'aktif' => true]));
        $this->toleransiBawaan = (float) Pengaturan::ambil('toleransi_default', 0);
    }

    protected function periksaBaris(array $isi): array
    {
        $pesan = [];
        $catatan = [];

        $namaKategori = Str::squish((string) $isi['kategori']);
        $lama = filled($isi['satuan_dasar'] ?? null);

        if ($lama) {
            $namaDasar = Str::squish((string) $isi['satuan_dasar']);
            $namaBeli = Str::squish((string) ($isi['satuan_beli'] ?? '')) ?: $namaDasar;
            $isiBeli = filled($isi['isi_per_satuan_beli'] ?? null) ? Angka::baca($isi['isi_per_satuan_beli']) : 1;
        } else {
            $namaBeli = Str::squish((string) ($isi['satuan'] ?? ''));
            [$namaDasar, $isiBeli] = SatuanTunggal::turunan($namaBeli);
        }

        $toleransi = filled($isi['toleransi_persen'] ?? null) ? Angka::baca($isi['toleransi_persen']) : $this->toleransiBawaan;
        $sama = mb_strtolower($namaBeli) === mb_strtolower($namaDasar);

        $pesan = [...$pesan, ...$this->kategori->periksa($namaKategori, 'Kategori', KategoriRequest::MAKS_NAMA, $catatan)];
        $pesan = [...$pesan, ...$this->satuan->periksa($lama ? $namaDasar : $namaBeli, $lama ? 'Satuan dasar' : 'Satuan', SatuanRequest::MAKS_NAMA, $catatan, 'Satuan')];
        if (! $sama) {
            $pesan = [...$pesan, ...$this->satuan->periksa($lama ? $namaBeli : $namaDasar, $lama ? 'Satuan beli' : 'Satuan resep', SatuanRequest::MAKS_NAMA, $catatan, 'Satuan')];
        }

        if (! is_numeric($isiBeli) || $isiBeli <= 0 || $isiBeli > BahanBakuRequest::MAKS_ISI) {
            $pesan[] = 'Isi per satuan beli harus angka lebih dari 0.';
        } elseif ($sama && (float) $isiBeli !== 1.0) {
            $pesan[] = 'Satuan beli sama dengan satuan dasar, jadi isinya harus 1.';
        }

        if (! is_numeric($toleransi) || $toleransi < 0 || $toleransi > 100) {
            $pesan[] = 'Toleransi harus angka 0 sampai 100.';
        }

        return [
            'data' => [
                'kategori' => $namaKategori, 'satuan_dasar' => $namaDasar, 'satuan_beli' => $namaBeli,
                'isi_per_satuan_beli' => $isiBeli, 'toleransi_persen' => $toleransi,
            ],
            'pesan'   => $pesan,
            'catatan' => $catatan,
        ];
    }

    protected function simpanBaris(array $d): void
    {
        $this->buatAtauPulihkan(BahanBaku::class, [
            'nama'                => $d['nama'],
            'kategori_id'         => $this->kategori->ambil($d['kategori'])->id,
            'satuan_dasar_id'     => $this->satuan->ambil($d['satuan_dasar'])->id,
            'satuan_beli_id'      => $this->satuan->ambil($d['satuan_beli'])->id,
            'isi_per_satuan_beli' => $d['isi_per_satuan_beli'],
            'toleransi_persen'    => $d['toleransi_persen'],
            'aktif'               => true,
        ]);
    }

    protected function catatanSetelahSimpan(): array
    {
        return array_values(array_filter([
            $this->kategori->jumlahBaru() ? $this->kategori->jumlahBaru().' kategori baru' : null,
            $this->satuan->jumlahBaru() ? $this->satuan->jumlahBaru().' satuan baru' : null,
        ]));
    }

    public function ringkasan(array $data): array
    {
        $standar = SatuanTunggal::turunan($data['satuan_beli']) === [$data['satuan_dasar'], (float) $data['isi_per_satuan_beli']];
        $satuan = $data['satuan_beli'] !== $data['satuan_dasar'] && ! $standar && is_numeric($data['isi_per_satuan_beli'])
            ? '1 '.$data['satuan_beli'].' = '.Angka::tampil($data['isi_per_satuan_beli']).' '.$data['satuan_dasar']
            : $data['satuan_beli'];

        return [$data['nama'], collect([$data['kategori'], $satuan])->filter()->implode(' · ')];
    }
}
