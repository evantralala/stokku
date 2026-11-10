<?php

namespace App\Http\Requests\Concerns;

/**
 * Membaca isian form tanpa galat bila bentuknya tidak terduga (mis. daftar dikirim di kolom teks lewat
 * alamat yang diutak-atik). Isian yang bukan nilai tunggal dianggap kosong; aturan validasi lalu
 * menampilkan pesan biasa.
 */
trait IsianAman
{
    protected function teks(string $kunci): string
    {
        $nilai = $this->input($kunci);

        return is_scalar($nilai) ? trim((string) $nilai) : '';
    }

    protected function teksAtauNull(string $kunci): ?string
    {
        $teks = $this->teks($kunci);

        return $teks === '' ? null : $teks;
    }

    /** Teks satu sel di baris form (mis. catatan atau keterangan per bahan); null bila kosong atau bukan teks. */
    protected static function teksSel(mixed $nilai): ?string
    {
        $teks = is_scalar($nilai) ? trim((string) $nilai) : '';

        return $teks === '' ? null : $teks;
    }

    /** Angka porsi dari isian bebas (mis. "12 porsi" menjadi "12"); null bila kosong atau bukan teks. */
    protected static function digitSel(mixed $nilai): ?string
    {
        return is_scalar($nilai) && filled($nilai) ? preg_replace('/\D/', '', (string) $nilai) : null;
    }
}
