<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Dasar Form Request untuk form data master.
 * Turunan cukup mengisi rules(), attributes(), dan bila perlu $roleBoleh.
 */
abstract class DataMasterRequest extends FormRequest
{
    use AksesMenuUtama;

    /** @var list<string> */
    protected array $roleBoleh = [User::ROLE_ADMIN_UTAMA];

    public function authorize(): bool
    {
        return in_array($this->user()?->role, $this->roleBoleh, true) || $this->bolehMenuUtama();
    }

    /** Data yang sedang diubah (dari parameter route), atau null saat menambah. */
    protected function dataYangDiubah(): ?Model
    {
        foreach ($this->route()?->parameters() ?? [] as $nilai) {
            if ($nilai instanceof Model) {
                return $nilai;
            }
        }

        return null;
    }

    /**
     * Aturan unik yang mengabaikan data yang sedang diubah. Saat menambah, data yang sudah dihapus (arsip) juga diabaikan
     * karena arsip itu dipulihkan saat disimpan; saat mengubah, nama arsip tetap dianggap terpakai.
     */
    protected function unik(string $tabel, string $kolom = 'nama'): Unique
    {
        $diubah = $this->dataYangDiubah();
        $aturan = Rule::unique($tabel, $kolom)->ignore($diubah?->getKey());

        return $diubah === null ? $aturan->whereNull('dihapus_pada') : $aturan;
    }
}
