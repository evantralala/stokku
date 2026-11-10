<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\LokasiRequest;
use App\Models\Cabang;
use App\Models\User;
use App\Support\LembarExcel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lokasi baru dari lembar Lokasi di Impor Sekaligus. Lokasi dikenali dari kodenya: kode yang sudah ada dilewati
 * (tidak diubah). Jenis kosong = Cabang; Gudang Pusat hanya boleh satu yang aktif. Lokasi arsip berkode/bernama
 * sama dipulihkan.
 */
class ImporLokasiService extends ImporExcel
{
    public function judul(): string
    {
        return 'Lokasi';
    }

    public function satuanHasil(): string
    {
        return 'lokasi';
    }

    public function labelDilewati(): string
    {
        return 'Sudah ada';
    }

    protected function isiTemplat(): array
    {
        $ada = Cabang::query()->tampil()->orderByRaw('jenis = ? desc', [Cabang::JENIS_PUSAT])->orderBy('nama')->get();

        if ($ada->isNotEmpty()) {
            return $ada->map(fn (Cabang $c) => ['kode' => $c->kode, 'nama' => $c->nama, 'jenis' => $c->label_jenis, 'alamat' => $c->alamat])->all();
        }

        return [
            ['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::LABEL_JENIS[Cabang::JENIS_PUSAT], 'alamat' => null],
            ['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::LABEL_JENIS[Cabang::JENIS_CABANG], 'alamat' => null],
            ['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::LABEL_JENIS[Cabang::JENIS_CABANG], 'alamat' => null],
        ];
    }

    public function kolom(): array
    {
        return [
            'kode'   => ['lebar' => 10, 'ket' => 'Wajib. Huruf/angka singkat, mis. JTI. Kode yang sudah ada dilewati.', 'contoh' => 'JTI'],
            'nama'   => ['lebar' => 28, 'ket' => 'Wajib. Nama lokasi, mis. Cabang Jati.', 'contoh' => 'Cabang Jati'],
            'jenis'  => ['lebar' => 16, 'ket' => 'Gudang Pusat atau Cabang. Kosong = Cabang. Gudang Pusat hanya boleh satu.', 'contoh' => 'Cabang'],
            'alamat' => ['lebar' => 34, 'ket' => 'Boleh kosong.', 'contoh' => null],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['kode', 'nama'];
    }

    public function jenisKolom(): array
    {
        return ['kode' => 'teks', 'nama' => 'nama', 'jenis' => 'teks', 'alamat' => 'teks'];
    }

    protected function barisEkspor(): array
    {
        return [];
    }

    public function periksa(array $mentah): array
    {
        $semua = Cabang::query()->get();
        $kodeAda = $semua->keyBy(fn (Cabang $c) => mb_strtolower($c->kode));
        $namaAda = $semua->keyBy(fn (Cabang $c) => mb_strtolower($c->nama));
        $adaPusat = Cabang::pusatAktifSelain(null) !== null;
        $diFile = ['kode' => [], 'nama' => []];
        $hasil = [];

        foreach ($mentah as $no => $isi) {
            $kode = Str::upper(preg_replace('/\s+/', '', (string) $isi['kode']));
            $nama = Str::squish((string) $isi['nama']);
            $jenis = self::jenis($isi['jenis'] ?? null);
            $alamat = self::teks($isi['alamat'] ?? null);
            $data = ['kode' => $kode, 'nama' => $nama, 'jenis' => $jenis, 'alamat' => $alamat];
            $lama = $kodeAda[mb_strtolower($kode)] ?? null;

            if ($kode !== '' && $lama && ! $lama->diarsipkan()) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ['Kode '.$kode.' sudah ada ('.$lama->nama.').'], 'catatan' => []];

                continue;
            }

            $pesan = [];

            if ($kode === '') {
                $pesan[] = 'Kode wajib diisi.';
            } elseif (mb_strlen($kode) > LokasiRequest::MAKS['kode'] || ! preg_match('/^[A-Z0-9-]+$/', $kode)) {
                $pesan[] = 'Kode hanya huruf, angka, dan tanda hubung, maksimal '.LokasiRequest::MAKS['kode'].' karakter.';
            } elseif (isset($diFile['kode'][$kode])) {
                $pesan[] = 'Kode sama dengan baris '.$diFile['kode'][$kode].'.';
            }

            $pemilikNama = $namaAda[mb_strtolower($nama)] ?? null;

            if ($nama === '') {
                $pesan[] = 'Nama wajib diisi.';
            } elseif (mb_strlen($nama) > LokasiRequest::MAKS['nama']) {
                $pesan[] = 'Nama maksimal '.LokasiRequest::MAKS['nama'].' huruf.';
            } elseif ($pemilikNama && ! $pemilikNama->diarsipkan() && mb_strtolower($pemilikNama->kode) !== mb_strtolower($kode)) {
                $pesan[] = 'Nama '.$nama.' sudah dipakai lokasi berkode '.$pemilikNama->kode.'.';
            } elseif (isset($diFile['nama'][mb_strtolower($nama)])) {
                $pesan[] = 'Nama sama dengan baris '.$diFile['nama'][mb_strtolower($nama)].'.';
            }

            if ($jenis === null) {
                $pesan[] = 'Jenis diisi Gudang Pusat atau Cabang.';
            } elseif ($jenis === Cabang::JENIS_PUSAT && $adaPusat) {
                $pesan[] = 'Sudah ada Gudang Pusat aktif. Pindah gudang pusat lewat menu Lokasi.';
            }

            if (mb_strlen((string) $alamat) > LokasiRequest::MAKS['alamat']) {
                $pesan[] = 'Alamat maksimal '.LokasiRequest::MAKS['alamat'].' huruf.';
            }

            if ($kode !== '') {
                $diFile['kode'][$kode] ??= $no;
            }
            if ($nama !== '') {
                $diFile['nama'][mb_strtolower($nama)] ??= $no;
            }
            if ($pesan === [] && $jenis === Cabang::JENIS_PUSAT) {
                $adaPusat = true;
            }

            $hasil[] = [
                'baris'   => $no,
                'status'  => $pesan === [] ? self::STATUS_SIAP : self::STATUS_SALAH,
                'data'    => $data,
                'pesan'   => $pesan,
                'catatan' => $pesan === [] && $lama ? ['Pernah dihapus: ditambahkan kembali, riwayat lama tersambung'] : [],
            ];
        }

        return $hasil;
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        return DB::transaction(function () use ($mentah, $oleh, $namaFile) {
            $hasil = collect($this->periksa($mentah));
            $siap = $hasil->where('status', self::STATUS_SIAP);

            foreach ($siap as $r) {
                $atribut = $r['data'] + ['aktif' => true];
                $arsip = Cabang::cariArsip($atribut);

                $arsip
                    ? $arsip->forceFill(['dihapus_pada' => null])->fill($atribut)->save()
                    : Cabang::create($atribut);
            }

            if ($siap->isNotEmpty()) {
                $this->catatLog($oleh, $siap->count(), $namaFile);
            }

            return ['disimpan' => $siap->count(), 'dilewati' => $hasil->count() - $siap->count(), 'catatan' => []];
        });
    }

    public function ringkasan(array $data): array
    {
        return [
            collect([$data['kode'], $data['nama']])->filter()->implode(' · ') ?: '(tanpa kode)',
            collect([$data['jenis'] ? Cabang::LABEL_JENIS[$data['jenis']] : null, $data['alamat']])->filter()->implode(' · '),
        ];
    }

    /** Jenis lokasi dari tulisan: kosong/cabang/outlet = cabang, gudang/pusat = Gudang Pusat; selain itu null. */
    private static function jenis(mixed $teks): ?string
    {
        $rapat = LembarExcel::rapatkanJudul($teks);

        return match (true) {
            $rapat === '', in_array($rapat, ['cabang', 'outlet', 'toko', 'branch', 'store'], true) => Cabang::JENIS_CABANG,
            in_array($rapat, ['gudangpusat', 'pusat', 'gudang', 'gp', 'warehouse', 'gudangutama'], true) => Cabang::JENIS_PUSAT,
            default => null,
        };
    }
}
