{{--
    Satu baris bahan di form kirim barang.
    Variabel: $i (indeks atau "__i__" untuk templat), $baris (['bahan_baku_id', 'qty']), $infoBahan ([id => ['nama', 'satuan', 'info']]).
--}}
@php
    $info = $infoBahan[$baris['bahan_baku_id'] ?? 0] ?? null;
@endphp

<div class="baris-transaksi" data-baris>
    <div class="baris-minta-isi">
        <x-form.pilih-bahan :name="'baris['.$i.'][bahan_baku_id]'" label="Bahan" wajib
                            :value="$baris['bahan_baku_id'] ?? null" :teks="$info['nama'] ?? null" />

        <x-isian-jumlah bebas :name="'baris['.$i.'][qty]'" label="Jumlah Dikirim" wajib desimal ringkas maxlength="24"
                        :value="$baris['qty'] ?? ''" :satuan="$info['satuan'] ?? ''" placeholder="0" data-jumlah />

        <p class="info-stok" data-info-stok aria-live="polite">{{ $info['info'] ?? '' }}</p>
    </div>

    <button type="button" class="btn btn-hapus baris-hapus" data-hapus-baris>
        <x-ikon nama="silang" />
        <span>Hapus Bahan</span>
    </button>
</div>
