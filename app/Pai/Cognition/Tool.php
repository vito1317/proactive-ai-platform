<?php

namespace App\Pai\Cognition;

/**
 * 一個 ReAct 工具（L4 行動層的抽象）。內建工具實作此介面；
 * 未來 P2/P3/P4 的 MCP 工具也將以此包裝接入。
 */
interface Tool
{
    /** 工具名稱（模型在 action 欄位呼叫的鍵）。 */
    public function name(): string;

    /** 給 LLM 的說明（含何時使用、需要哪些 action_input）。 */
    public function description(): string;

    /**
     * @param  array<string, mixed>  $input  模型提供的 action_input
     */
    public function run(array $input, AgentContext $ctx): ToolResult;
}
