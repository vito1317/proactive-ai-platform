<?php

namespace App\Pai\Cognition;

use RuntimeException;

/**
 * Prompt 模板集中管理：模板存於 resources/prompts/*.md，以 {{key}} 佔位符插值。
 * 好處：調 prompt 不必改 PHP；多個模板可統一處理模型 quirk（如 /no_think）。
 */
class Prompts
{
    /** @var array<string, string> 同請求內的模板快取 */
    private static array $cache = [];

    /**
     * 載入模板並插值。缺模板直接丟例外（fail fast，避免空 prompt 靜默送出）。
     *
     * @param  array<string, string|int|float>  $vars
     */
    public static function render(string $name, array $vars = []): string
    {
        $tpl = self::$cache[$name] ??= self::load($name);

        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{{'.$k.'}}'] = (string) $v;
        }
        $out = strtr($tpl, $replace);

        // 模型 quirk 適配：/no_think 是特定模型（Qwen 系）抑制思考鏈的指令。
        // 換用不認得它的模型時，後台關 llm.no_think 即可全域拿掉，不必逐一改模板。
        if (! (bool) app(\App\Pai\Settings\Settings::class)->get('llm.no_think', true)) {
            $out = (string) preg_replace('#/no_think[ \t]*#', '', $out);
        }

        return rtrim($out);
    }

    private static function load(string $name): string
    {
        $path = resource_path("prompts/{$name}.md");
        $tpl = is_file($path) ? file_get_contents($path) : false;
        if ($tpl === false) {
            throw new RuntimeException("Prompt 模板不存在：{$path}");
        }

        return $tpl;
    }
}
