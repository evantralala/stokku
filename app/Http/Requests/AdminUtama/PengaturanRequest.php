<?php

namespace App\Http\Requests\AdminUtama;

use App\Models\Pengaturan;
use App\Models\User;
use App\Support\Angka;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/** Aturan validasi dibangun dari tipe dan batas setiap baris di tabel pengaturan. */
class PengaturanRequest extends FormRequest
{
    private ?Collection $daftar = null;

    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_UTAMA;
    }

    /** @return Collection<string, Pengaturan> */
    public function daftarPengaturan(): Collection
    {
        return $this->daftar ??= Pengaturan::all()->keyBy('kunci');
    }

    protected function prepareForValidation(): void
    {
        $nilai = (array) $this->input('nilai', []);

        foreach ($this->daftarPengaturan() as $kunci => $p) {
            if (array_key_exists($kunci, $nilai) && in_array($p->tipe, [Pengaturan::TIPE_BILANGAN, Pengaturan::TIPE_PERSEN], true)) {
                $nilai[$kunci] = Angka::baca($nilai[$kunci]);
            }
        }

        $this->merge(['nilai' => $nilai]);
    }

    public function rules(): array
    {
        return $this->daftarPengaturan()
            ->mapWithKeys(fn (Pengaturan $p, string $kunci) => ["nilai.{$kunci}" => $p->aturanValidasi()])
            ->all();
    }

    public function messages(): array
    {
        return [
            'nilai.*.date_format' => 'Isi jam dengan format 24 jam, mis. 06:00.',
            'nilai.*.integer'     => 'Isi dengan bilangan bulat tanpa koma.',
        ];
    }

    public function attributes(): array
    {
        return $this->daftarPengaturan()
            ->mapWithKeys(fn (Pengaturan $p, string $kunci) => ["nilai.{$kunci}" => $p->label ?? $kunci])
            ->all();
    }
}
