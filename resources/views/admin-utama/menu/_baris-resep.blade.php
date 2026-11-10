{{--
    Satu baris bahan di form resep.
    Variabel: $i (indeks baris atau "__i__" untuk templat), $baris (['bahan_baku_id', 'jumlah']),
              $infoBahan ([id => ['nama', 'satuan']]).
--}}
@php
    $info = $infoBahan[$baris['bahan_baku_id'] ?? 0] ?? null;
@endphp

<div class="baris-resep" data-baris>
    <div class="baris-resep-isi">
        <x-form.pilih-bahan :name="'baris['.$i.'][bahan_baku_id]'" label="Bahan" wajib
                            :value="$baris['bahan_baku_id'] ?? null" :teks="$info['nama'] ?? null" />

        <x-form.angka :name="'baris['.$i.'][jumlah]'" label="Takaran per Porsi" wajib desimal maxlength="14"
                      :value="$baris['jumlah'] ?? ''" :satuan="$info['satuan'] ?? ''" placeholder="0" data-jumlah />
    </div>

    <button type="button" class="btn btn-hapus baris-hapus" data-hapus-baris>
        <x-ikon nama="silang" />
        <span>Hapus Bahan</span>
    </button>
</div>
