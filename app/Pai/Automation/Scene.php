<?php

namespace App\Pai\Automation;

use Illuminate\Database\Eloquent\Model;

/**
 * 情境模式：一句「○○模式」執行一串動作（動作格式同 AutomationEngine）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property array $actions
 * @property int $run_count
 * @property \Illuminate\Support\Carbon|null $last_run_at
 */
class Scene extends Model
{
    protected $fillable = ['user_id', 'name', 'actions', 'run_count', 'last_run_at'];

    protected $casts = [
        'actions' => 'array',
        'last_run_at' => 'datetime',
    ];

    /** 依口語找情境（「睡覺模式」「進入睡覺模式」都要中）。 */
    public static function match(int $uid, string $utterance): ?self
    {
        foreach (self::where('user_id', $uid)->get() as $s) {
            if ($s->name !== '' && str_contains($utterance, $s->name)) {
                return $s;
            }
        }

        return null;
    }

    /** 執行：交給 AutomationEngine 跑動作（同自動化語意）。回傳給使用者聽的一句話。 */
    public function run(?string $preferNode = null): string
    {
        $engine = app(AutomationEngine::class);
        $node = $preferNode ?: \App\Pai\Mcp\ReverseBus::ownerPhoneNode((int) $this->user_id);
        $ctx = [
            'name' => trim((string) (\App\Models\User::find($this->user_id)?->name ?? '')),
            'time' => now('Asia/Taipei')->format('H:i'),
        ];
        $engine->runActions((int) $this->user_id, (array) $this->actions, $ctx, $node);
        $this->run_count = (int) $this->run_count + 1;
        $this->last_run_at = now();
        $this->save();

        return "已進入「{$this->name}」模式（".count((array) $this->actions).' 個動作執行中）。';
    }
}
