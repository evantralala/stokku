<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\IsianAman;
use App\Http\Requests\DataMasterRequest;
use App\Models\Cabang;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LokasiRequest extends DataMasterRequest
{
    use IsianAman;

    public const MAKS = [
        'kode' => 10,
        'nama' => 100,
        'alamat' => 160,
    ];

    /** Kode disimpan huruf besar tanpa spasi: " jti 1" -> "JTI1". */
    protected function prepareForValidation(): void
    {
        if ($this->has('kode')) {
            $this->merge(['kode' => Str::upper(preg_replace('/\s+/', '', $this->teks('kode')))]);
        }
    }

    public function rules(): array
    {
        return [
            'kode' => [
                'required', 'string', 'max:'.self::MAKS['kode'], 'regex:/^[A-Z0-9-]+$/',
                $this->unik('cabang', 'kode'),
            ],
            'nama' => ['required', 'string', 'max:'.self::MAKS['nama'], $this->unik('cabang')],
            'jenis' => [
                'required',
                Rule::in(array_keys(Cabang::LABEL_JENIS)),
                $this->jenisTidakBerubahBilaSudahDipakai(...),
                $this->hanyaSatuGudangPusat(...),
            ],
            'alamat' => ['nullable', 'string', 'max:'.self::MAKS['alamat']],
        ];
    }

    private function jenisTidakBerubahBilaSudahDipakai(string $atribut, mixed $nilai, Closure $gagal): void
    {
        $lokasi = $this->dataYangDiubah();

        if ($lokasi && $lokasi->jenis !== $nilai && $lokasi->sudahDipakai()) {
            $gagal('Jenis lokasi tidak bisa diubah karena lokasi ini sudah dipakai.');
        }
    }

    private function hanyaSatuGudangPusat(string $atribut, mixed $nilai, Closure $gagal): void
    {
        if ($nilai !== Cabang::JENIS_PUSAT) {
            return;
        }

        $lokasi = $this->dataYangDiubah();

        // Gudang Pusat yang sedang nonaktif boleh diubah datanya; aturan dicek lagi saat diaktifkan.
        if ($lokasi && ! $lokasi->aktif) {
            return;
        }

        $pusatLain = Cabang::pusatAktifSelain($lokasi?->id);

        if ($pusatLain) {
            $gagal("Sudah ada Gudang Pusat aktif ({$pusatLain->nama}). Hanya boleh ada satu Gudang Pusat aktif.");
        }
    }

    public function messages(): array
    {
        return [
            'kode.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung (-). Contoh: JTI atau CBG-02.',
            'kode.unique' => 'Kode ini sudah dipakai lokasi lain. Gunakan kode lain.',
            'nama.unique' => 'Nama lokasi ini sudah terdaftar. Gunakan nama lain.',
        ];
    }

    public function attributes(): array
    {
        return [
            'kode' => 'kode lokasi',
            'nama' => 'nama lokasi',
            'jenis' => 'jenis lokasi',
            'alamat' => 'alamat',
        ];
    }
}
