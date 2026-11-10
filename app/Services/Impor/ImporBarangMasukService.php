<?php

namespace App\Services\Impor;

use App\Http\Requests\Pusat\BarangMasukRequest;
use App\Models\BahanBaku;
use App\Models\BarangMasukDetail;
use App\Models\Cabang;
use App\Models\User;
use App\Services\BarangMasukService;
use App\Services\LogPerubahanService;
use App\Support\Angka;
use App\Support\ContohTemplat;
use App\Support\LembarExcel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Impor barang masuk Gudang Pusat dari Excel. Satu baris = satu bahan, jumlah dalam satuan beli.
 * Kolom lain di file (mis. kolom supplier dari templat lama) diabaikan.
 * Baris dengan tanggal yang sama menjadi satu dokumen barang masuk berstatus menunggu persetujuan Admin Utama,
 * persis seperti dicatat lewat form. Bila satu baris salah, dokumen tanggal itu tidak dibuat.
 */
class ImporBarangMasukService extends ImporExcel
{
    private ?Cabang $lokasi = null;

    public function __construct(
        LogPerubahanService $log,
        private BarangMasukService $barangMasuk,
    ) {
        parent::__construct($log);
    }

    /** Gudang tempat barang diterima. Wajib dipanggil sebelum ekspor, periksa, atau simpan. */
    public function diLokasi(Cabang $lokasi): static
    {
        $this->lokasi = $lokasi;

        return $this;
    }

    public function judul(): string
    {
        return 'Barang masuk';
    }

    public function satuanHasil(): string
    {
        return 'barang';
    }

    protected function isiTemplat(): array
    {
        return ContohTemplat::bahan()->values()->map(fn (array $b, int $i) => [
            'tanggal' => now()->toDateString(), 'bahan' => $b['nama'], 'jumlah' => ContohTemplat::angka($i), 'satuan' => $b['satuan_beli'],
            'keterangan' => 'Nota 0123',
        ])->all();
    }

    public function kolom(): array
    {
        return [
            'tanggal'             => ['lebar' => 14, 'ket' => 'Wajib. Tanggal barang diterima, HH/BB/TTTT, mis. '.now()->format('d/m/Y').', tidak boleh setelah hari ini. Satu tanggal = satu dokumen.', 'contoh' => now()->toDateString(), 'tanggal' => true],
            'bahan'               => ['lebar' => 28, 'ket' => 'Wajib. Nama bahan baku persis seperti di daftar bahan.', 'contoh' => 'Beras Jepang'],
            'jumlah'              => ['lebar' => 10, 'ket' => 'Wajib. Jumlah diterima dalam satuan bahan, mis. 2,5. Kosong = dilewati.', 'contoh' => 25],
            'satuan'              => ['lebar' => 10, 'ket' => 'Satuan bahan, mis. kg. Bila diisi gram atau ml, angkanya dikonversi otomatis.', 'contoh' => 'kg'],
            'keterangan'          => ['lebar' => 24, 'ket' => 'Boleh kosong, maksimal '.BarangMasukRequest::MAKS_KETERANGAN.' karakter.', 'contoh' => 'Nota 0123'],
        ];
    }

    protected function kolomSatuanJumlah(): ?string
    {
        return 'satuan';
    }

    protected function kolomJumlahPintar(): array
    {
        return ['jumlah'];
    }

    /** Bahan yang sama pada tanggal yang sama dijumlahkan. */
    protected function kunciGabung(array $isi): ?string
    {
        $bahan = mb_strtolower(Str::squish((string) ($isi['bahan'] ?? '')));

        return $bahan === '' ? null : Str::squish((string) ($isi['tanggal'] ?? '')).'|'.$bahan;
    }

    protected function kolomWajib(): array
    {
        return ['tanggal', 'bahan', 'jumlah'];
    }

    /** Satu tanggal menjadi satu dokumen barang masuk. */
    protected function kunciKelompok(array $isi): ?string
    {
        return (string) ($isi['tanggal'] ?? '');
    }

    protected function barisEkspor(): array
    {
        return BarangMasukDetail::query()
            ->with(['barangMasuk:id,tanggal', 'bahanBaku.satuanBeli'])
            ->whereHas('barangMasuk', fn ($q) => $q->diLokasi($this->lokasiDipakai()->id)
                ->whereDate('tanggal', '>=', now()->subDays(31)->toDateString()))
            ->orderByDesc('id')
            ->get()
            ->sortByDesc(fn (BarangMasukDetail $d) => $d->barangMasuk->tanggal->toDateString())
            ->map(fn (BarangMasukDetail $d) => [
                $d->barangMasuk->tanggal->toDateString(), $d->bahanBaku->nama, $d->bahanBaku->keSatuanBeli($d->qty),
                $d->bahanBaku->satuanBeli->nama, $d->keterangan,
            ])
            ->values()
            ->all();
    }

