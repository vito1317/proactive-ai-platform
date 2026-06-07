<?php

namespace App\Pai\Safety;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * #5 檢查點 / 回滾：改檔或改設定前先快照，之後可「還原剛才的修改」。
 *
 * @property int $id
 * @property string $kind   file|setting
 * @property string $target
 * @property ?string $before
 * @property bool $existed
 */
class Checkpoint extends Model
{
    protected $fillable = ['kind', 'target', 'before', 'existed', 'label', 'restored'];

    protected $casts = ['existed' => 'boolean', 'restored' => 'boolean'];

    /** 改檔前快照（讀現有內容）。 */
    public static function file(string $path, string $label = ''): void
    {
        try {
            $existed = is_file($path);
            static::create([
                'kind' => 'file', 'target' => $path,
                'before' => $existed ? @file_get_contents($path) : null,
                'existed' => $existed, 'label' => $label,
            ]);
            static::prune();
        } catch (Throwable) {
        }
    }

    /** 改設定前快照。 */
    public static function setting(string $key, $old, string $label = ''): void
    {
        try {
            static::create([
                'kind' => 'setting', 'target' => $key,
                'before' => $old === null ? null : (string) $old,
                'existed' => $old !== null, 'label' => $label,
            ]);
            static::prune();
        } catch (Throwable) {
        }
    }

    private static function prune(): void
    {
        try {
            $n = static::count();
            if ($n > 300) {
                static::orderBy('id')->limit($n - 300)->delete();
            }
        } catch (Throwable) {
        }
    }
}
