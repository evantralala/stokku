<?php

namespace App\Services\Impor;

use App\Support\Angka;
use App\Support\PembersihExcel;
use App\Support\PencocokNama;
use Illuminate\Support\Carbon;

/**
 * Membersihkan isi file impor sebelum diperiksa layanan impor, per jenis kolom:
 *   teks          spasi dirapikan
 *   nama          + huruf besar di awal kata bila ditulis serba kecil/serba besar
 *   angka         "2,5", "2.5", "2½", "1.000" dibaca jadi angka
 *   tanggal       berbagai format jadi TTTT-BB-HH
 *   satuan        sinonim ("Kilo", "KG") jadi satuan baku/yang ada di sistem
 *   bahan, menu, lokasi, kategori, kategori_menu
 *                 dicocokkan dengan nama di sistem: tulisan beda huruf/spasi langsung dibetulkan,
 *                 salah ketik/singkatan menjadi tebakan ("Maksudnya Beras Jepang?") yang menunggu jawaban pengguna
 *   nama:<jenis>  kolom nama data master yang sedang diimpor: dicocokkan dengan nama yang sudah ada (tebakan = "sudah ada")
 *
 * Jawaban tebakan ($keputusan) dikunci per kolom + tulisan asli, jadi satu jawaban berlaku untuk semua baris yang sama.
 */
final class PembersihImpor
{
    public const TERIMA = 'terima';
    public const TOLAK = 'tolak';

    /** Satuan sependek ini tidak ditebak (terlalu mudah salah). */
    private const SATUAN_MIN_TEBAK = 4;

    public function __construct(private KamusImpor $kamus = new KamusImpor)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $mentah  [nomor baris => isi]
     * @param  array<string, string>  $jenis  [kolom => jenis]
     * @param  array<string, string>  $keputusan  [kunci tebakan => terima|tolak]
     * @return array{mentah: array<int, array<string, mixed>>, info: array<int, array{betul: list<string>, tebakan: list<array>}>}
     *         tebakan: kunci, kolom, label, asli, saran, ada (nama sudah ada di data master), jawab (terima|tolak|null)
     */
    public function bersihkan(array $mentah, array $jenis, array $keputusan = []): array
    {
        $info = [];

        foreach ($mentah as $no => $isi) {
            $betul = [];
            $tebakan = [];

            foreach ($isi as $kolom => $nilai) {
                $j = $jenis[$kolom] ?? 'teks';
                [$isi[$kolom], $catat, $tebak] = $this->bersihkanSel($nilai, $j, $keputusan);

                if ($catat !== null) {
                    $betul[] = $catat;
                }

                if ($tebak !== null) {
                    $tebakan[] = ['kolom' => $kolom] + $tebak;
                }
            }

            $mentah[$no] = $isi;
            $info[$no] = ['betul' => $betul, 'tebakan' => $tebakan];
        }

        return ['mentah' => $mentah, 'info' => $info];
    }

    public static function kunciTebakan(string $jenis, string $asli): string
    {
        return 't'.substr(md5($jenis.'|'.PencocokNama::kunci($asli)), 0, 12);
    }

    /** @return array{0: mixed, 1: ?string, 2: ?array} [nilai bersih, catatan dibetulkan, tebakan] */
    private function bersihkanSel(mixed $nilai, string $jenis, array $keputusan): array
    {
        $teks = PembersihExcel::teks($nilai);

        if ($teks === null) {
            return [null, null, null];
        }

        return match (true) {
            $jenis === 'angka'   => $this->angka($nilai, $teks),
            $jenis === 'tanggal' => $this->tanggal($nilai, $teks),
            $jenis === 'nama'    => $this->catat($teks, PembersihExcel::kapital($teks)),
            $jenis === KamusImpor::SATUAN, $jenis === 'nama:'.KamusImpor::SATUAN
                                 => $this->satuan($teks, $jenis, $keputusan),
            isset(KamusImpor::LABEL[$jenis]) => $this->rujukan($teks, $jenis, $keputusan, false),
            str_starts_with($jenis, 'nama:') && isset(KamusImpor::LABEL[substr($jenis, 5)])
                                 => $this->rujukan($teks, substr($jenis, 5), $keputusan, true),
            default              => [is_string($nilai) ? $teks : $nilai, null, null],
        };
    }

