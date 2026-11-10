<?php

namespace App\Services\Impor;

use App\Http\Requests\OpnameHitungRequest;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\User;
use App\Services\LogPerubahanService;
use App\Services\OpnameService;
use App\Support\Angka;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Stock opname dari Excel. Templat berisi semua bahan yang dihitung di lokasi itu beserta stok sistemnya;
 * petugas mengisi kolom Hitung Fisik (satuan bahan), lalu unggah. Satu file = satu opname yang langsung dikirim ke
 * Admin Utama (OpnameService::dariExcel). Bahan yang tidak ada di file atau Hitung Fisik-nya kosong dianggap 0.
 * Bila satu baris salah, opname tidak dibuat. Bahan yang sama di beberapa baris dijumlahkan.
 */
class ImporOpnameService extends ImporExcel
{
    private ?Cabang $lokasi = null;

    public function __construct(
        LogPerubahanService $log,
        private OpnameService $opname,
    ) {
        parent::__construct($log);
    }

    /** Lokasi yang diopname. Wajib dipanggil sebelum templat, periksa, atau simpan. */
    public function diLokasi(Cabang $lokasi): static
    {
        $this->lokasi = $lokasi;

        return $this;
    }

    public function judul(): string
    {
        return 'Opname';
    }

    public function satuanHasil(): string
    {
        return 'bahan';
    }

    public function teksUnggah(): array
    {
        return [
            'Impor Opname',
            'Unduh templat berisi semua bahan dan stok sistem, isi kolom Hitung Fisik, lalu unggah. Opname langsung dikirim ke Admin Utama. Bahan yang tidak diisi dianggap 0.',
            'Unduh Templat',
        ];
    }

