<?php

namespace App\Pai\Memory;

use Illuminate\Database\Eloquent\Model;

/**
 * 跨對話的長期使用者記憶（一筆 = 一個關於使用者的事實/偏好）。
 *
 * @property int $id
 * @property ?int $user_id
 * @property string $category
 * @property string $content
 * @property bool $pinned
 * @property int $hits
 */
class UserMemory extends Model
{
    protected $fillable = ['user_id', 'category', 'content', 'pinned', 'hits'];

    protected $casts = ['pinned' => 'boolean', 'hits' => 'integer'];
}
