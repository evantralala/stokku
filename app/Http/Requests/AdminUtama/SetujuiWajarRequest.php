<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Support\AturanWajar;
use Illuminate\Foundation\Http\FormRequest;

/** Dokumen yang dipilih dari ringkasan "Setujui yang wajar". Kunci berbentuk "jenis:id". */
class SetujuiWajarRequest extends FormRequest
{
    use AksesMenuUtama;

    public const MAKS_DOKUMEN = 200;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    public function rules(): array
    {
        $jenis = implode('|', array_map('preg_quote', AturanWajar::JENIS_MASSAL));

        return [
            'dokumen'   => ['required', 'array', 'max:'.self::MAKS_DOKUMEN],
            'dokumen.*' => ['required', 'string', 'regex:/^('.$jenis.')'.preg_quote(AturanWajar::PEMISAH_KUNCI, '/').'\d+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'dokumen.required' => 'Pilih minimal satu dokumen.',
            'dokumen.max'      => 'Terlalu banyak dokumen sekaligus. Setujui sebagian dulu.',
            'dokumen.*.regex'  => 'Ada dokumen yang tidak bisa disetujui sekaligus. Muat ulang halaman lalu coba lagi.',
        ];
    }
}
