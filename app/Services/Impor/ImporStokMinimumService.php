<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\StokMinimumRequest;
use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\User;
use App\Support\Angka;
use App\Support\ContohTemplat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impor & ekspor stok minimum per lokasi, diisi dalam satuan beli. Satu baris = satu bahan di satu lokasi.
 * Ekspor berisi semua lokasi aktif x bahan aktif, jadi file ekspor bisa langsung diubah lalu diimpor lagi.
 * Minimum kosong atau 0 berarti tanpa batas minimum (baris yang ada dihapus).
 */
class ImporStokMinimumService extends ImporExcel
{
    public const MAKS_BARIS = 10000;

    public function judul(): string
    {
        return 'Stok minimum';
    }

    public function satuanHasil(): string
    {
        return 'stok minimum';
    }

    protected function isiTemplat(): array
    {
        $lokasi = ContohTemplat::kodeLokasi();

        return ContohTemplat::bahan()->values()->map(fn (array $b, int $i) => [
            'lokasi' => $lokasi, 'bahan' => $b['nama'], 'satuan' => $b['satuan_beli'], 'minimum' => ContohTemplat::angka($i, [5, 2, 3, 4, 10, 2, 6, 3, 5]),
        ])->all();
    }

    public function kolom(): array
    {
        return [
            'lokasi'  => ['lebar' => 16, 'ket' => 'Wajib. Kode atau nama lokasi, mis. GP. "Semua" = semua lokasi, "Semua Cabang" = semua cabang.', 'contoh' => 'GP'],
            'bahan'   => ['lebar' => 28, 'ket' => 'Wajib. Nama bahan baku persis seperti di daftar bahan.', 'contoh' => 'Beras Jepang'],
            'satuan'  => ['lebar' => 10, 'ket' => 'Satuan bahan, mis. kg. Bila diisi gram atau ml, angkanya dikonversi otomatis.', 'contoh' => 'kg'],
            'minimum' => ['lebar' => 12, 'ket' => 'Stok minimum dalam satuan bahan, mis. 2,5. Kosong atau 0 = tanpa batas minimum.', 'contoh' => 10],
        ];
    }

    protected function kolomSatuanJumlah(): ?string
    {
        return 'satuan';
    }

    protected function kolomJumlahPintar(): array
    {
        return ['minimum'];
    }

    protected function kolomWajib(): array
    {
        return ['lokasi', 'bahan', 'minimum'];
    }

    protected function barisEkspor(): array
    {
        $lokasi = Cabang::query()->aktif()->orderByRaw('jenis = ? desc', [Cabang::JENIS_PUSAT])->orderBy('nama')->get(['id', 'kode']);
        $bahan = BahanBaku::query()->aktif()->with('satuanBeli:id,nama')
            ->orderBy(Kategori::select('urutan')->whereColumn('kategori.id', 'bahan_baku.kategori_id'))
            ->orderBy('nama')
            ->get();
        $minimum = BahanBakuMinimum::query()->get()->groupBy('cabang_id')->map->pluck('minimum', 'bahan_baku_id');

        return $lokasi->flatMap(fn (Cabang $l) => $bahan->map(function (BahanBaku $b) use ($l, $minimum) {
            $nilai = $minimum[$l->id][$b->id] ?? null;

            return [$l->kode, $b->nama, $b->satuanBeli->nama, $nilai !== null ? $b->keSatuanBeli($nilai) : null];
        }))->values()->all();
    }

    /**
     * Lokasi "Semua" / "Semua Cabang" dipecah menjadi satu baris per lokasi aktif. Bila bahan yang sama juga ditulis
     * untuk satu lokasi secara langsung (kode/nama lokasi), baris langsung itu yang dipakai untuk lokasi tersebut.
     */
    public function periksa(array $mentah): array
    {
        $lokasi = Cabang::query()->get();
        $bahan = BahanBaku::query()->with('satuanBeli')->get()->keyBy(fn (BahanBaku $b) => mb_strtolower($b->nama));
        $minimum = BahanBakuMinimum::query()->get()->keyBy(fn ($m) => $m->cabang_id.'|'.$m->bahan_baku_id);
        $kunciBahan = fn (array $isi) => mb_strtolower(Str::squish((string) $isi['bahan']));

        $langsung = [];
        foreach ($mentah as $isi) {
            $teks = Str::squish((string) $isi['lokasi']);
            if (LokasiImpor::jenisSemua($teks) === null && ($l = LokasiImpor::cari($lokasi, $teks))) {
                $langsung[$l->id.'|'.$kunciBahan($isi)] = true;
            }
        }

        $sudah = [];
        $hasil = [];

        foreach ($mentah as $no => $isi) {
            $teksLokasi = Str::squish((string) $isi['lokasi']);
            $semua = LokasiImpor::jenisSemua($teksLokasi);

            if ($semua === null) {
                $hasil[] = $this->periksaSatu($no, $isi, LokasiImpor::cari($lokasi, $teksLokasi), $teksLokasi, $bahan, $minimum, $sudah, false);

                continue;
            }

            $sasaran = LokasiImpor::sasaran($semua)->reject(fn (Cabang $l) => isset($langsung[$l->id.'|'.$kunciBahan($isi)]));

            if ($sasaran->isEmpty()) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI,
                    'data' => ['cabang_id' => null, 'bahan_baku_id' => null, 'lokasi' => $teksLokasi, 'bahan' => Str::squish((string) $isi['bahan']), 'satuan' => null, 'minimum' => null],
                    'pesan' => [LokasiImpor::sasaran($semua)->isEmpty() ? 'Belum ada lokasi aktif.' : 'Semua lokasinya sudah diisi di baris lain.'], 'catatan' => []];

                continue;
            }

