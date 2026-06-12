<?php

namespace App\Providers;

use App\Pai\Action\ActionExecutor;
use App\Pai\Cognition\CognitiveEngine;
use App\Pai\Cognition\DomainToolset;
use App\Pai\Cognition\IntentClassifier;
use App\Pai\Cognition\LlmClient;
use App\Pai\Domains\DomainPackLoader;
use App\Pai\Domains\DomainPackValidator;
use App\Pai\Domains\DomainRegistry;
use App\Pai\Memory\DatabaseVectorStore;
use App\Pai\Memory\Embeddings;
use App\Pai\Memory\LocalHashEmbeddings;
use App\Pai\Memory\MemoryStore;
use App\Pai\Memory\OpenAiEmbeddings;
use App\Pai\Memory\PgVectorStore;
use App\Pai\Memory\VectorStore;
use App\Pai\Security\EgressGateway;
use App\Pai\Security\LocalEncryptedVault;
use App\Pai\Security\ProcessSandbox;
use App\Pai\Security\Sandbox;
use App\Pai\Security\SecretVault;
use App\Pai\Settings\Settings;
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
        $this->app->singleton(ActionExecutor::class, fn ($app) => new ActionExecutor(
            $app->make(Sandbox::class),
            $app->make(EgressGateway::class),
        ));

        $this->app->singleton(LlmClient::class, fn ($app) => new LlmClient($app->make(Settings::class)));

        $this->app->singleton(DomainToolset::class, fn ($app) => new DomainToolset(
            $app->make(Sandbox::class),
            $app->make(EgressGateway::class),
        ));

        $this->app->singleton(CognitiveEngine::class, fn ($app) => new CognitiveEngine(
            $app->make(LlmClient::class),
            $app->make(Settings::class),
            $app->make(DomainRegistry::class),
            $app->make(DomainToolset::class),
            $app->make(ActionExecutor::class),
            $app->make(MemoryStore::class),
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

        // L2 記憶：嵌入 + 向量儲存（driver 由 config 切換）；外層加快取（同文字不重算）
        $this->app->singleton(Embeddings::class, function () {
            $c = config('pai.memory.embeddings');
            $inner = ($c['driver'] ?? 'local') === 'openai'
                ? new OpenAiEmbeddings($c['base_url'], $c['model'], $c['api_key'], (int) $c['dim'])
                : new LocalHashEmbeddings((int) $c['dim']);

            return new \App\Pai\Memory\CachedEmbeddings($inner);
        });
        $this->app->singleton(VectorStore::class, fn () => config('pai.memory.store') === 'pgvector'
            ? new PgVectorStore
            : new DatabaseVectorStore);
        $this->app->singleton(MemoryStore::class, fn ($app) => new MemoryStore(
            $app->make(VectorStore::class),
            $app->make(Embeddings::class),
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
