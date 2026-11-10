<?php

namespace App\View\Composers;

use App\Models\User;
use App\Services\JumlahMenu;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Menyelipkan $pengguna dan $jumlah ke layout dan halaman.
 * Didaftarkan sebagai scoped, jadi query hanya jalan sekali per request.
 */
class LayoutComposer
{
    private bool $sudahDihitung = false;

    private ?User $pengguna = null;

    private array $jumlah = JumlahMenu::KOSONG;

    public function __construct(private JumlahMenu $jumlahMenu) {}

    public function compose(View $view): void
    {
        $this->hitungSekali();

        $view->with([
            'pengguna' => $this->pengguna,
            'jumlah' => $this->jumlah,
        ]);
    }

    private function hitungSekali(): void
    {
        if ($this->sudahDihitung) {
            return;
        }

        /** @var User|null $user */
        $user = Auth::user();
        $user?->loadMissing('cabang');

        $this->pengguna = $user;
        $this->jumlah = $this->jumlahMenu->untuk($user);
        $this->sudahDihitung = true;
    }
}
