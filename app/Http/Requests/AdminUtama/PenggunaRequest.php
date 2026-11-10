<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\IsianAman;
use App\Http\Requests\DataMasterRequest;
use App\Models\Cabang;
use App\Models\User;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Tambah / ubah pengguna. Lokasi ditentukan dari peran:
 * Admin Utama tanpa lokasi, Admin Pusat otomatis Gudang Pusat aktif, Admin Cabang memilih cabang.
 * Password hanya diisi saat menambah; perubahan password lewat halaman terpisah.
 */
class PenggunaRequest extends DataMasterRequest
{
    use IsianAman;

    public const MAKS = [
        'nama'  => 100,
        'email' => 150,
    ];

    /** Akun Admin Utama hanya bisa diubah Admin Utama (Admin Pusat bisa diberi menu Pengguna). */
    public function authorize(): bool
    {
        $pengguna = $this->dataYangDiubah();

        return parent::authorize() && (! $pengguna instanceof User || $pengguna->bisaDikelolaOleh($this->user()));
    }

    /**
     * Peran yang boleh diberikan: Admin Utama hanya oleh Admin Utama.
     *
     * @return array<string, string>
     */
    public static function pilihanPeran(?User $oleh): array
    {
        return $oleh?->isAdminUtama()
            ? User::LABEL_ROLE
            : array_diff_key(User::LABEL_ROLE, [User::ROLE_ADMIN_UTAMA => true]);
    }

    protected function prepareForValidation(): void
    {
        $role = $this->input('role');

        $this->merge([
            'email'     => Str::lower($this->teks('email')),
            'cabang_id' => match ($role) {
                User::ROLE_ADMIN_PUSAT  => Cabang::pusatAktifSelain(null)?->id,
                User::ROLE_ADMIN_CABANG => $this->input('cabang_id') ?: null,
                default                 => null,
            },
        ]);
    }

    public function rules(): array
    {
        $menambah = $this->dataYangDiubah() === null;

        return [
            'nama'  => ['required', 'string', 'max:'.self::MAKS['nama']],
            'email' => ['required', 'string', 'email', 'max:'.self::MAKS['email'], $this->unik('users', 'email')],
            'role'  => [
                'required',
                Rule::in(array_keys(User::LABEL_ROLE)),
                $this->peranBolehDiberikan(...),
                $this->peranBolehDiubah(...),
                $this->gudangPusatTersedia(...),
            ],
            'cabang_id' => [
                Rule::requiredIf(fn () => $this->input('role') === User::ROLE_ADMIN_CABANG),
                'nullable',
                'integer',
                Rule::exists('cabang', 'id')->where('aktif', true)->where('jenis', $this->input('role') === User::ROLE_ADMIN_PUSAT
                    ? Cabang::JENIS_PUSAT
                    : Cabang::JENIS_CABANG),
            ],
            'password' => $menambah
                ? ['required', 'string', Password::min(8), 'confirmed']
                : ['exclude'],
        ];
    }

    /** Peran sendiri tidak boleh diubah, dan harus tetap ada minimal satu Admin Utama aktif. */
    private function peranBolehDiubah(string $atribut, mixed $nilai, Closure $gagal): void
    {
        $pengguna = $this->dataYangDiubah();

        if (! $pengguna || $pengguna->role === $nilai) {
            return;
        }

        if ($pengguna->is($this->user())) {
            $gagal('Anda tidak bisa mengubah peran akun sendiri.');

            return;
        }

        if ($pengguna->isAdminUtama() && $pengguna->aktif && ! User::adaAdminUtamaAktifLain($pengguna->id)) {
            $gagal('Harus tetap ada minimal satu Admin Utama aktif. Tambahkan Admin Utama lain terlebih dahulu.');
        }
    }

    private function peranBolehDiberikan(string $atribut, mixed $nilai, Closure $gagal): void
    {
        $pengguna = $this->dataYangDiubah();

        if (is_string($nilai) && ! array_key_exists($nilai, self::pilihanPeran($this->user())) && ! ($pengguna && $pengguna->role === $nilai)) {
            $gagal('Hanya Admin Utama yang bisa memberikan peran Admin Utama.');
        }
    }

    private function gudangPusatTersedia(string $atribut, mixed $nilai, Closure $gagal): void
    {
        if ($nilai === User::ROLE_ADMIN_PUSAT && $this->input('cabang_id') === null) {
            $gagal('Belum ada Gudang Pusat aktif. Tambahkan Gudang Pusat di menu Lokasi terlebih dahulu.');
        }
    }

    public function messages(): array
    {
        return [
            'email.unique'       => 'Email ini sudah dipakai pengguna lain.',
            'cabang_id.required' => 'Pilih cabang tempat admin ini bertugas.',
            'cabang_id.exists'   => 'Cabang tidak ditemukan atau sudah tidak aktif. Pilih cabang lain.',
            'password.confirmed' => 'Ulangi password belum sama. Ketik ulang dengan teliti.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nama'      => 'nama',
            'email'     => 'email',
            'role'      => 'peran',
            'cabang_id' => 'cabang',
            'password'  => 'password',
        ];
    }
}
