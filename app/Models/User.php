<?php

namespace App\Models;

use App\Models\Concerns\DataMaster;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use DataMaster, HasFactory, Notifiable;

    public const ROLE_ADMIN_UTAMA = 'admin_utama';
    public const ROLE_ADMIN_PUSAT = 'admin_pusat';
    public const ROLE_ADMIN_CABANG = 'admin_cabang';

    /** Nama role yang ditampilkan di layar. */
    public const LABEL_ROLE = [
        self::ROLE_ADMIN_UTAMA  => 'Admin Utama',
        self::ROLE_ADMIN_PUSAT  => 'Admin Pusat',
        self::ROLE_ADMIN_CABANG => 'Admin Cabang',
    ];

    /** Kolom transaksi yang mencatat pengguna; pengguna yang tercatat di sini tidak boleh dihapus. */
    private const RUJUKAN = [
        'stok_mutasi'          => 'dibuat_oleh',
        'permintaan'           => 'diajukan_oleh',
        'kiriman'              => 'dikirim_oleh',
        'kiriman_detail'       => 'dichecklist_oleh',
        'barang_masuk'         => 'dicatat_oleh',
        'barang_keluar'        => 'dicatat_oleh',
        'penjualan_harian'     => 'dicatat_oleh',
        'opname'               => 'petugas_id',
        'persetujuan'          => 'oleh_user_id',
        'log_perubahan'        => 'user_id',
        'kontrol_sistem'       => 'diubah_oleh',
    ];

    protected $table = 'users';

    protected $fillable = [
        'nama',
        'email',
        'password',
        'role',
        'cabang_id',
        'aktif',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'aktif'    => 'boolean',
        ];
    }

    public function emailLaporan(): HasOne
    {
        return $this->hasOne(EmailLaporan::class);
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function isAdminUtama(): bool
    {
        return $this->role === self::ROLE_ADMIN_UTAMA;
    }

    public function isAdminPusat(): bool
    {
        return $this->role === self::ROLE_ADMIN_PUSAT;
    }

    public function isAdminCabang(): bool
    {
        return $this->role === self::ROLE_ADMIN_CABANG;
    }

    /** Akun Admin Utama hanya boleh diubah, dinonaktifkan, dihapus, atau diganti password-nya oleh Admin Utama. */
    public function bisaDikelolaOleh(?User $oleh): bool
    {
        return $oleh !== null && ($oleh->isAdminUtama() || ! $this->isAdminUtama());
    }

    /** $user->label_role -> "Admin Cabang" */
    protected function labelRole(): Attribute
    {
        return Attribute::get(fn () => self::LABEL_ROLE[$this->role] ?? $this->role);
    }

    /** $user->inisial -> "FN" untuk "Fitri Nuraini" */
    protected function inisial(): Attribute
    {
        return Attribute::get(fn () => collect(preg_split('/\s+/', trim((string) $this->nama)))
            ->filter()
            ->take(2)
            ->map(fn ($kata) => mb_strtoupper(mb_substr($kata, 0, 1)))
            ->implode(''));
    }

    /**
     * Nama route dashboard sesuai role.
     * Sengaja tanpa 'default': role baru yang lupa didaftarkan di sini langsung ketahuan.
     */
    public function ruteDashboard(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN_UTAMA  => 'admin-utama.dashboard',
            self::ROLE_ADMIN_PUSAT  => 'pusat.dashboard',
            self::ROLE_ADMIN_CABANG => 'cabang.dashboard',
        };
    }

    public function kolomUnik(): array
    {
        return ['email'];
    }

    public function kolomCari(): array
    {
        return ['nama', 'email'];
    }

    public function sudahDipakai(): bool
    {
        foreach (self::RUJUKAN as $tabel => $kolom) {
            if (DB::table($tabel)->where($kolom, $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Admin Utama aktif selain pengguna ini (aturan: minimal harus ada satu Admin Utama aktif). */
    public static function adaAdminUtamaAktifLain(int $kecualiId): bool
    {
        return static::query()
            ->where('role', self::ROLE_ADMIN_UTAMA)
            ->where('aktif', true)
            ->whereKeyNot($kecualiId)
            ->exists();
    }

    /**
     * Ganti password dan keluarkan semua sesi pengguna ini,
     * kecuali sesi yang disebut (dipakai saat pengguna mengganti password sendiri).
     */
    public function aturPassword(string $passwordBaru, ?string $kecualiSesi = null): void
    {
        DB::transaction(function () use ($passwordBaru, $kecualiSesi) {
            $this->forceFill([
                'password'       => $passwordBaru,
                'remember_token' => null,
            ])->save();

            DB::table('sessions')
                ->where('user_id', $this->id)
                ->when($kecualiSesi, fn ($q) => $q->where('id', '!=', $kecualiSesi))
                ->delete();
        });
    }
}
