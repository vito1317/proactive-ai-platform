<?php

namespace App\Pai\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * 開發/單機用的金庫：值以 APP_KEY 經 Crypt 加密存於 pai_secrets。
 * production 可替換為 HashiCorp Vault 驅動（實作同一介面即可）。
 */
class LocalEncryptedVault implements SecretVault
{
    public function has(string $name): bool
    {
        return SecretRecord::whereKey($name)->exists();
    }

    public function get(string $name): ?string
    {
        $row = SecretRecord::find($name);
        if ($row === null) {
            return null;
        }
        try {
            return Crypt::decryptString($row->ciphertext);
        } catch (DecryptException) {
            return null;
        }
    }

    public function put(string $name, string $value, ?string $description = null): void
    {
        SecretRecord::updateOrCreate(
            ['name' => $name],
            ['ciphertext' => Crypt::encryptString($value), 'description' => $description],
        );
    }

    public function forget(string $name): void
    {
        SecretRecord::whereKey($name)->delete();
    }

    public function names(): array
    {
        return SecretRecord::orderBy('name')->pluck('name')->all();
    }
}

/**
 * @internal Eloquent backing for {@see LocalEncryptedVault}.
 *
 * @property string $name
 * @property string $ciphertext
 * @property ?string $description
 */
class SecretRecord extends Model
{
    protected $table = 'pai_secrets';

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name', 'ciphertext', 'description'];

    protected $hidden = ['ciphertext']; // 永不序列化到 JSON / 回應
}
