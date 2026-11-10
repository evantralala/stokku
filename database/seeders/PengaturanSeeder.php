<?php

namespace Database\Seeders;

use App\Models\Pengaturan;
use Illuminate\Database\Seeder;

class PengaturanSeeder extends Seeder
{
    public function run(): void
    {
        $daftar = [
            'batas_jam_persetujuan' => [
                'nilai' => '24',
                'label' => 'Batas Waktu Persetujuan',
                'tipe' => Pengaturan::TIPE_BILANGAN,
                'satuan' => 'jam',
                'nilai_min' => 1,
                'nilai_maks' => 168,
                'keterangan' => 'Permintaan yang belum diputuskan Admin Utama setelah waktu ini otomatis ditolak.',
            ],
            'jam_permintaan_otomatis' => [
                'nilai' => '06:00',
                'label' => 'Jam Permintaan Otomatis',
                'tipe' => Pengaturan::TIPE_JAM,
                'satuan' => null,
                'nilai_min' => null,
                'nilai_maks' => null,
                'keterangan' => 'Setiap hari pada jam ini sistem membuat permintaan untuk bahan yang stoknya di bawah minimum.',
            ],
            'jam_ganti_hari_penjualan' => [
                'nilai' => '06:00',
                'label' => 'Jam Ganti Hari Penjualan',
                'tipe' => Pengaturan::TIPE_JAM,
                'satuan' => null,
                'nilai_min' => null,
                'nilai_maks' => null,
                'keterangan' => 'Sebelum jam ini tanggal penjualan masih dianggap kemarin, karena penjualan dicatat setelah closing tengah malam.',
            ],
            'toleransi_default' => [
                'nilai' => '5',
                'label' => 'Toleransi Selisih Bawaan',
                'tipe' => Pengaturan::TIPE_PERSEN,
                'satuan' => '%',
                'nilai_min' => 0,
                'nilai_maks' => 100,
                'keterangan' => 'Nilai awal toleransi selisih saat menambah bahan baku baru.',
            ],
            'ambang_stok_menipis' => [
                'nilai' => '20',
                'label' => 'Ambang Stok Menipis',
                'tipe' => Pengaturan::TIPE_PERSEN,
                'satuan' => '%',
                'nilai_min' => 0,
                'nilai_maks' => 1000,
                'keterangan' => 'Stok dianggap menipis bila sisanya tidak lebih dari minimum ditambah persen ini.',
            ],
            'persen_cadangan_minta' => [
                'nilai' => '20',
                'label' => 'Cadangan Isi Stok',
                'tipe' => Pengaturan::TIPE_PERSEN,
                'satuan' => '%',
                'nilai_min' => 0,
                'nilai_maks' => 500,
                'keterangan' => 'Isi stok dari halaman Stok dan permintaan otomatis cabang memakai stok minimum ditambah persen ini.',
            ],
        ];

        $urutan = 0;

        foreach ($daftar as $kunci => $data) {
            $pengaturan = Pengaturan::firstOrNew(['kunci' => $kunci]);

            // Nilai yang sudah diubah Admin Utama tidak ditimpa; keterangan lainnya selalu diperbarui.
            if (! $pengaturan->exists) {
                $pengaturan->nilai = $data['nilai'];
            }

            $pengaturan->fill(collect($data)->except('nilai')->all() + ['urutan' => ++$urutan]);
            $pengaturan->save();
        }
    }
}
