<?php

namespace App\Pai\Cognition;

/** 一次認知運行 (AgentRun) 的生命週期。 */
enum RunStatus: string
{
    case Running = 'running';            // ReAct 迴圈進行中
    case AwaitingHitl = 'awaiting_hitl'; // 產出高風險動作，等待人類核准 (L5)
    case Completed = 'completed';        // 完成（含低風險自動執行）
    case Failed = 'failed';              // 推理/工具錯誤
    case Cancelled = 'cancelled';        // 使用者中止（透過 stop-task 技能 / 中控台）
}
