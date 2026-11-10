<?php

namespace App\Services\Impor;

use App\Http\Requests\Cabang\PermintaanRequest;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\User;
use App\Services\LogPerubahanService;
use App\Services\PermintaanService;
use App\Support\Angka;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Permintaan cabang dari Excel. Templat berisi semua bahan aktif beserta sisa stok dan minimum cabang; bahan di bawah
 * minimum sudah terisi usulan jumlah (sama dengan Isi Otomatis di form). Satu file = satu permintaan yang menunggu
 * persetujuan Admin Utama. Jumlah kosong atau 0 dilewati, baris yang salah dilewati, sisanya tetap diajukan.
 * Bahan yang sama di beberapa baris dijumlahkan.
 */
class ImporPermintaanService extends ImporExcel
{
    private ?Cabang $lokasi = null;

    public function __construct(
        LogPerubahanService $log,
        private PermintaanService $permintaan,
    ) {
        parent::__construct($log);
    }

    /** Cabang yang meminta. Wajib dipanggil sebelum templat, periksa, atau simpan. */
    public function diLokasi(Cabang $lokasi): static
    {
        $this->lokasi = $lokasi;

        return $this;
    }

    public function judul(): string
    {
        return 'Permintaan';
    }

    public function satuanHasil(): string
    {
        return 'bahan';
    }

    public function teksUnggah(): array
    {
        return [
            'Impor Permintaan',
            'Unduh templat berisi semua bahan; bahan di bawah minimum sudah terisi usulan. Ubah kolom Jumlah, lalu unggah. Jumlah kosong atau 0 tidak diminta. Tabel sendiri juga bisa.',
            'Unduh Templat',
        ];
    }

