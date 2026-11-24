<?php

namespace App\Http\Requests;

use App\Models\Cabang;
use App\Services\LiburService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tandai hari libur cabang. Admin Cabang untuk cabangnya sendiri; Admin Pusat dan Admin Utama memilih cabang. */
class LiburRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $aturan = [
            'tanggal' => [
                'required', 'date_format:Y-m-d', 'before_or_equal:today',
                'after_or_equal:'.now()->subDays(LiburService::batasMundur($this->user()))->toDateString(),
            ],
        ];

        if (! $this->user()->isAdminCabang()) {
            $aturan['cabang_id'] = ['required', 'integer',
                Rule::exists('cabang', 'id')->where('jenis', Cabang::JENIS_CABANG)->where('aktif', true)];
        }

        return $aturan;
    }

    public function messages(): array
    {
        return [
            'tanggal.required'        => 'Pilih tanggal.',
            'tanggal.date_format'     => 'Pilih tanggal dari kalender.',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh setelah hari ini.',
            'tanggal.after_or_equal'  => 'Libur hanya bisa ditandai paling lama '.LiburService::batasMundur($this->user()).' hari ke belakang.',
            'cabang_id.required'      => 'Pilih cabang.',
            'cabang_id.exists'        => 'Pilih cabang yang aktif.',
        ];
    }
}
