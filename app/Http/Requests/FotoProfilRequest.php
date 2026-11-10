<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AturanFoto;
use Illuminate\Foundation\Http\FormRequest;

/** Foto profil dari menu Akun: unggah foto baru atau centang Hapus Foto. */
class FotoProfilRequest extends FormRequest
{
    use AturanFoto;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return $this->aturanFoto();
    }

    public function messages(): array
    {
        return $this->pesanFoto();
    }
}