    public function periksa(array $mentah): array
    {
        $this->lokasiDipakai();
        $bahan = BahanBaku::query()->with('satuanBeli')->get()->keyBy(fn (BahanBaku $b) => mb_strtolower($b->nama));
        $hariIni = now()->toDateString();
        $hasil = [];
        $diperiksa = [];

        foreach ($mentah as $no => $isi) {
            $namaBahan = Str::squish((string) $isi['bahan']);
            $b = $bahan[mb_strtolower($namaBahan)] ?? null;
            $tanggal = filled($isi['tanggal']) ? LembarExcel::tanggal($isi['tanggal']) : null;
            $jumlah = Angka::baca($isi['jumlah']);
            $keterangan = self::teks($isi['keterangan']);

            $data = [
                'tanggal'             => $tanggal ?? (string) $isi['tanggal'],
                'bahan'               => $b?->nama ?? $namaBahan,
                'bahan_baku_id'       => $b?->id,
                'jumlah'              => is_numeric($jumlah) ? (float) $jumlah : null,
                'satuan'              => $b?->satuanBeli->nama,
                'keterangan'          => $keterangan,
            ];

            if (blank($isi['jumlah'])) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ['Jumlah kosong.'], 'catatan' => []];

                continue;
            }

            $pesan = [];

            if ($tanggal === null) {
                $pesan[] = blank($isi['tanggal']) ? 'Tanggal wajib diisi.' : 'Tanggal tidak terbaca. Pakai HH/BB/TTTT, mis. 16/10/2026.';
            } elseif ($tanggal > $hariIni) {
                $pesan[] = 'Tanggal barang masuk tidak boleh setelah hari ini.';
            }

            if (! $b) {
                $pesan[] = $namaBahan === '' ? 'Nama bahan wajib diisi.' : "Bahan {$namaBahan} tidak ada di daftar bahan baku.";
            } elseif (! $b->aktif) {
                $pesan[] = "Bahan {$b->nama} sedang nonaktif.";
            }

            if (! is_numeric($jumlah) || $jumlah <= 0 || $jumlah > BarangMasukRequest::MAKS_QTY) {
                $pesan[] = 'Jumlah harus angka lebih dari 0.';
            }

            if (mb_strlen((string) $keterangan) > BarangMasukRequest::MAKS_KETERANGAN) {
                $pesan[] = 'Keterangan maksimal '.BarangMasukRequest::MAKS_KETERANGAN.' karakter.';
            }

            $diperiksa[] = ['no' => $no, 'data' => $data, 'pesan' => $pesan, 'catatan' => []];
        }

        foreach (collect($diperiksa)->groupBy(fn ($r) => strlen($r['data']['tanggal']) === 10 ? $r['data']['tanggal'] : 'salah|'.$r['no']) as $daftar) {
            $salah = $daftar->contains(fn ($r) => $r['pesan'] !== []);

            foreach ($daftar as $r) {
                $hasil[] = [
                    'baris'   => $r['no'],
                    'status'  => $salah ? self::STATUS_SALAH : self::STATUS_SIAP,
                    'data'    => $r['data'],
                    'pesan'   => $salah && $r['pesan'] === [] ? ['Dokumen tanggal ini tidak dibuat karena ada baris lain yang salah.'] : $r['pesan'],
                    'catatan' => $salah ? [] : $r['catatan'],
                ];
            }
        }

        return $hasil;
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        $lokasi = $this->lokasiDipakai();

        return DB::transaction(function () use ($mentah, $oleh, $namaFile, $lokasi) {
            $hasil = collect($this->periksa($mentah));
            $siap = $hasil->where('status', self::STATUS_SIAP);
            $dokumen = [];

            foreach ($siap->groupBy('data.tanggal')->sortKeys() as $tanggal => $daftar) {
                $dokumen[] = $this->barangMasuk->catat($oleh, $lokasi, [
                    'tanggal' => $tanggal,
                    'catatan' => Str::limit('Diimpor dari Excel'.($namaFile ? ' ('.$namaFile.')' : ''), BarangMasukRequest::MAKS_CATATAN),
                    'baris'   => $daftar->map(fn ($r) => [
                        'bahan_baku_id'       => $r['data']['bahan_baku_id'],
                        'qty'                 => $r['data']['jumlah'],
                        'keterangan'          => $r['data']['keterangan'],
                    ])->values()->all(),
                ])->nomor;
            }

            if ($siap->isNotEmpty()) {
                $this->catatLog($oleh, $siap->count(), $namaFile);
            }

            return [
                'disimpan' => $siap->count(),
                'dilewati' => $hasil->count() - $siap->count(),
                'catatan'  => $dokumen === [] ? [] : [count($dokumen).' dokumen menunggu persetujuan Admin Utama ('.implode(', ', $dokumen).')'],
            ];
        });
    }

    public function ringkasan(array $data): array
    {
        $tanggal = strlen($data['tanggal']) === 10 ? Carbon::parse($data['tanggal'])->translatedFormat('j M Y') : $data['tanggal'];

        return [
            $data['bahan'] !== '' ? $data['bahan'] : '(tanpa nama bahan)',
            collect([
                $tanggal,
                $data['jumlah'] !== null ? Angka::tampil($data['jumlah']).' '.$data['satuan'] : null,
            ])->filter()->implode(' · '),
        ];
    }

    private function lokasiDipakai(): Cabang
    {
        return $this->lokasi ?? throw new LogicException('Lokasi barang masuk belum dipilih.');
    }
}
