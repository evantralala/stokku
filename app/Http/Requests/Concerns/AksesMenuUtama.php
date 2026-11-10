<?php

namespace App\Http\Requests\Concerns;

use App\Services\KontrolSistemService;
use App\Support\DaftarKontrol;

/** Untuk Form Request di halaman Admin Utama yang menunya bisa dibagikan ke Admin Pusat (Pengaturan > Hak Akses). */
trait AksesMenuUtama
{
    protected function bolehMenuUtama(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->isAdminUtama()
            || ($this->routeIs(DaftarKontrol::AWALAN_UTAMA.'.*') && app(KontrolSistemService::class)->bolehRequest($this));
    }
}
