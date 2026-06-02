<?php

namespace App\Pai\Settings;

use Illuminate\Database\Eloquent\Model;

/**
 * 後台可調參數（覆寫 config/pai.php）。key 用點記法，value 以 JSON 存。
 *
 * @property string $key
 * @property mixed $value
 */
class PaiSetting extends Model
{
    protected $table = 'pai_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'json'];
}
