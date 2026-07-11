<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Agent\Tenant;
use App\Pai\Automation\Automation;
use App\Pai\Skills\Skill;

/**
 * 讓 AI 自己建立「自動化流程」：觸發→條件→動作。把使用者口語需求轉成 spec 存起來，
 * 排程引擎(AutomationEngine)之後就會自動跑。例：每天早上若不在公司就問要不要傳訊息給主管。
 */
class CreateAutomationSkill implements Skill
{
    public function name(): string
    {
        return 'create-automation';
    }

    public function description(): string
    {
        return '建立一條自動化流程（之後自動執行）。spec 是 JSON 字串：'
            .'{"trigger":{"type":"daily|interval|unlock","at":"HH:MM","every_min":N,"window":["07:00","09:30"],"days":[1,2,3,4,5]},'
            .'"conditions":[{"type":"location_outside|location_inside","place":"公司或地址","radius_m":400},{"type":"weekday","days":[1,2,3,4,5]},{"type":"time_after","time":"HH:MM"},{"type":"always"}],'
            .'"actions":[{"type":"speak","text":"…"},{"type":"notify","text":"…"},{"type":"open_map","place":"公司","app":"google"},{"type":"agent","instruction":"用LINE傳訊息給王經理：我會遲到{late}分"},{"type":"ask","question":"要傳訊息給主管嗎？","yes":[{"type":"agent","instruction":"…"}],"no":[]}]}。'
            .'文字可用變數 {name}{km}{drive}{eta}{late}{time}{place}。'
            .'若是一次性/短期需求，請帶 expires_at（截止時間 YYYY-MM-DD HH:MM）或 max_runs（跑幾次後自動停），到期會自動停用。';
    }

    public function parameters(): array
    {
        return [
            'name' => '流程名稱（給使用者看，如「早晨通勤遲到提醒」）',
            'spec' => '流程定義 JSON 字串（trigger/conditions/actions，見說明）',
            'expires_at' => '（選填）截止時間 YYYY-MM-DD HH:MM，到期自動停用；長期習慣可省略',
            'max_runs' => '（選填）執行幾次後自動停用；只跑一次填 1，長期可省略',
        ];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $uid = Tenant::id();
        if ($uid === null) {
            return '無法判斷帳號，請在登入情境下建立。';
        }
        $name = trim((string) ($args['name'] ?? ''));
        $specRaw = $args['spec'] ?? '';
        $spec = is_array($specRaw) ? $specRaw : json_decode((string) $specRaw, true);
        if (! is_array($spec) || empty($spec['trigger']) || empty($spec['actions'])) {
            return '流程定義不完整：至少要有 trigger 與 actions。請給合法的 spec JSON。';
        }
        if ($name === '') {
            $name = '自動化流程';
        }
        $expiresAt = Automation::parseExpiry($args['expires_at'] ?? null);
        $maxRuns = Automation::parseMaxRuns($args['max_runs'] ?? null);
        $auto = Automation::create([
            'user_id' => $uid, 'name' => $name, 'enabled' => true,
            'spec' => $spec, 'state' => [], 'source' => 'ai',
            'expires_at' => $expiresAt, 'max_runs' => $maxRuns,
        ]);
        $stop = $auto->autoStopLabel();

        return "✅ 已建立自動化流程「{$name}」(#{$auto->id})，之後會自動執行。"
            .($stop ? "（自動停止：{$stop}）" : '')
            ."可說「列出自動化」查看或「關閉自動化 {$auto->id}」停用。";
    }
}