    private function angka(mixed $nilai, string $teks): array
    {
        $angka = PembersihExcel::angka($nilai);

        if ($angka === null) {
            return [$teks, null, null];
        }

        $tampil = Angka::tampil($angka);
        $catat = is_string($nilai) && $tampil !== $teks && Angka::isian($angka) !== $teks ? "{$teks} dibaca {$tampil}" : null;

        return [$angka, $catat, null];
    }

    private function tanggal(mixed $nilai, string $teks): array
    {
        $tanggal = PembersihExcel::tanggal($nilai);

        if ($tanggal === null) {
            return [$teks, null, null];
        }

        $tampil = Carbon::parse($tanggal)->format('d/m/Y');
        $catat = is_string($nilai) && ! in_array($teks, [$tampil, $tanggal], true) && ! is_numeric($teks) ? "{$teks} dibaca {$tampil}" : null;

        return [$tanggal, $catat, null];
    }

    private function satuan(string $teks, string $jenis, array $keputusan): array
    {
        $diSistem = $this->kamus->nama(KamusImpor::SATUAN);
        $baku = PembersihExcel::satuan($teks, $diSistem);
        $ada = collect($diSistem)->contains(fn ($s) => mb_strtolower($s) === mb_strtolower($baku));

        if (! $ada && mb_strlen($baku) >= self::SATUAN_MIN_TEBAK) {
            $cari = $this->kamus->pencocok(KamusImpor::SATUAN)->cari($baku);

            if ($cari['jenis'] === PencocokNama::TEBAK) {
                return $this->jawab($teks, $baku, $cari['nama'], $jenis, KamusImpor::SATUAN, $keputusan, $jenis !== KamusImpor::SATUAN);
            }
        }

        return $this->catat($teks, $baku);
    }

    private function rujukan(string $teks, string $jenis, array $keputusan, bool $namaSendiri): array
    {
        if ($jenis === KamusImpor::LOKASI && ($kode = $this->kamus->kodeLokasi($teks)) !== null) {
            return $this->catat($teks, $kode);
        }

        if ($jenis === KamusImpor::LOKASI && ($semua = LokasiImpor::jenisSemua($teks)) !== null) {
            return $this->catat($teks, $semua);
        }

        if ($jenis === KamusImpor::BAHAN && ($nama = $this->kamus->bahanDariNamaLain($teks)) !== null) {
            return $this->catat($teks, $nama);
        }

        $cari = $this->kamus->pencocok($jenis)->cari($teks);

        return match ($cari['jenis']) {
            PencocokNama::PAS   => $this->catat($teks, $cari['nama']),
            PencocokNama::TEBAK => $this->jawab($teks, PembersihExcel::kapital($teks), $cari['nama'], ($namaSendiri ? 'nama:' : '').$jenis, $jenis, $keputusan, $namaSendiri),
            default             => $this->catat($teks, PembersihExcel::kapital($teks)),
        };
    }

    /** Tebakan: dipakai bila diterima, tulisan sendiri bila ditolak, belum dijawab tetap ditanyakan. */
    private function jawab(string $asli, string $sendiri, string $saran, string $jenis, string $rujukan, array $keputusan, bool $namaSendiri): array
    {
        $kunci = self::kunciTebakan($jenis, $asli);
        $jawab = in_array($keputusan[$kunci] ?? null, [self::TERIMA, self::TOLAK], true) ? $keputusan[$kunci] : null;
        $tebakan = [
            'kunci' => $kunci,
            'label' => KamusImpor::LABEL[$rujukan],
            'asli'  => $asli,
            'saran' => $saran,
            'ada'   => $namaSendiri,
            'jawab' => $jawab,
        ];

        return match ($jawab) {
            self::TERIMA => [$saran, "{$asli} → {$saran}", $tebakan],
            self::TOLAK  => [...array_slice($this->catat($asli, $sendiri), 0, 2), $tebakan],
            default      => [$sendiri, null, $tebakan],
        };
    }

    private function catat(string $asli, string $hasil): array
    {
        return [$hasil, $hasil !== $asli ? "{$asli} → {$hasil}" : null, null];
    }
}
