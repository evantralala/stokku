<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\IsianAman;
use App\Http\Requests\Pusat\BarangMasukRequest;
use App\Models\BahanBaku;
use App\Support\Angka;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Ubah stok langsung satu bahan (ikon pensil). Dua cara:
 *   kurangi  isi jumlah yang dikurangi (jumlah_kurang, satuan beli); stok_baru dihitung = stok sekarang - jumlah.
 *   samakan  isi stok sebenarnya (stok_baru, satuan beli). Bawaan bila cara tidak dikirim.
 * stok_lama = stok (satuan dasar) saat form dibuka. Keduanya menjadi penyesuaian stok.
 */
class UbahStokRequest extends FormRequest
{
    use IsianAman;

    public const CARA_KURANGI = 'kurangi';
    public const CARA_SAMAKAN = 'samakan';

    public function cara(): string
    {
        return $this->input('cara') === self::CARA_KURANGI ? self::CARA_KURANGI : self::CARA_SAMAKAN;
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [
            'cara'       => $this->cara(),
            'keterangan' => $this->teksAtauNull('keterangan'),
        ];

        if ($this->cara() === self::CARA_KURANGI) {
            $bahan = $this->route('bahanBaku');
            $kurang = JumlahPintar::isian($this->input('jumlah_kurang'), $bahan?->id);
            $data['jumlah_kurang'] = $kurang;
            $data['stok_baru'] = is_numeric($kurang) && is_numeric($this->input('stok_lama')) && $bahan instanceof BahanBaku
                ? round($bahan->keSatuanBeli((float) $this->input('stok_lama')) - (float) $kurang, 3)
                : null;
        } else {
            $data['stok_baru'] = JumlahPintar::isian($this->input('stok_baru'), $this->route('bahanBaku')?->id);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $kurangi = $this->cara() === self::CARA_KURANGI;

        return [
            'cara'          => ['required', 'in:'.self::CARA_KURANGI.','.self::CARA_SAMAKAN],
            'jumlah_kurang' => $kurangi ? ['required', 'numeric', 'gt:0', 'max:'.BarangMasukRequest::MAKS_QTY] : ['exclude'],
            'stok_baru'     => $kurangi ? ['nullable', 'numeric'] : ['required', 'numeric', 'min:0', 'max:'.BarangMasukRequest::MAKS_QTY],
            'stok_lama'     => ['required', 'numeric'],
            'keterangan'    => ['nullable', 'string', 'max:'.TambahStokRequest::MAKS_KETERANGAN],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $bahan = $this->route('bahanBaku');

                if (! $bahan instanceof BahanBaku || $validator->errors()->hasAny(['stok_baru', 'stok_lama', 'jumlah_kurang'])) {
                    return;
                }

                if ($this->cara() === self::CARA_KURANGI) {
                    if ((float) $this->input('stok_baru') < -0.0005) {
                        $validator->errors()->add('jumlah_kurang', 'Pengurangan melebihi stok sekarang ('
                            .Angka::tampil($bahan->keSatuanBeli((float) $this->input('stok_lama'))).' '.$bahan->satuanBeli?->nama.').');
                    }

                    return;
                }

                if (abs($bahan->keSatuanDasar($this->input('stok_baru')) - (float) $this->input('stok_lama')) < 0.0005) {
                    $validator->errors()->add('stok_baru', 'Jumlahnya sama dengan stok sekarang. Isi jumlah yang benar-benar ada.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'stok_baru.required' => 'Isi jumlah stok baru.',
            'stok_baru.numeric'  => 'Jumlah harus berupa angka, mis. 5 atau 2,5.',
            'stok_baru.min'      => 'Jumlah tidak boleh kurang dari 0.',
            'stok_baru.max'      => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'stok_lama.*'        => 'Buka lagi Ubah stok dari halaman Stok.',
            'jumlah_kurang.required' => 'Isi jumlah yang dikurangi.',
            'jumlah_kurang.numeric'  => 'Jumlah harus berupa angka, mis. 2 atau 0,5.',
            'jumlah_kurang.gt'       => 'Jumlah yang dikurangi harus lebih dari 0.',
            'jumlah_kurang.max'      => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'keterangan.max'     => 'Keterangan maksimal :max karakter.',
        ];
    }
}
