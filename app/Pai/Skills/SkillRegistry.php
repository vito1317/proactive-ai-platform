<?php

namespace App\Pai\Skills;

use App\Pai\Skills\Builtin\GetSettingsSkill;
use App\Pai\Skills\Builtin\ListDomainsSkill;
use App\Pai\Skills\Builtin\RestartWorkersSkill;
use App\Pai\Skills\Builtin\StopTaskSkill;
use App\Pai\Skills\Builtin\TailLogsSkill;
use App\Pai\Skills\Builtin\ToggleDomainSkill;
use App\Pai\Skills\Builtin\UpdateSettingSkill;

/** 平台技能登錄處：內建自我管理技能。透過容器解析以注入相依。 */
class SkillRegistry
{
    private const BUILTIN = [
        GetSettingsSkill::class,
        UpdateSettingSkill::class,
        ListDomainsSkill::class,
        ToggleDomainSkill::class,
        RestartWorkersSkill::class,
        StopTaskSkill::class,
        TailLogsSkill::class,
    ];

    /** @var array<string,Skill>|null */
    private ?array $skills = null;

    /** @return array<string,Skill> name => skill */
    public function all(): array
    {
        if ($this->skills === null) {
            $this->skills = [];
            foreach (self::BUILTIN as $class) {
                $skill = app($class);
                $this->skills[$skill->name()] = $skill;
            }
        }

        return $this->skills;
    }

    public function get(string $name): ?Skill
    {
        return $this->all()[$name] ?? null;
    }

    /** 給 LLM 選用的技能目錄文字。 */
    public function catalog(): string
    {
        $lines = [];
        foreach ($this->all() as $skill) {
            $params = $skill->parameters();
            $p = $params === [] ? '無參數' : implode('；', array_map(fn ($k, $v) => "{$k}：{$v}", array_keys($params), $params));
            $risk = $skill->isHighRisk() ? '[高風險]' : '[低風險]';
            $lines[] = "- {$skill->name()} {$risk}：{$skill->description()}（參數：{$p}）";
        }

        return implode("\n", $lines);
    }
}
