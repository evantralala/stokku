<?php

namespace App\Services;

use App\Models\BahanBakuMinimum;
use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Models\LogPerubahan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan Gudang Pusat ke lokasi baru dalam satu langkah:
 * lokasi baru dibuat sebagai Gudang Pusat aktif, semua stok yang ada dipindah lewat satu kiriman (langsung diterima),
 * Admin Pusat dan stok minimum ikut pindah, lalu Gudang Pusat lama dinonaktifkan. Riwayat gudang lama tetap tersimpan.
 * Bahan yang stoknya minus tidak ikut dipindah; betulkan dulu lewat stock opname bila perlu.
 */
class PindahGudangPusatService
{
    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
        private LogPerubahanService $log,
    ) {}

    /**
     * @return array{bahan: int, minus: int, admin: int, minimum: int, hambatan: list<string>}
     */
    public function ringkasan(Cabang $pusat): array
    {
        $saldo = DB::table('stok_saldo')->where('cabang_id', $pusat->id);

        return [
            'bahan'    => (clone $saldo)->where('saldo', '>', 0)->count(),
            'minus'    => (clone $saldo)->where('saldo', '<', 0)->count(),
            'admin'    => User::query()->where('cabang_id', $pusat->id)->where('aktif', true)->count(),
            'minimum'  => BahanBakuMinimum::query()->where('cabang_id', $pusat->id)->count(),
            'hambatan' => $this->hambatan($pusat),
        ];
    }

    /**
     * Pekerjaan di Gudang Pusat lama yang harus selesai dulu, supaya tidak ada transaksi yang tertinggal di lokasi nonaktif.
     *
     * @return list<string>
     */
    public function hambatan(Cabang $pusat): array
    {
        $hambatan = [];

        if (Cabang::query()->whereKey($pusat->id)->value('opname_aktif')) {
            $hambatan[] = 'Stock opname di '.$pusat->nama.' belum selesai. Selesaikan atau batalkan dulu.';
        }

        $menunggu = StokMutasi::query()->menunggu()->where('cabang_id', $pusat->id)->distinct()->count('dokumen_id');

        if ($menunggu > 0) {
            $hambatan[] = $menunggu.' transaksi di '.$pusat->nama.' masih menunggu persetujuan atau penerimaan.';
        }

        $revisi = BarangMasuk::query()->where('cabang_id', $pusat->id)->where('status', BarangMasuk::STATUS_REVISI)->count()
            + BarangKeluar::query()->where('cabang_id', $pusat->id)->where('status', BarangKeluar::STATUS_REVISI)->count();

        if ($revisi > 0) {
            $hambatan[] = $revisi.' dokumen di '.$pusat->nama.' masih perlu diperbaiki. Perbaiki atau batalkan dulu.';
        }

        $retur = Kiriman::query()->where('ke_cabang_id', $pusat->id)->where('status', Kiriman::STATUS_DIKIRIM)->count();

        if ($retur > 0) {
            $hambatan[] = $retur.' kiriman ke '.$pusat->nama.' masih dalam perjalanan. Terima dulu.';
        }

        return $hambatan;
    }

    /**
     * @param  array{kode: string, nama: string, alamat?: ?string}  $data
     * @return array{pusat: Cabang, kiriman: ?Kiriman, bahan: int, minus: int, admin: int, minimum: int}
     *
     * @throws DomainException bila belum ada Gudang Pusat aktif atau masih ada pekerjaan yang belum selesai
     */
    public function pindahkan(User $oleh, array $data): array
    {
        return DB::transaction(function () use ($oleh, $data) {
            $lama = Cabang::query()->pusat()->aktif()->lockForUpdate()->first();

            if ($lama === null) {
                throw new DomainException('Belum ada Gudang Pusat aktif yang bisa dipindah.');
            }

            $hambatan = $this->hambatan($lama);

            if ($hambatan !== []) {
                throw new DomainException(implode(' ', $hambatan));
            }

            $lama->update(['aktif' => false]);

            $baru = Cabang::create([
                'kode'              => $data['kode'],
                'nama'              => $data['nama'],
                'alamat'            => $data['alamat'] ?? null,
                'jenis'             => Cabang::JENIS_PUSAT,
                'aktif'             => true,
            ]);

            $saldo = DB::table('stok_saldo')
                ->where('cabang_id', $lama->id)
                ->orderBy('bahan_baku_id')
                ->lockForUpdate()
                ->get(['bahan_baku_id', 'saldo']);

            $pindah = $saldo->filter(fn ($s) => round((float) $s->saldo, 3) > 0);
            $kiriman = $pindah->isEmpty() ? null : $this->kirimSemuaStok($oleh, $lama, $baru, $pindah->all());

            $admin = User::query()->where('cabang_id', $lama->id)->update(['cabang_id' => $baru->id]);
            $minimum = BahanBakuMinimum::query()->where('cabang_id', $lama->id)->update(['cabang_id' => $baru->id]);
            $minus = $saldo->count(fn ($s) => round((float) $s->saldo, 3) < 0);

            $this->notifikasi->keLokasi(
                $baru,
                'Gudang Pusat pindah ke '.$baru->nama,
                'Stok dari '.$lama->nama.' sudah dipindah'.($kiriman ? ' ('.$kiriman->nomor.')' : '').'. Transaksi berikutnya dicatat di '.$baru->nama.'.',
                route('pusat.stok', [], false)
            );

            $this->log->catat(
                $oleh,
                LogPerubahan::KATEGORI_DATA,
                'lokasi.pindah-pusat',
                "Gudang Pusat dipindah dari {$lama->nama} ke {$baru->nama}: {$pindah->count()} bahan, {$admin} Admin Pusat, {$minimum} stok minimum.",
                ['dari' => $lama->nama, 'ke' => $baru->nama, 'kiriman' => $kiriman?->nomor, 'bahan_minus' => $minus],
                $baru
            );

            return [
                'pusat'   => $baru,
                'kiriman' => $kiriman,
                'bahan'   => $pindah->count(),
                'minus'   => $minus,
                'admin'   => $admin,
                'minimum' => $minimum,
            ];
        });
    }

    /** @param  list<object{bahan_baku_id: int, saldo: string}>  $saldo */
    private function kirimSemuaStok(User $oleh, Cabang $lama, Cabang $baru, array $saldo): Kiriman
    {
        $sekarang = now();

        $kiriman = Kiriman::create([
            'nomor'          => NomorDokumen::berikutnya('kiriman', Kiriman::AWALAN_NOMOR, $lama, $sekarang),
            'dari_cabang_id' => $lama->id,
            'ke_cabang_id'   => $baru->id,
            'permintaan_id'  => null,
            'dikirim_oleh'   => $oleh->id,
            'dikirim_pada'   => $sekarang,
            'sampai_pada'    => $sekarang,
            'catatan_pusat'  => "Pindah Gudang Pusat dari {$lama->nama} ke {$baru->nama}.",
            'status'         => Kiriman::STATUS_SELESAI,
        ]);

        $keluar = [];
        $masuk = [];

        foreach ($saldo as $s) {
            $qty = round((float) $s->saldo, 3);

            $detail = $kiriman->detail()->create([
                'bahan_baku_id'    => $s->bahan_baku_id,
                'qty_kirim'        => $qty,
                'qty_terima'       => $qty,
                'kondisi'          => KirimanDetail::KONDISI_BAIK,
                'dichecklist_oleh' => $oleh->id,
                'dichecklist_pada' => $sekarang,
            ]);

            $keluar[] = ['cabang_id' => $lama->id, 'bahan_baku_id' => $s->bahan_baku_id, 'jumlah' => -$qty, 'dokumen_detail_id' => $detail->id];
            $masuk[] = ['cabang_id' => $baru->id, 'bahan_baku_id' => $s->bahan_baku_id, 'jumlah' => $qty, 'dokumen_detail_id' => $detail->id];
        }

        $this->stok->catat($keluar, StokMutasi::JENIS_KIRIM_KELUAR, Kiriman::DOKUMEN_TIPE, $kiriman->id, $oleh->id);
        $this->stok->catat($masuk, StokMutasi::JENIS_KIRIM_MASUK, Kiriman::DOKUMEN_TIPE, $kiriman->id, $oleh->id);
        $this->stok->sahkan(Kiriman::DOKUMEN_TIPE, $kiriman->id);

        return $kiriman;
    }
}
