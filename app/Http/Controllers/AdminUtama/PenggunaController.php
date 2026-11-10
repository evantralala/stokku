<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\DataMasterController;
use App\Http\Requests\AdminUtama\PenggunaRequest;
use App\Models\Cabang;
use App\Models\User;
use App\Services\PenggunaService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Pengguna dan hak aksesnya (peran + lokasi). */
class PenggunaController extends DataMasterController
{
    protected string $model = User::class;
    protected string $rute = 'admin-utama.pengguna';
    protected string $view = 'admin-utama.pengguna';
    protected string $label = 'pengguna';

    public function __construct(private PenggunaService $layanan) {}

    public function index(Request $request): View
    {
        return $this->tampilkanDaftar($request);
    }

    public function create(): View
    {
        return $this->tampilkanForm();
    }

    public function store(PenggunaRequest $request): RedirectResponse
    {
        return $this->simpanBaru($request, $request->validated());
    }

    public function edit(User $pengguna): View
    {
        $this->pastikanBisaDikelola($pengguna);

        return $this->tampilkanForm($pengguna);
    }

    public function update(PenggunaRequest $request, User $pengguna): RedirectResponse
    {
        return $this->simpanPerubahan($pengguna, $request->validated());
    }

    public function ubahAktif(Request $request, User $pengguna): RedirectResponse
    {
        $this->pastikanBisaDikelola($pengguna);

        return $this->aturAktif($request, $pengguna);
    }

    public function destroy(Request $request, User $pengguna): RedirectResponse
    {
        $this->pastikanBisaDikelola($pengguna);

        if ($pengguna->is($request->user())) {
            return redirect()->route('admin-utama.pengguna.ubah', $pengguna)->with('galat', 'Anda tidak bisa menghapus akun sendiri.');
        }

        return $this->hapusData($pengguna);
    }

    /** Admin Pusat yang diberi menu Pengguna tidak bisa mengubah akun Admin Utama. */
    private function pastikanBisaDikelola(User $pengguna): void
    {
        if (! $pengguna->bisaDikelolaOleh(request()->user())) {
            throw new HttpResponseException(redirect()->route($this->rute)
                ->with('galat', 'Akun Admin Utama hanya bisa diubah oleh Admin Utama.'));
        }
    }

    /** Admin Utama mengatur ulang password pengguna lain; pengguna itu langsung keluar dari semua perangkat. */
    public function aturUlangPassword(Request $request, User $pengguna): RedirectResponse
    {
        $this->pastikanBisaDikelola($pengguna);

        if ($pengguna->is($request->user())) {
            return redirect()->route('akun')->with('galat', 'Ganti password akun sendiri lewat halaman Akun saya.');
        }

        $data = $request->validate(
            ['password_baru' => ['required', 'string', Password::min(8), 'confirmed']],
            ['password_baru.confirmed' => 'Ulangi password belum sama. Ketik ulang dengan teliti.'],
            ['password_baru' => 'password baru']
        );

        $this->layanan->aturUlangPassword($pengguna, $data['password_baru'], $request->user());

        return redirect()
            ->route('admin-utama.pengguna')
            ->with('sukses', "Password {$pengguna->nama} berhasil diganti. Sampaikan password baru kepadanya.");
    }

    protected function bacaFilter(Request $request): array
    {
        $role = $request->query('peran');

        return ['peran' => array_key_exists((string) $role, User::LABEL_ROLE) ? $role : ''];
    }

    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $query
            ->when($filter['peran'] !== '', fn (Builder $q) => $q->where('role', $filter['peran']))
            ->with('cabang:id,nama')
            ->orderByDesc('aktif')
            ->orderBy('nama');
    }

    protected function dataDaftar(array $filter): array
    {
        return ['pilihanPeran' => User::LABEL_ROLE];
    }

    protected function dataForm(?Model $item): array
    {
        return [
            'maks'          => PenggunaRequest::MAKS,
            'pilihanPeran'  => PenggunaRequest::pilihanPeran(auth()->user()),
            'pilihanCabang' => Cabang::query()->cabang()->aktif()->orderBy('nama')->pluck('nama', 'id')->all(),
            'gudangPusat'   => Cabang::pusatAktifSelain(null),
            'akunSendiri'   => $item !== null && $item->is(auth()->user()),
        ];
    }

    protected function terapkanAktif(Model $item, bool $aktif): ?string
    {
        try {
            $this->layanan->aturAktif($item, $aktif, request()->user());
        } catch (DomainException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function setelahDibuat(Model $item): void
    {
        $this->layanan->catatDibuat($item, request()->user());
    }

    protected function setelahDiubah(Model $item, array $sebelum): void
    {
        $this->layanan->catatDiubah($item, $sebelum, request()->user());
    }

    protected function setelahDihapus(Model $item): void
    {
        $this->layanan->catatDihapus($item, request()->user());
    }

    protected function setelahDiarsipkan(Model $item): void
    {
        $this->layanan->catatDihapus($item, request()->user());
    }

}
