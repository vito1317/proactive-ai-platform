<?php

namespace App\Providers;

use App\Pai\Cognition\CognitiveEngine;
use App\Pai\Cognition\IntentClassifier;
use App\Pai\Cognition\LlmClient;
use App\Pai\Domains\DomainPackLoader;
use App\Pai\Domains\DomainPackValidator;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Security\EgressGateway;
use App\Pai\Security\LocalEncryptedVault;
use App\Pai\Security\ProcessSandbox;
use App\Pai\Security\Sandbox;
use App\Pai\Security\SecretVault;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class PaiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DomainPackValidator::class);

        // 零信任：機密金庫 + 網路層注入出口
        $this->app->singleton(SecretVault::class, LocalEncryptedVault::class);
        $this->app->singleton(EgressGateway::class, fn ($app) => new EgressGateway($app->make(SecretVault::class)));
        $this->app->singleton(Sandbox::class, ProcessSandbox::class);
        $this->app->singleton(\App\Pai\Action\ActionExecutor::class, fn ($app) => new \App\Pai\Action\ActionExecutor(
            $app->make(Sandbox::class),
            $app->make(EgressGateway::class),
        ));

        $this->app->singleton(LlmClient::class, fn ($app) => new LlmClient($app->make(\App\Pai\Settings\Settings::class)));

        $this->app->singleton(\App\Pai\Cognition\DomainToolset::class, fn ($app) => new \App\Pai\Cognition\DomainToolset(
            $app->make(Sandbox::class),
            $app->make(EgressGateway::class),
        ));

        $this->app->singleton(CognitiveEngine::class, fn ($app) => new CognitiveEngine(
            $app->make(LlmClient::class),
            $app->make(\App\Pai\Settings\Settings::class),
            $app->make(DomainRegistry::class),
            $app->make(\App\Pai\Cognition\DomainToolset::class),
            $app->make(\App\Pai\Action\ActionExecutor::class),
            $app->make(\App\Pai\Memory\MemoryStore::class),
        ));

        $this->app->singleton(IntentClassifier::class, fn ($app) => new IntentClassifier(
            $app->make(LlmClient::class),
            $app->make(DomainRegistry::class),
        ));

        $this->app->singleton(DomainPackLoader::class, function ($app): DomainPackLoader {
            return new DomainPackLoader(
                $app->make(DomainPackValidator::class),
                (string) config('pai.packs_path'),
            );
        });

        // L2 記憶：嵌入 + 向量儲存（driver 由 config 切換）
        $this->app->singleton(\App\Pai\Memory\Embeddings::class, function () {
            $c = config('pai.memory.embeddings');

            return ($c['driver'] ?? 'local') === 'openai'
                ? new \App\Pai\Memory\OpenAiEmbeddings($c['base_url'], $c['model'], $c['api_key'], (int) $c['dim'])
                : new \App\Pai\Memory\LocalHashEmbeddings((int) $c['dim']);
        });
        $this->app->singleton(\App\Pai\Memory\VectorStore::class, fn () => config('pai.memory.store') === 'pgvector'
            ? new \App\Pai\Memory\PgVectorStore
            : new \App\Pai\Memory\DatabaseVectorStore);
        $this->app->singleton(\App\Pai\Memory\MemoryStore::class, fn ($app) => new \App\Pai\Memory\MemoryStore(
            $app->make(\App\Pai\Memory\VectorStore::class),
            $app->make(\App\Pai\Memory\Embeddings::class),
        ));

        // 啟動時載入一次領域包；無效的包跳過並記錄（預設不信任、不拖垮系統）。
        $this->app->singleton(DomainRegistry::class, function ($app): DomainRegistry {
            $result = $app->make(DomainPackLoader::class)->loadAllLenient();

            foreach ($result['errors'] as $source => $errors) {
                Log::warning("[PAI] 領域包載入失敗，已略過：{$source}", ['errors' => $errors]);
            }

            return new DomainRegistry($result['packs'], $result['errors']);
        });
    }
}
