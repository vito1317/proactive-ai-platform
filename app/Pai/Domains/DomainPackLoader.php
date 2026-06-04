<?php

namespace App\Pai\Domains;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * 掃描 packs 目錄、解析並驗證每個 *.yaml，產出 {@see DomainPack}。
 *
 * 對應 docs/SPEC.md §1「平台如何載入一個領域包」。
 */
final class DomainPackLoader
{
    public function __construct(
        private readonly DomainPackValidator $validator,
        private readonly string $packsPath,
    ) {}

    /**
     * 嚴格載入單一檔案。驗證不過或 domain 與檔名不符即拋出。
     */
    public function loadFile(string $path): DomainPack
    {
        $source = basename($path);

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new DomainPackValidationException($source, ['YAML 解析失敗：'.$e->getMessage()]);
        }

        $errors = $this->validator->validate($parsed);
        if ($errors !== []) {
            throw new DomainPackValidationException($source, $errors);
        }

        return DomainPack::fromArray($parsed);
    }

    /**
     * 嚴格載入整個目錄；任一檔案無效即拋出。
     *
     * @return array<string, DomainPack> 以 domain 鍵
     */
    public function loadAll(): array
    {
        $packs = [];
        foreach ($this->files() as $path) {
            $pack = $this->loadFile($path);
            $packs[$pack->domain] = $pack;
        }

        return $packs;
    }

    /**
     * 寬鬆載入：跳過無效檔案，回傳有效包與錯誤清單。
     * 平台啟動時用此法——壞掉的領域不應拖垮整個系統。
     *
     * @return array{packs: array<string, DomainPack>, errors: array<string, string[]>}
     */
    public function loadAllLenient(): array
    {
        $packs = [];
        $errors = [];
        foreach ($this->files() as $path) {
            try {
                $pack = $this->loadFile($path);
                $packs[$pack->domain] = $pack;
            } catch (DomainPackValidationException $e) {
                $errors[$e->source] = $e->errors;
            }
        }

        return ['packs' => $packs, 'errors' => $errors];
    }

    /**
     * @return string[] 排序後的 *.yaml 絕對路徑
     */
    public function files(): array
    {
        if (! is_dir($this->packsPath)) {
            return [];
        }
        $files = glob(rtrim($this->packsPath, '/').'/*.{yaml,yml}', GLOB_BRACE) ?: [];
        sort($files);

        return $files;
    }
}