    public function kolom(): array
    {
        return [
            'kategori'  => ['lebar' => 18, 'ket' => 'Hanya keterangan, tidak dibaca saat impor.', 'contoh' => 'Bahan Kering', 'info' => true],
            'bahan'     => ['lebar' => 30, 'ket' => 'Wajib. Nama bahan baku persis seperti di daftar bahan.', 'contoh' => 'Beras Jepang'],
            'satuan'    => ['lebar' => 10, 'ket' => 'Satuan bahan, mis. kg. Bila diisi gram atau ml, angkanya dikonversi otomatis.', 'contoh' => 'kg'],
            'sisa_stok' => ['lebar' => 11, 'ket' => 'Stok cabang saat templat diunduh. Hanya keterangan, tidak dibaca saat impor.', 'contoh' => 3, 'info' => true],
            'minimum'   => ['lebar' => 11, 'ket' => 'Stok minimum cabang. Hanya keterangan, tidak dibaca saat impor.', 'contoh' => 10, 'info' => true],
            'jumlah'    => ['lebar' => 11, 'ket' => 'Jumlah yang diminta dalam satuan bahan, mis. 5 atau 2x10 kg. Kosong atau 0 = tidak diminta.', 'contoh' => 9],
            'alasan'    => ['lebar' => 26, 'ket' => 'Boleh kosong, maksimal '.PermintaanRequest::MAKS_ALASAN.' karakter.', 'contoh' => 'Stok di bawah minimum'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['bahan', 'jumlah'];
    }

    protected function sinonimKolom(): array
    {
        $sinonim = parent::sinonimKolom();
        $sinonim['jumlah'] = [...$sinonim['jumlah'], 'Diminta', 'Jumlah Diminta', 'Minta', 'Jumlah Minta', 'Permintaan', 'Order', 'Pesan', 'Jumlah Pesan'];
        $sinonim['alasan'] = ['Keterangan', 'Ket', 'Catatan', 'Note'];

        return $sinonim;
    }

    protected function kolomSatuanJumlah(): ?string
    {
        return 'satuan';
    }

    protected function kolomJumlahPintar(): array
    {
        return ['jumlah'];
    }

    protected function kunciGabung(array $isi): ?string
    {
        $bahan = mb_strtolower(Str::squish((string) ($isi['bahan'] ?? '')));

        return $bahan === '' ? null : $bahan;
    }

    /** Jumlah kosong atau 0 pasti dilewati, jadi tebakan nama bahannya tidak perlu ditanyakan. */
    protected function barisTanpaTanya(array $isi): bool
    {
        $jumlah = Angka::baca($isi['jumlah'] ?? null);

        return blank($isi['jumlah'] ?? null) || (is_numeric($jumlah) && (float) $jumlah == 0.0);
    }

    protected function isiTemplat(): array
    {
        $lokasi = $this->lokasiDipakai();
        $bahan = BahanBaku::query()
            ->aktif()
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (BahanBaku $b) => [$b->kategori?->urutan ?? PHP_INT_MAX, $b->kategori?->nama, $b->nama])
            ->values();
        $kondisi = $this->permintaan->kondisiStok($lokasi, $bahan->pluck('id')->all());
        $usulan = $this->permintaan->usulan($lokasi)->keyBy(fn (array $u) => $u['bahan']->id);

        return $bahan->map(fn (BahanBaku $b) => [
            'kategori'  => $b->kategori?->nama,
            'bahan'     => $b->nama,
            'satuan'    => $b->satuanBeli->nama,
            'sisa_stok' => $b->keSatuanBeli($kondisi[$b->id]['saldo']),
            'minimum'   => $kondisi[$b->id]['minimum'] > 0 ? $b->keSatuanBeli($kondisi[$b->id]['minimum']) : null,
            'jumlah'    => isset($usulan[$b->id]) ? $usulan[$b->id]['qty_beli'] : null,
            'alasan'    => isset($usulan[$b->id]) ? 'Stok di bawah minimum' : null,
        ])->all();
    }

    protected function barisEkspor(): array
    {
        return array_map('array_values', $this->isiTemplat());
    }

    public function namaFile(string $jenis): string
    {
        return Str::slug(($jenis === 'templat' ? 'templat ' : '').'permintaan '.$this->lokasiDipakai()->kode.' '.now()->format('Y-m-d')).'.xlsx';
    }

    public function periksa(array $mentah): array
    {
        $this->lokasiDipakai();
        $bahan = BahanBaku::query()->with('satuanBeli:id,nama')->get()->keyBy(fn (BahanBaku $b) => mb_strtolower($b->nama));
        $hasil = [];

        foreach ($mentah as $no => $isi) {
            $namaBahan = Str::squish((string) ($isi['bahan'] ?? ''));
            $b = $bahan[mb_strtolower($namaBahan)] ?? null;
            $jumlah = Angka::baca($isi['jumlah'] ?? null);
            $alasan = self::teks($isi['alasan'] ?? null);

            $data = [
                'bahan'         => $b?->nama ?? $namaBahan,
                'bahan_baku_id' => $b?->id,
                'jumlah'        => is_numeric($jumlah) ? (float) $jumlah : null,
                'satuan'        => $b?->satuanBeli->nama,
                'alasan'        => $alasan,
            ];

            if ($this->barisTanpaTanya($isi)) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ['Tidak diminta (jumlah kosong atau 0).'], 'catatan' => []];

                continue;
            }

            $pesan = [];

            if (! $b) {
                $pesan[] = $namaBahan === '' ? 'Nama bahan wajib diisi.' : "Bahan {$namaBahan} tidak ada di daftar bahan baku.";
            } elseif (! $b->aktif) {
                $pesan[] = "Bahan {$b->nama} sedang nonaktif.";
            }

            if (! is_numeric($jumlah) || $jumlah <= 0 || $jumlah > PermintaanRequest::MAKS_QTY) {
                $pesan[] = 'Jumlah harus angka lebih dari 0, mis. 5 atau 2,5.';
            }

            if (mb_strlen((string) $alasan) > PermintaanRequest::MAKS_ALASAN) {
                $pesan[] = 'Alasan maksimal '.PermintaanRequest::MAKS_ALASAN.' karakter.';
            }

            $hasil[] = ['baris' => $no, 'status' => $pesan === [] ? self::STATUS_SIAP : self::STATUS_SALAH, 'data' => $data, 'pesan' => $pesan, 'catatan' => []];
        }

        return $hasil;
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        $lokasi = $this->lokasiDipakai();

        return DB::transaction(function () use ($mentah, $oleh, $namaFile, $lokasi) {
            $hasil = collect($this->periksa($mentah));
            $siap = $hasil->where('status', self::STATUS_SIAP);

            if ($siap->isEmpty()) {
                return ['disimpan' => 0, 'dilewati' => $hasil->count(), 'catatan' => []];
            }

            $permintaan = $this->permintaan->ajukan($oleh, $lokasi, [
                'baris' => $siap->map(fn ($r) => [
                    'bahan_baku_id' => $r['data']['bahan_baku_id'],
                    'qty'           => $r['data']['jumlah'],
                    'alasan'        => $r['data']['alasan'],
                ])->values()->all(),
            ]);
            $this->catatLog($oleh, $siap->count(), $namaFile);

            return [
                'disimpan' => $siap->count(),
                'dilewati' => $hasil->count() - $siap->count(),
                'catatan'  => ['permintaan '.$permintaan->nomor.' menunggu persetujuan Admin Utama'],
            ];
        });
    }

    public function ringkasan(array $data): array
    {
        return [
            $data['bahan'] !== '' ? $data['bahan'] : '(tanpa nama bahan)',
            $data['jumlah'] !== null && $data['jumlah'] > 0 ? 'Diminta '.Angka::tampil($data['jumlah']).' '.$data['satuan'] : '',
        ];
    }

    private function lokasiDipakai(): Cabang
    {
        return $this->lokasi ?? throw new LogicException('Cabang peminta belum dipilih.');
    }
}
