<?php

namespace App\Pai\Skills;

use Illuminate\Database\Eloquent\Model;

/**
 * 學會的技能（playbook）：從成功的多步任務萃取的可重用做法。
 *
 * @property int $id
 * @property string $name
 * @property string $when_to_use
 * @property string $steps
 * @property string $keywords
 * @property int $uses
 */
class LearnedSkill extends Model
{
    protected $fillable = ['name', 'when_to_use', 'steps', 'keywords', 'uses'];

    /** 找出與本次訊息相關的學會技能（關鍵字命中），最多 $limit 筆。 */
    public static function relevant(string $message, int $limit = 3): \Illuminate\Support\Collection
    {
        $msg = mb_strtolower($message);
        $all = static::orderByDesc('uses')->orderByDesc('updated_at')->limit(80)->get();

        return $all->filter(function ($s) use ($msg) {
            foreach (preg_split('/\s+/', (string) $s->keywords) ?: [] as $kw) {
                $kw = mb_strtolower(trim($kw));
                if ($kw !== '' && mb_strlen($kw) >= 2 && str_contains($msg, $kw)) {
                    return true;
                }
            }

            return false;
        })->take($limit)->values();
    }
}
