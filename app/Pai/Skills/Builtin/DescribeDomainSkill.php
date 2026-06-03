<?php

namespace App\Pai\Skills\Builtin;

use App\Pai\Domains\DomainRegistry;
use App\Pai\Skills\Skill;

/** 說明某領域包的完整內容（觸發、工具、子智能體、劇本、風險策略）。低風險。 */
class DescribeDomainSkill implements Skill
{
    public function __construct(private readonly DomainRegistry $registry) {}

    public function name(): string
    {
        return 'describe-domain';
    }

    public function description(): string
    {
        return '說明某個領域包的細節：用途、觸發條件、可用工具、子智能體、劇本、自治階段與需人工核准的動作';
    }

    public function parameters(): array
    {
        return ['domain' => '領域代號（省略則列出全部摘要）'];
    }

    public function isHighRisk(): bool
    {
        return false;
    }

    public function run(array $args): string
    {
        $domain = trim((string) ($args['domain'] ?? ''));
        if ($domain === '') {
            $all = array_values($this->registry->all());
            if ($all === []) {
                return '目前沒有載入任何領域包。';
            }

            return "目前領域包：\n".implode("\n", array_map(fn ($p) => "・{$p->domain}：{$p->description}", $all));
        }

        $p = $this->registry->get($domain);
        if (! $p) {
            $names = implode('、', array_keys($this->registry->all()));

            return "找不到領域「{$domain}」。現有：{$names}";
        }

        $list = fn (array $a) => $a === [] ? '（無）' : implode('、', $a);

        return implode("\n", [
            "領域：{$p->domain}（協調者：{$p->coordinator}）",
            "用途：{$p->description}",
            '自治階段：'.$p->autonomy,
            '觸發事件：'.$list($p->eventTopics()),
            '排程：'.$list($p->cronTriggers()),
            '可用工具：'.$list($p->tools),
            '高風險工具：'.$list($p->highRiskTools()),
            '子智能體：'.$list($p->agentNames()),
            '劇本：'.$list($p->playbooks),
            '需人工核准：'.$list($p->hitlRequired),
        ]);
    }
}
