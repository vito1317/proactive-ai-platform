<?php

namespace App\Pai\Perception;

/**
 * L1 主動感知：定時掃描受監控日誌檔，偵測「新增」的錯誤行，為每段錯誤
 * 發出 log.error 事件（走既有 Ingest 管線 → 路由到 log-ops 協調者 → 自動修復）。
 * 以位元組游標確保只處理新內容、不重複。
 */
class LogScanner
{
    /** 掃描所有來源，回傳偵測到的錯誤數。 */
    public function scan(): int
    {
        $count = 0;
        foreach ((array) config('pai.logops.sources') as $path) {
            $count += $this->scanFile($path);
        }

        return $count;
    }

    public function scanFile(string $path): int
    {
        if (! is_file($path) || ! is_readable($path)) {
            return 0;
        }

        $size = filesize($path);
        $cursor = LogCursor::firstOrNew(['path' => $path]);
        $offset = $cursor->offset ?? 0;
        if ($size < $offset) {
            $offset = 0; // 檔案被截斷/輪替 → 重頭
        }
        if ($size <= $offset) {
            return 0; // 無新增
        }

        $fh = fopen($path, 'rb');
        fseek($fh, $offset);
        $new = (string) fread($fh, $size - $offset);
        fclose($fh);

        $cursor->offset = $size;
        $cursor->save();

        return $this->processChunk($path, $new);
    }

    private function processChunk(string $path, string $content): int
    {
        $patterns = (array) config('pai.logops.patterns');
        $regex = '/('.implode('|', array_map('preg_quote', $patterns)).')/i';
        $span = (int) config('pai.logops.excerpt_lines', 6);

        $lines = preg_split('/\r?\n/', $content) ?: [];
        $n = count($lines);
        $count = 0;
        $skipUntil = -1;

        for ($i = 0; $i < $n; $i++) {
            if ($i <= $skipUntil) {
                continue; // 已被前一段擷取涵蓋（避免堆疊重複觸發）
            }
            if (! preg_match($regex, $lines[$i])) {
                continue;
            }

            $excerpt = implode("\n", array_slice($lines, $i, $span));
            $skipUntil = $i + $span - 1;

            $this->fire($path, $lines[$i], $excerpt);
            $count++;
        }

        return $count;
    }

    private function fire(string $path, string $line, string $excerpt): void
    {
        $sev = preg_match('/CRITICAL|EMERGENCY|ALERT|Fatal/i', $line) ? 'critical' : 'high';

        $event = PaiEvent::create([
            'source' => 'log',
            'topic' => 'log.error',
            'payload' => [
                'file' => basename($path),
                'path' => $path,
                'severity' => $sev,
                'line' => trim($line),
                'excerpt' => $excerpt,
            ],
            'status' => EventStatus::Received,
        ]);

        IngestEventJob::dispatch($event->id);
    }
}
