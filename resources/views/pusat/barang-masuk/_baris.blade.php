{{--
    Satu baris barang di form barang masuk.
    Variabel: $i (indeks atau "__i__" untuk templat), $baris (['bahan_baku_id', 'qty', 'keterangan']),
              $infoBahan ([id => ['nama', 'satuan']]), $maksKeterangan.
--}}
@php $info = $infoBahan[$baris['bahan_baku_id'] ?? 0] ?? null; @endphp

<div class="baris-transaksi" data-baris>
    <div class="baris-masuk-isi">
        <x-form.pilih-bahan :name="'baris['.$i.'][bahan_baku_id]'" label="Bahan" wajib
                            :value="$baris['bahan_baku_id'] ?? null" :teks="$info['nama'] ?? null" />

        <x-isian-jumlah bebas :name="'baris['.$i.'][qty]'" label="Jumlah Diterima" wajib desimal ringkas maxlength="24"
                        :value="$baris['qty'] ?? ''" :satuan="$info['satuan'] ?? ''" placeholder="0" data-jumlah />

        <x-form.teks :name="'baris['.$i.'][keterangan]'" label="Keterangan" :value="$baris['keterangan'] ?? null"
                     :maxlength="$maksKeterangan" autocomplete="off" placeholder="Opsional, mis. kemasan rusak 1" />
    </div>

    <button type="button" class="btn btn-hapus baris-hapus" data-hapus-baris>
        <x-ikon nama="silang" />
        <span>Hapus Barang</span>
    </button>
</div>
