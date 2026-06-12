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
use App\Pai\Skills\Builtin\DelegateSkill;
use App\Pai\Skills\Builtin\ExecuteCodeSkill;
use App\Pai\Skills\Builtin\LoopSkill;
use App\Pai\Skills\Builtin\WaitSkill;
use App\Pai\Skills\Builtin\RollbackSkill;
use App\Pai\Skills\Builtin\RunShellSkill;
use App\Pai\Skills\Builtin\SendEmailSkill;
use App\Pai\Skills\Builtin\CancelScheduledSkill;
use App\Pai\Skills\Builtin\FalGenerateSkill;
use App\Pai\Skills\Builtin\FirecrawlScrapeSkill;
use App\Pai\Skills\Builtin\GenerateImageSkill;
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
        CancelScheduledSkill::class,
        \App\Pai\Skills\Builtin\CreateAutomationSkill::class,
        \App\Pai\Skills\Builtin\ListAutomationsSkill::class,
        \App\Pai\Skills\Builtin\ToggleAutomationSkill::class,
        GenerateImageSkill::class,
        FirecrawlScrapeSkill::class,
        FalGenerateSkill::class,
        TailLogsSkill::class,
        // 通用系統操作
        RunShellSkill::class,
        ExecuteCodeSkill::class,
        WaitSkill::class,
        LoopSkill::class,
        DelegateSkill::class,
        RollbackSkill::class,
        OpenAppSkill::class,
        ReadFileSkill::class,
        WriteFileSkill::class,
        EditFileSkill::class,
        InsertInFileSkill::class,
        // 網路
        AnswerFromWebSkill::class,
        WebSearchSkill::class,
        WebFetchSkill::class,
        SendEmailSkill::class,
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
        return $this->catalogFor(null);
    }

    /**
     * 精簡工具目錄：MCP 工具依「基本工具名」去重（多節點重複的 browser、exec 等只留一份），
     * 優先保留 $preferNode（使用者預設節點）的版本。大幅縮短 prompt，避免本地模型被上百個工具淹沒。
     */
    public function catalogFor(?string $preferNode, ?array $allowedNodes = null, ?array $allowedSkills = null, ?array $modeTools = null): string
    {
        return self::format($this->dedupedSkills($preferNode, $allowedNodes, $allowedSkills, $modeTools));
    }

    /**
     * 去重後的技能清單（MCP 工具同名只留一個，優先 $preferNode）。
     * $allowedNodes：null=不限制；陣列=只保留節點名在清單內的 MCP 工具（租戶裝置隔離）。
     * $allowedSkills：null=不限制；陣列=只保留清單內的 builtin skill（租戶 skill 授權）。
     * $modeTools：null=不限制；陣列=Agent Profile 模式工具白名單（同時過濾 builtin 名 + mcp base）。
     * @return array<int,Skill>
     */
    public function dedupedSkills(?string $preferNode, ?array $allowedNodes = null, ?array $allowedSkills = null, ?array $modeTools = null): array
    {
        $builtin = [];
        $mcpByBase = [];
        foreach ($this->all() as $name => $skill) {
            if (! str_starts_with($name, 'mcp__')) {
                // 租戶 skill 逐項授權：非 admin 且未授權此 skill → 不放進目錄
                if ($allowedSkills !== null && ! in_array($name, $allowedSkills, true)) {
                    continue;
                }
                // Agent Profile 模式白名單（builtin 依名稱）
                if ($modeTools !== null && ! in_array($name, $modeTools, true)) {
                    continue;
                }
                $builtin[] = $skill;

                continue;
            }
            $parts = explode('__', $name);
            $node = $parts[1] ?? '';
            $base = $parts[2] ?? $name;
            // 租戶裝置隔離：節點不在此帳號可存取清單 → 不放進目錄
            if ($allowedNodes !== null && ! in_array($node, $allowedNodes, true)) {
                continue;
            }
            // Agent Profile 模式白名單（mcp 依 base 工具名）
            if ($modeTools !== null && ! in_array($base, $modeTools, true)) {
                continue;
            }
            if (isset($mcpByBase[$base])) {
                if ($preferNode !== null && $node === $preferNode && $mcpByBase[$base]['node'] !== $preferNode) {
                    $mcpByBase[$base] = ['skill' => $skill, 'node' => $node];
                }

                continue;
            }
            $mcpByBase[$base] = ['skill' => $skill, 'node' => $node];
        }

        return array_merge($builtin, array_map(fn ($e) => $e['skill'], array_values($mcpByBase)));
    }

    /** @param array<int,Skill> $skills */
    public static function format(array $skills): string
    {
        $lines = [];
        foreach ($skills as $skill) {
            $params = $skill->parameters();
            $p = $params === [] ? '無參數' : implode('；', array_map(fn ($k, $v) => "{$k}：{$v}", array_keys($params), $params));
            $risk = $skill->isHighRisk() ? '[高風險]' : '[低風險]';
            $lines[] = "- {$skill->name()} {$risk}：{$skill->description()}（參數：{$p}）";
        }

        return implode("\n", $lines);
    }
}