            foreach ($sasaran as $l) {
                $hasil[] = $this->periksaSatu($no, $isi, $l, $teksLokasi, $bahan, $minimum, $sudah, true);
            }
        }

        return $hasil;
    }

    private function periksaSatu(int $no, array $isi, ?Cabang $l, string $teksLokasi, $bahan, $minimum, array &$sudah, bool $dariSemua): array
    {
        $namaBahan = Str::squish((string) $isi['bahan']);
        $b = $bahan[mb_strtolower($namaBahan)] ?? null;
        $nilai = blank($isi['minimum']) ? 0 : Angka::baca($isi['minimum']);
        $pesan = [];
        $catatan = $dariSemua ? ["Dari {$teksLokasi}."] : [];

        if (! $l) {
            $pesan[] = $teksLokasi === '' ? 'Lokasi wajib diisi.' : "Lokasi {$teksLokasi} tidak dikenal.";
        } elseif (! $l->aktif) {
            $pesan[] = "{$l->nama} sedang nonaktif.";
        }

        if (! $b) {
            $pesan[] = $namaBahan === '' ? 'Nama bahan wajib diisi.' : "Bahan {$namaBahan} tidak ada di daftar bahan baku.";
        } elseif (! $b->aktif) {
            $pesan[] = "{$b->nama} sedang nonaktif.";
        }

        if (! is_numeric($nilai) || $nilai < 0 || $nilai > StokMinimumRequest::MAKS_NILAI) {
            $pesan[] = 'Minimum diisi angka 0 atau lebih, mis. 2,5.';
        }

        $kunci = $l && $b ? $l->id.'|'.$b->id : null;

        if ($kunci && isset($sudah[$kunci])) {
            $pesan[] = "{$b->nama} di {$l->nama} sudah ada di baris {$sudah[$kunci]}.";
        }

        if ($kunci) {
            $sudah[$kunci] ??= $no;
        }

        $data = [
            'cabang_id'     => $l?->id,
            'bahan_baku_id' => $b?->id,
            'lokasi'        => $l?->nama ?? $teksLokasi,
            'bahan'         => $b?->nama ?? $namaBahan,
            'satuan'        => $b?->satuanBeli->nama,
            'minimum'       => is_numeric($nilai) ? (float) $nilai : null,
        ];

        $status = $pesan === [] ? self::STATUS_SIAP : self::STATUS_SALAH;

        if ($pesan === []) {
            $lama = $minimum[$kunci] ?? null;
            $baru = $b->keSatuanDasar($nilai);

            if ($lama === null && $baru <= 0) {
                $status = self::STATUS_DILEWATI;
                $pesan[] = 'Tanpa batas minimum, tidak ada yang diubah.';
            } elseif ($lama !== null && (float) $lama->minimum === $baru) {
                $status = self::STATUS_DILEWATI;
                $pesan[] = 'Sama dengan yang tersimpan.';
            } elseif ($lama !== null && $baru <= 0) {
                $catatan[] = 'Batas minimum dihapus.';
            } elseif ($lama !== null) {
                $catatan[] = 'Sebelumnya '.Angka::tampil($b->keSatuanBeli($lama->minimum)).' '.$b->satuanBeli->nama.'.';
            }
        }

        return ['baris' => $no, 'status' => $status, 'data' => $data, 'pesan' => $pesan, 'catatan' => $catatan];
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        return DB::transaction(function () use ($mentah, $oleh, $namaFile) {
            $hasil = collect($this->periksa($mentah));
            $siap = $hasil->where('status', self::STATUS_SIAP);

            foreach ($siap->groupBy('data.cabang_id') as $cabangId => $daftar) {
                BahanBakuMinimum::simpanUntukLokasi((int) $cabangId,
                    $daftar->mapWithKeys(fn ($r) => [$r['data']['bahan_baku_id'] => $r['data']['minimum']])->all());
            }

            if ($siap->isNotEmpty()) {
                $this->catatLog($oleh, $siap->count(), $namaFile);
            }

            return [
                'disimpan' => $siap->count(),
                'dilewati' => $hasil->count() - $siap->count(),
                'catatan'  => [],
            ];
        });
    }

    public function ringkasan(array $data): array
    {
        return [
            $data['bahan'] !== '' ? $data['bahan'] : '(tanpa nama bahan)',
            collect([
                $data['lokasi'],
                $data['minimum'] !== null ? ($data['minimum'] > 0 ? 'Minimum '.Angka::tampil($data['minimum']).' '.$data['satuan'] : 'Tanpa minimum') : null,
            ])->filter()->implode(' · '),
        ];
    }
}
