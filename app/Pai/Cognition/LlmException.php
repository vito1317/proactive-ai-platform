<?php

namespace App\Pai\Cognition;

use RuntimeException;

/** L3 大腦與 LLM 後端通訊或解析失敗時拋出。 */
final class LlmException extends RuntimeException {}
