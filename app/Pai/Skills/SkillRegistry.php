<?php

namespace App\Pai\Skills;

use App\Pai\Skills\Builtin\AddCommandSkill;
use App\Pai\Skills\Builtin\AddMcpServerSkill;
use App\Pai\Skills\Builtin\AnswerFromWebSkill;
use App\Pai\Skills\Builtin\DescribeDomainSkill;
use App\Pai\Skills\Builtin\EditFileSkill;
use App\Pai\Skills\Builtin\GenerateInstallCommandSkill;
use App\Pai\Skills\Builtin\GetSettingsSkill;
use App\Pai\Skills\Builtin\InsertInFileSkill;
use App\Pai\Skills\Builtin\ListCommandsSkill;
use App\Pai\Skills\Builtin\ListDomainsSkill;
use App\Pai\Skills\Builtin\ListMcpServersSkill;
use App\Pai\Skills\Builtin\MergeDomainsSkill;
use App\Pai\Skills\Builtin\OpenAppSkill;
use App\Pai\Skills\Builtin\ReadFileSkill;
use App\Pai\Skills\Builtin\RemoveCommandSkill;
use App\Pai\Skills\Builtin\RemoveMcpServerSkill;
use App\Pai\Skills\Builtin\RestartWorkersSkill;
use App\Pai\Skills\Builtin\RunShellSkill;
use App\Pai\Skills\Builtin\StopTaskSkill;
use App\Pai\Skills\Builtin\TailLogsSkill;
use App\Pai\Skills\Builtin\ToggleDomainSkill;
use App\Pai\Skills\Builtin\UpdateSettingSkill;
use App\Pai\Skills\Builtin\WebFetchSkill;
use App\Pai\Skills\Builtin\WebSearchSkill;
use App\Pai\Skills\Builtin\WriteFileSkill;

/** 平台技能登錄處：內建自我管理技能。透過容器解析以注入相依。 */
class SkillRegistry
{
    private const BUILTIN = [
        // 平台自我管理
        GetSettingsSkill::class,
        UpdateSettingSkill::class,
        ListDomainsSkill::class,
        DescribeDomainSkill::class,
        ToggleDomainSkill::class,
        MergeDomainsSkill::class,
        RestartWorkersSkill::class,
        StopTaskSkill::class,
        TailLogsSkill::class,
        // 通用系統操作
        RunShellSkill::class,
        OpenAppSkill::class,
        ReadFileSkill::class,
        WriteFileSkill::class,
        EditFileSkill::class,
        InsertInFileSkill::class,
        // 網路
        AnswerFromWebSkill::class,
        WebSearchSkill::class,
        WebFetchSkill::class,
        // MCP 工具伺服器管理
        AddMcpServerSkill::class,
        ListMcpServersSkill::class,
        RemoveMcpServerSkill::class,
        // 部署
        GenerateInstallCommandSkill::class,
        // 自訂斜線指令
        AddCommandSkill::class,
        ListCommandsSkill::class,
        RemoveCommandSkill::class,
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
            // 已接入的 MCP server 工具 → 包成對話技能，讓 agentic 也能呼叫（如 gateway 遠端執行）
            foreach ($this->mcpSkills() as $skill) {
                $this->skills[$skill->name()] = $skill;
            }
        }

        return $this->skills;
    }

    /** 把所有啟用中的 MCP server 工具包成 McpSkill。 */
    private function mcpSkills(): array
    {
        $out = [];
        try {
            $client = app(\App\Pai\Mcp\McpClient::class);
            foreach (\App\Pai\Mcp\McpServer::where('enabled', true)->get() as $server) {
                foreach (($server->tools ?? []) as $tool) {
                    if (isset($tool['name'])) {
                        $out[] = new McpSkill($client, $server, $tool);
                    }
                }
            }
        } catch (\Throwable) {
            // MCP 表不存在 / 連線問題 → 略過，不影響內建技能
        }

        return $out;
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
