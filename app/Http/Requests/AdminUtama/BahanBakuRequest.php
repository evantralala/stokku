<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AturanFoto;
use App\Http\Requests\DataMasterRequest;
use App\Models\Satuan;
use App\Support\Angka;
use App\Support\SatuanTunggal;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class BahanBakuRequest extends DataMasterRequest
{
    use AturanFoto;

    public const MAKS = [
        'nama' => 100,
    ];

    /** Batas kolom decimal(12,3). */
    public const MAKS_ISI = 999999999;

    /**
     * Form mengirim satu satuan (satuan_id); satuan dasar, satuan beli, dan isinya diturunkan dari situ (lihat
     * SatuanTunggal). Bila satuannya tidak diganti, susunan satuan bahan yang sudah ada dipertahankan.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nama_lain'           => is_string($this->input('nama_lain')) || $this->input('nama_lain') === null
                ? (implode(', ', \App\Models\BahanBaku::pecahNamaLain($this->input('nama_lain'))) ?: null)
                : $this->input('nama_lain'),
            'isi_per_satuan_beli' => Angka::baca($this->input('isi_per_satuan_beli')),
            'toleransi_persen'    => Angka::baca($this->input('toleransi_persen')),
        ]);

        $satuan = is_numeric($this->input('satuan_id')) ? Satuan::find((int) $this->input('satuan_id')) : null;

        if ($satuan === null) {
            return;
        }

        $bahan = $this->dataYangDiubah();

        $this->merge($bahan && (int) $bahan->satuan_beli_id === $satuan->id
            ? $bahan->only(['satuan_dasar_id', 'satuan_beli_id', 'isi_per_satuan_beli'])
            : SatuanTunggal::untuk($satuan));
    }

    public function dataTanpaFoto(): array
    {
        return collect($this->validated())->except(['foto', 'hapus_foto', 'satuan_id'])->all();
    }

    public function rules(): array
    {
        return [
            'nama'            => ['required', 'string', 'max:'.self::MAKS['nama'], $this->unik('bahan_baku')],
            'nama_lain'       => ['nullable', 'string', 'max:500'],
            'kategori_id'     => ['required', 'integer', $this->aktifAtauMiliknya('kategori', 'kategori_id')],
            'satuan_id'       => [
                'nullable', 'required_without:satuan_dasar_id', 'integer', $this->aktifAtauMiliknya('satuan', 'satuan_beli_id'),
                $this->satuanSejenisBilaSudahDipakai(...),
            ],
            'satuan_dasar_id' => [
                'required', 'integer', $this->aktifAtauMiliknya('satuan', 'satuan_dasar_id'),
                $this->satuanDasarTidakBerubahBilaSudahDipakai(...),
            ],
            'satuan_beli_id'      => ['required', 'integer', $this->aktifAtauMiliknya('satuan', 'satuan_beli_id')],
            'isi_per_satuan_beli' => [
                'required', 'numeric', 'gt:0', 'max:'.self::MAKS_ISI,
                $this->isiSatuBilaSatuanSama(...),
            ],
            'toleransi_persen'    => ['required', 'numeric', 'between:0,100'],
        ] + $this->aturanFoto();
    }

    /** Pilihan harus aktif, kecuali nilai yang sudah tersimpan di bahan ini (supaya data lama tetap bisa disimpan). */
    private function aktifAtauMiliknya(string $tabel, string $kolom): Exists
    {
        $nilaiSekarang = $this->dataYangDiubah()?->getAttribute($kolom);

        return Rule::exists($tabel, 'id')->where(function ($q) use ($nilaiSekarang) {
            $q->where('aktif', true)->when($nilaiSekarang, fn ($q) => $q->orWhere('id', $nilaiSekarang));
        });
    }

    private function satuanDasarTidakBerubahBilaSudahDipakai(string $atribut, mixed $nilai, Closure $gagal): void
    {
        $bahan = $this->dataYangDiubah();

        if ($this->filled('satuan_id')) {
            return;
        }

        if ($bahan && (int) $nilai !== (int) $bahan->satuan_dasar_id && $bahan->sudahDipakai()) {
            $gagal('Satuan dasar tidak bisa diubah karena bahan ini sudah punya stok atau transaksi.');
        }
    }

    /** Bahan yang sudah dipakai hanya boleh ganti ke satuan sejenis (mis. kg dan gram), supaya angka stoknya tetap benar. */
    private function satuanSejenisBilaSudahDipakai(string $atribut, mixed $nilai, Closure $gagal): void
    {
        $bahan = $this->dataYangDiubah();

        if ($bahan && (int) $this->input('satuan_dasar_id') !== (int) $bahan->satuan_dasar_id && $bahan->sudahDipakai()) {
            $gagal('Bahan ini sudah punya stok atau transaksi, jadi satuannya hanya bisa diganti ke satuan sejenis (mis. kg ke gram).');
        }
    }

    private function isiSatuBilaSatuanSama(string $atribut, mixed $nilai, Closure $gagal): void
    {
        $samaSatuan = (int) $this->input('satuan_beli_id') === (int) $this->input('satuan_dasar_id');

        if ($samaSatuan && (float) $nilai !== 1.0) {
            $gagal('Satuan beli sama dengan satuan dasar, jadi isinya harus 1.');
        }
    }

    public function messages(): array
    {
        return [
            'nama.unique'              => 'Bahan dengan nama ini sudah ada. Periksa daftar bahan baku.',
            'isi_per_satuan_beli.gt'   => 'Isi per satuan beli harus lebih dari 0.',
            'toleransi_persen.between' => 'Toleransi harus antara 0 sampai 100 persen.',
        ] + $this->pesanFoto();
    }

    public function attributes(): array
    {
        return [
            'nama'                => 'nama bahan',
            'kategori_id'         => 'kategori',
            'satuan_id'           => 'satuan',
            'satuan_dasar_id'     => 'satuan dasar',
            'satuan_beli_id'      => 'satuan beli',
            'isi_per_satuan_beli' => 'isi per satuan beli',
            'toleransi_persen'    => 'toleransi selisih',
        ];
    }
}