    public function kolom(): array
    {
        return [
            'kategori'     => ['lebar' => 18, 'ket' => 'Hanya keterangan, tidak dibaca saat impor.', 'contoh' => 'Bahan Kering', 'info' => true],
            'bahan'        => ['lebar' => 30, 'ket' => 'Wajib. Nama bahan baku persis seperti di daftar bahan.', 'contoh' => 'Beras Jepang'],
            'satuan'       => ['lebar' => 10, 'ket' => 'Satuan bahan, mis. kg. Bila diisi gram atau ml, angkanya dikonversi otomatis.', 'contoh' => 'kg'],
            'stok_sistem'  => ['lebar' => 12, 'ket' => 'Stok sistem saat templat diunduh. Hanya keterangan, tidak dibaca saat impor.', 'contoh' => 25, 'info' => true],
            'hitung_fisik' => ['lebar' => 13, 'ket' => 'Wajib. Hasil hitung fisik dalam satuan bahan, mis. 24,5 atau 2x10 kg. Kosong = 0.', 'contoh' => 24.5],
            'catatan'      => ['lebar' => 26, 'ket' => 'Boleh kosong, maksimal '.OpnameHitungRequest::MAKS_CATATAN.' karakter.', 'contoh' => 'Rak atas'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['bahan', 'hitung_fisik'];
    }

    protected function kolomSatuanJumlah(): ?string
    {
        return 'satuan';
    }

    protected function kolomJumlahPintar(): array
    {
        return ['hitung_fisik'];
    }

    protected function kunciGabung(array $isi): ?string
    {
        $bahan = mb_strtolower(Str::squish((string) ($isi['bahan'] ?? '')));

        return $bahan === '' ? null : $bahan;
    }

    /** Semua baris menjadi satu opname, jadi tebakan yang belum dijawab menahan seluruh file. */
    protected function kunciKelompok(array $isi): ?string
    {
        return 'opname';
    }

    protected function isiTemplat(): array
    {
        return $this->daftarBahan()->map(fn (array $b) => [
            'kategori'     => $b['bahan']->kategori?->nama,
            'bahan'        => $b['bahan']->nama,
            'satuan'       => $b['bahan']->satuanBeli->nama,
            'stok_sistem'  => $b['bahan']->keSatuanBeli($b['sistem']),
            'hitung_fisik' => null,
            'catatan'      => null,
        ])->values()->all();
    }

    protected function barisEkspor(): array
    {
        return array_map('array_values', $this->isiTemplat());
    }

    public function namaFile(string $jenis): string
    {
        return Str::slug(($jenis === 'templat' ? 'templat ' : '').'opname '.$this->lokasiDipakai()->kode.' '.now()->format('Y-m-d')).'.xlsx';
    }

    public function periksa(array $mentah): array
    {
        $lokasi = $this->lokasiDipakai();
        $dihitung = $this->opname->bahanDihitung($lokasi);
        $bahan = BahanBaku::query()->with('satuanBeli:id,nama')->get()->keyBy(fn (BahanBaku $b) => mb_strtolower($b->nama));
        $diperiksa = [];

        foreach ($mentah as $no => $isi) {
            $namaBahan = Str::squish((string) ($isi['bahan'] ?? ''));
            $b = $bahan[mb_strtolower($namaBahan)] ?? null;
            $kosong = blank($isi['hitung_fisik'] ?? null);
            $fisik = $kosong ? 0.0 : Angka::baca($isi['hitung_fisik']);
            $catatan = self::teks($isi['catatan'] ?? null);

            $data = [
                'bahan'         => $b?->nama ?? $namaBahan,
                'bahan_baku_id' => $b?->id,
                'fisik'         => is_numeric($fisik) ? (float) $fisik : null,
                'satuan'        => $b?->satuanBeli->nama,
                'catatan'       => $catatan,
                'diisi'         => ! $kosong,
            ];

            $pesan = [];

            if (! $b) {
                $pesan[] = $namaBahan === '' ? 'Nama bahan wajib diisi.' : "Bahan {$namaBahan} tidak ada di daftar bahan baku.";
            } elseif (! $dihitung->has($b->id)) {
                $pesan[] = "Bahan {$b->nama} sedang nonaktif dan stoknya kosong, jadi tidak ikut opname.";
            }

            if (! is_numeric($fisik) || $fisik < 0 || $fisik > OpnameHitungRequest::MAKS_QTY) {
                $pesan[] = 'Hitung Fisik harus angka 0 atau lebih, mis. 24,5.';
            }

            if (mb_strlen((string) $catatan) > OpnameHitungRequest::MAKS_CATATAN) {
                $pesan[] = 'Catatan maksimal '.OpnameHitungRequest::MAKS_CATATAN.' karakter.';
            }

            $diperiksa[] = ['baris' => $no, 'data' => $data, 'pesan' => $pesan,
                'catatan' => $kosong && $pesan === [] ? ['Hitung Fisik kosong, dianggap 0.'] : []];
        }

        $umum = match (true) {
            ($hambatan = $this->opname->hambatanMulai($lokasi->fresh())) !== null => $hambatan,
            $diperiksa !== [] && collect($diperiksa)->doesntContain('data.diisi', true) => 'Kolom Hitung Fisik masih kosong semua. Isi hasil hitung dulu.',
            default => null,
        };
        $adaSalah = collect($diperiksa)->contains(fn ($r) => $r['pesan'] !== []);

        return array_map(function (array $r) use ($umum, $adaSalah) {
            $pesan = $umum !== null ? [$umum, ...$r['pesan']] : $r['pesan'];

            if ($pesan === [] && $adaSalah) {
                $pesan = ['Opname tidak dibuat karena ada baris lain yang salah.'];
            }

            return [
                'baris'   => $r['baris'],
                'status'  => $pesan === [] ? self::STATUS_SIAP : self::STATUS_SALAH,
                'data'    => $r['data'],
                'pesan'   => $pesan,
                'catatan' => $pesan === [] ? $r['catatan'] : [],
            ];
        }, $diperiksa);
    }

    public function teksBarisSalah(): string
    {
        return 'Opname tidak dibuat selama masih ada baris salah isi. Betulkan di file lalu unggah ulang.';
    }

    public function catatanPratinjau(array $baris): array
    {
        $diFile = collect($baris)->pluck('data.bahan_baku_id')->filter()->all();
        $tidakAda = $this->daftarBahan()->reject(fn (array $b) => in_array($b['bahan']->id, $diFile, true))->pluck('bahan.nama');

        if ($tidakAda->isEmpty()) {
            return [];
        }

        $nama = $tidakAda->take(5)->implode(', ').($tidakAda->count() > 5 ? ', dan '.($tidakAda->count() - 5).' lainnya' : '');

        return [$tidakAda->count().' bahan tidak ada di file dan dianggap 0: '.$nama.'.'];
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        $lokasi = $this->lokasiDipakai();

        return DB::transaction(function () use ($mentah, $oleh, $namaFile, $lokasi) {
            $hasil = collect($this->periksa($mentah));
            $salah = $hasil->firstWhere('status', self::STATUS_SALAH);

            if ($salah !== null) {
                throw new DomainException('Opname tidak dibuat: '.$salah['pesan'][0].' Betulkan file lalu unggah ulang.');
            }

            $hitung = $hasil->mapWithKeys(fn ($r) => [$r['data']['bahan_baku_id'] => [
                'qty'     => $r['data']['fisik'],
                'catatan' => $r['data']['catatan'],
            ]])->all();

            $opname = $this->opname->dariExcel($oleh, $lokasi, $hitung);
            $this->catatLog($oleh, count($hitung), $namaFile);
            $nol = $opname->detail()->count() - count($hitung);

            return [
                'disimpan' => count($hitung),
                'dilewati' => 0,
                'catatan'  => array_values(array_filter([
                    $nol > 0 ? $nol.' bahan lain dianggap 0' : null,
                    'opname '.$opname->nomor.' dikirim ke Admin Utama',
                ])),
            ];
        });
    }

    public function ringkasan(array $data): array
    {
        return [
            $data['bahan'] !== '' ? $data['bahan'] : '(tanpa nama bahan)',
            $data['fisik'] !== null ? 'Hitung fisik '.Angka::tampil($data['fisik']).' '.$data['satuan'] : '',
        ];
    }

    /**
     * Bahan yang dihitung di lokasi ini, urut kategori lalu nama.
     *
     * @return Collection<int, array{bahan: BahanBaku, sistem: float}>
     */
    private function daftarBahan(): Collection
    {
        $dihitung = $this->opname->bahanDihitung($this->lokasiDipakai());

        return BahanBaku::query()
            ->whereKey($dihitung->keys()->all())
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (BahanBaku $b) => [$b->kategori?->urutan ?? PHP_INT_MAX, $b->kategori?->nama, $b->nama])
            ->values()
            ->map(fn (BahanBaku $b) => ['bahan' => $b, 'sistem' => (float) $dihitung[$b->id]]);
    }

    private function lokasiDipakai(): Cabang
    {
        return $this->lokasi ?? throw new LogicException('Lokasi opname belum dipilih.');
    }
}
