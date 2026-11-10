<?php

namespace App\Exceptions;

use App\Models\BahanBaku;
use App\Support\Angka;
use DomainException;

/** Pengeluaran stok lebih besar dari saldo yang tersedia. Jumlah dalam satuan dasar. */
class StokTidakCukup extends DomainException
{
    public function __construct(
        public readonly string $namaBahan,
        public readonly float $saldo,
        public readonly float $diminta,
        public readonly ?int $bahanBakuId = null,
    ) {
        parent::__construct("Stok {$namaBahan} tidak cukup.");
    }

    /** Pesan untuk pengguna dengan sisa stok dalam satuan beli, mis. "Stok Nori di Gudang Pusat tidak cukup (tersisa 2 kg). …" */
    public function pesanDi(string $namaLokasi): string
    {
        $bahan = $this->bahanBakuId ? BahanBaku::with('satuanBeli:id,nama')->find($this->bahanBakuId) : null;

        if (! $bahan) {
            return $this->getMessage();
        }

        $sisa = Angka::tampil($bahan->keSatuanBeli($this->saldo)).' '.$bahan->satuanBeli->nama;

        return "Stok {$bahan->nama} di {$namaLokasi} tidak cukup (tersisa {$sisa}). Kurangi jumlahnya atau kirim nanti.";
    }
}
