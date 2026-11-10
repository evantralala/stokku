<?php

namespace App\Services\Impor;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impor & ekspor Excel satu data master. Data dikenali dari nama (unik, tanpa membedakan huruf besar-kecil):
 * nama yang sudah ada dilewati, nama kosong atau ganda di file salah isi. Nama yang sama dengan data yang pernah
 * dihapus (arsip) memulihkan data itu, sehingga riwayat lamanya tersambung lagi.
 */
abstract class ImporMaster extends ImporExcel
{
    /** @return list<string> nama yang sudah ada, huruf kecil */
    abstract protected function namaAda(): array;

    /**
     * Normalkan dan periksa satu baris (selain nama kosong/ganda, yang diperiksa di sini).
     *
     * @param  array<string, mixed>  $isi
     * @return array{data: array<string, mixed>, pesan: list<string>, catatan?: list<string>} data wajib berisi 'nama'
     */
    abstract protected function periksaBaris(array $isi): array;

    /** @param  array<string, mixed>  $data */
    abstract protected function simpanBaris(array $data): void;

    /** Batas panjang nama. */
    abstract protected function maksNama(): int;

    private int $dipulihkan = 0;

    /**
     * Buat data baru, atau pulihkan arsip bernama sama dengan atribut baru.
     *
     * @param  class-string<Model>  $kelas  model yang memakai trait DataMaster
     */
    protected function buatAtauPulihkan(string $kelas, array $atribut): Model
    {
        $arsip = $kelas::cariArsip($atribut);

        if ($arsip) {
            $arsip->forceFill(['dihapus_pada' => null])->fill($atribut + ['aktif' => true])->save();
            $this->dipulihkan++;

            return $arsip;
        }

        return $kelas::create($atribut);
    }

    /** @return list<string> nama data yang pernah dihapus (huruf kecil) */
    protected function namaArsip(): array
    {
        return [];
    }

    /** Siapkan data pembanding sebelum memeriksa. */
    protected function siapkan(): void
    {
    }

    /** Jenis kolom nama untuk pembersih: 'nama' (huruf besar di awal kata) atau 'nama:<jenis>' (dicocokkan dengan yang sudah ada). */
    protected function jenisNama(): string
    {
        return 'nama';
    }

    public function jenisKolom(): array
    {
        return ['nama' => $this->jenisNama()] + parent::jenisKolom();
    }

    public function labelDilewati(): string
    {
        return 'Sudah ada';
    }

    /**
     * @param  array<int, array<string, mixed>>  $mentah
     * @return list<array{baris: int, status: string, data: array, pesan: list<string>, catatan: list<string>}>
     */
    public function periksa(array $mentah): array
    {
        $this->siapkan();
        $ada = array_flip($this->namaAda());
        $arsip = array_flip($this->namaArsip());
        $diFile = [];
        $hasil = [];

        foreach ($mentah as $no => $isi) {
            $isi['nama'] = Str::squish((string) ($isi['nama'] ?? ''));
            $kunci = mb_strtolower($isi['nama']);

            if ($kunci !== '' && isset($ada[$kunci])) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => ['nama' => $isi['nama']], 'pesan' => ['Sudah ada.'], 'catatan' => [], 'ringkas' => [$isi['nama'], '']];

                continue;
            }

            $periksa = $this->periksaBaris($isi);
            $pesan = $periksa['pesan'];

            if ($kunci === '') {
                array_unshift($pesan, 'Nama wajib diisi.');
            } elseif (mb_strlen($isi['nama']) > $this->maksNama()) {
                array_unshift($pesan, 'Nama maksimal '.$this->maksNama().' huruf.');
            } elseif (isset($diFile[$kunci])) {
                array_unshift($pesan, 'Nama sama dengan baris '.$diFile[$kunci].'.');
            }

            if ($kunci !== '') {
                $diFile[$kunci] ??= $no;
            }

            $hasil[] = [
                'baris'   => $no,
                'status'  => $pesan === [] ? self::STATUS_SIAP : self::STATUS_SALAH,
                'data'    => ['nama' => $isi['nama']] + $periksa['data'],
                'pesan'   => $pesan,
                'catatan' => $pesan === [] ? array_values(array_unique([...(isset($arsip[$kunci]) ? ['Pernah dihapus: ditambahkan kembali, riwayat lama tersambung'] : []), ...($periksa['catatan'] ?? [])])) : [],
            ];
        }

        return $hasil;
    }

    /**
     * Simpan baris yang masih siap setelah diperiksa ulang.
     *
     * @param  array<int, array<string, mixed>>  $mentah
     * @return array{disimpan: int, dilewati: int, catatan: list<string>}
     */
    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        return DB::transaction(function () use ($mentah, $oleh, $namaFile) {
            $hasil = $this->periksa($mentah);
            $siap = collect($hasil)->where('status', self::STATUS_SIAP)->values();

            foreach ($this->urutanSimpan($siap) as $r) {
                $this->simpanBaris($r['data']);
            }

            if ($siap->isNotEmpty()) {
                $this->catatLog($oleh, $siap->count(), $namaFile);
            }

            return [
                'disimpan' => $siap->count(),
                'dilewati' => count($hasil) - $siap->count(),
                'catatan'  => [...($this->dipulihkan ? [$this->dipulihkan.' ditambahkan kembali'] : []), ...$this->catatanSetelahSimpan()],
            ];
        });
    }

    /** @return list<string> catatan tambahan untuk pesan hasil simpan, mis. "2 kategori baru" */
    protected function catatanSetelahSimpan(): array
    {
        return [];
    }

    /** @param  Collection<int, array>  $siap */
    protected function urutanSimpan(Collection $siap): Collection
    {
        return $siap;
    }
}
