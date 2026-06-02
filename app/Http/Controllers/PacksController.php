<?php

namespace App\Http\Controllers;

use App\Pai\Domains\DomainPackGenerator;
use App\Pai\Domains\DomainPackValidator;
use App\Pai\Domains\DomainRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * 用自然語言新增領域包：描述 → LLM 生成 manifest → 驗證 → 預覽 → 寫入 packs/ 啟用。
 * 無需手寫 YAML、無需寫程式（生成的領域用基礎工具即可運作）。
 */
class PacksController extends Controller
{
    public function index(DomainRegistry $registry): Response
    {
        return Inertia::render('Packs', [
            'domains' => array_map(static fn ($p) => $p->toArray(), array_values($registry->all())),
            'preview' => session('pack_preview'),
        ]);
    }

    public function generate(Request $request, DomainPackGenerator $generator): RedirectResponse
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:1000']]);

        $result = $generator->generate($data['description']);

        return redirect()->route('packs')->with('pack_preview', $result);
    }

    public function save(Request $request, DomainPackValidator $validator): RedirectResponse
    {
        $data = $request->validate(['yaml' => ['required', 'string']]);

        try {
            $manifest = Yaml::parse($data['yaml']);
        } catch (\Throwable $e) {
            return back()->with('flash', ['error' => 'YAML 解析失敗：'.$e->getMessage()]);
        }

        $errors = $validator->validate($manifest);
        if ($errors !== []) {
            return back()->with('flash', ['error' => '驗證失敗：'.implode('；', $errors)]);
        }

        $domain = $manifest['domain'];
        $path = base_path('packs/'.$domain.'.yaml');
        file_put_contents($path, $data['yaml']);

        return redirect()->route('packs')->with('flash', [
            'success' => "領域包 [{$domain}] 已啟用（下次請求即載入；queue worker 需重啟才會處理該領域）。",
        ]);
    }
}
