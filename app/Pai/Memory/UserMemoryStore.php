<?php

namespace App\Pai\Memory;

/**
 * 跨對話長期使用者記憶：recall（注入 prompt）/ remember（去重寫入）/ forget。
 * 與既有 MemoryStore（向量 RAG）不同——這裡存「關於使用者本人的事實/偏好」，每輪對話都注入。
 */
class UserMemoryStore
{
    private const MAX_KEEP = 200;     // 每位使用者保留上限

    /** 注入用：把長期記憶整理成可讀文字；沒有則回空字串。 */
    public function recall(?int $userId, ?int $max = null): string
    {
        // 注入筆數上限可調（pai.memory.user_inject_max）：太多會吃爆 context、稀釋注意力
        $max ??= (int) config('pai.memory.user_inject_max', 30);
        $rows = UserMemory::where('user_id', $userId)
            ->orderByDesc('pinned')->orderByDesc('hits')->orderByDesc('updated_at')
            ->limit(max(1, $max))->get();
        if ($rows->isEmpty()) {
            return '';
        }
        $label = ['identity' => '身分', 'location' => '地點', 'preference' => '偏好',
            'dislike' => '不喜歡', 'contact' => '聯絡人', 'routine' => '習慣', 'fact' => '事實'];

        // 記憶內容會逐字進 system prompt（且可由使用者對話間接寫入）→ 注入前先中和注入語句
        $sanitizer = app(\App\Pai\Security\ToolDescriptionSanitizer::class);

        return $rows->map(fn ($m) => '・['.($label[$m->category] ?? $m->category).'] '
            .$sanitizer->sanitize((string) $m->content)->clean)->implode("\n");
    }

    /** 寫入一筆（去重：內容高度相似就略過/更新）。回 true=有實際新增。 */
    public function remember(?int $userId, string $content, string $category = 'fact'): bool
    {
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));
        if (mb_strlen($content) < 2 || mb_strlen($content) > 300) {
            return false;
        }
        $existing = UserMemory::where('user_id', $userId)->get();
        $norm = fn (string $s) => mb_strtolower((string) preg_replace('/[\s，。、,.!?！？]/u', '', $s));
        $nc = $norm($content);
        foreach ($existing as $m) {
            $nm = $norm($m->content);
            if ($nm === $nc || ($nc !== '' && (str_contains($nm, $nc) || str_contains($nc, $nm)))) {
                if (mb_strlen($content) > mb_strlen($m->content)) {
                    $m->update(['content' => $content, 'category' => $category]);
                }
                $m->increment('hits');

                return false;
            }
        }
        UserMemory::create(['user_id' => $userId, 'category' => $category, 'content' => $content]);
        $this->prune($userId);

        return true;
    }

    /** 忘記：刪除內容含關鍵字的記憶。回刪除筆數。 */
    public function forget(?int $userId, string $needle): int
    {
        $needle = trim($needle);
        if ($needle === '') {
            return 0;
        }

        return UserMemory::where('user_id', $userId)->where('content', 'like', '%'.$needle.'%')->delete();
    }

    /** @return \Illuminate\Support\Collection<int, UserMemory> */
    public function all(?int $userId)
    {
        return UserMemory::where('user_id', $userId)->orderByDesc('pinned')->orderByDesc('updated_at')->get();
    }

    private function prune(?int $userId): void
    {
        $count = UserMemory::where('user_id', $userId)->count();
        if ($count <= self::MAX_KEEP) {
            return;
        }
        UserMemory::where('user_id', $userId)->where('pinned', false)
            ->orderBy('hits')->orderBy('updated_at')
            ->limit($count - self::MAX_KEEP)->delete();
    }
}
