<?php

namespace App\Pai\Chat;

use RuntimeException;

/** 內部訊號：使用者按「終止」或插話 → 中斷 LLM 串流迴圈。 */
class StopStreaming extends RuntimeException {}
