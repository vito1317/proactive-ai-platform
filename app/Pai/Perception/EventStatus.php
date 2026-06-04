<?php

namespace App\Pai\Perception;

/**
 * 一筆感知事件 (L1) 在管線中的生命週期狀態。
 * received → normalized → routed（或 ignored，當無領域訂閱）。
 */
enum EventStatus: string
{
    case Received = 'received';     // 剛進匯流排
    case Normalized = 'normalized'; // 已標記 intent / severity
    case Routed = 'routed';         // 已交給領域協調者
    case Ignored = 'ignored';       // 無領域訂閱此主題
    case Failed = 'failed';         // 處理時發生錯誤
}
