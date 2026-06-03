<?php

namespace App\Pai\Skills;

/**
 * 一個「技能」= 對話 AI 可呼叫的一項平台能力（含修改平台自身設定的能力）。
 * 高風險技能（會改設定 / 改領域 / 重啟服務）受 zero-trust 閘門控管：
 * 需後台開啟「允許系統自我修改」(skills.allow_system_writes) 才能執行。
 */
interface Skill
{
    /** 唯一代號（kebab-case），LLM 以此選用。 */
    public function name(): string;

    /** 一句話說明用途，給 LLM 與後台顯示。 */
    public function description(): string;

    /** 參數綱要：[名稱 => 說明]，供 LLM 填值。 */
    public function parameters(): array;

    /** 是否為高風險（會修改系統狀態）。 */
    public function isHighRisk(): bool;

    /**
     * 執行技能。
     *
     * @param  array<string,mixed>  $args
     * @return string 給使用者看的自然語言結果
     */
    public function run(array $args): string;
}
