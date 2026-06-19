<?php
namespace SyafiqUnijaya\AiChatbox;

use SyafiqUnijaya\AiChatbox\Engine\AiProvider;
use SyafiqUnijaya\AiChatbox\Engine\AnthropicEngine;
use SyafiqUnijaya\AiChatbox\Engine\Contracts\AiEngineInterface;
use SyafiqUnijaya\AiChatbox\Engine\PromptBuilder;

/**
 * Resolves named AI providers from config and returns an AiProvider instance.
 *
 * Named providers are defined under ai-chatbox.providers.{name}.
 * Each provider must specify api_url, api_token, and api_model. All other
 * settings (temperature, system_prompt, language, etc.) are inherited from
 * the top-level ai-chatbox config.
 *
 * The special name 'default' (and empty string) resolves to the configured
 * active_provider, falling back to the first defined provider.
 *
 * Calling methods directly on the facade (e.g. AI::chat(...)) is delegated
 * to the 'default' provider via __call().
 *
 * @see \SyafiqUnijaya\AiChatbox\AI
 * @see \SyafiqUnijaya\AiChatbox\Engine\AiProvider
 */
class AiManager
{
    /**
     * Return a fluent AiProvider for the given named provider.
     *
     * @param  string  $name  Provider key from ai-chatbox.providers (or 'default')
     * @throws \InvalidArgumentException  if the provider is not configured
     */
    public function provider(string $name = 'default'): AiProvider
    {
        $config = $this->resolveConfig($name);

        return new AiProvider(
            $this->resolveEngine($config),
            new PromptBuilder(),
            $config,
        );
    }

    /**
     * Proxy undefined method calls to the default provider.
     *
     * Allows: AI::chat($prompt)  instead of  AI::provider()->chat($prompt)
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->provider('default')->{$method}(...$args);
    }

    /**
     * Return the correct engine for a resolved config array.
     * Used by the chatbox controller and provider() to avoid duplication.
     */
    public function resolveEngine(array $config): AiEngineInterface
    {
        return str_contains($config['api_url'] ?? '', 'api.anthropic.com')
        ? new AnthropicEngine()
        : app(AiEngineInterface::class);
    }

    /**
     * Resolve the embedding endpoint settings for a given (active) provider config.
     *
     * RAG embeddings may come from a provider different to the chat provider —
     * essential when the chat provider has no embeddings API (e.g. Anthropic).
     * When 'rag_embedding_provider' names another provider, that provider's
     * embedding URL, model, AND api_token are used; otherwise the active
     * provider's own embedding settings apply. The embedding timeout is a
     * universal global setting, never per-provider.
     *
     * @param  array<string, mixed>  $cfg  The active/effective provider config.
     * @return array{url: ?string, model: ?string, token: ?string, timeout: int}
     */
    public function embeddingConfig(array $cfg): array
    {
        $source = $cfg;
        $providerName = $cfg['rag_embedding_provider'] ?? null;

        if (is_string($providerName) && $providerName !== '') {
            try {
                $source = $this->resolveConfig($providerName);
            } catch (\InvalidArgumentException) {
                // Named embedding provider is misconfigured — fall back to the
                // active provider's own embedding settings rather than failing.
            }
        }

        return [
            'url' => $source['rag_embedding_url'] ?? null,
            'model' => $source['rag_embedding_model'] ?? null,
            'token' => $source['api_token'] ?? null,
            'timeout' => (int) ($cfg['rag_embedding_timeout'] ?? 10),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    public function resolveConfig(string $name): array
    {
        $base = config('ai-chatbox', []);
        $providers = $base['providers'] ?? [];

        // 'default' and empty resolve to whatever active_provider is configured
        if ($name === 'default' || $name === '') {
            $active = $base['active_provider'] ?? '';
            $name = ($active && $active !== 'default')
            ? $active
            : (string) array_key_first($providers);
        }

        if (!array_key_exists($name, $providers)) {
            throw new \InvalidArgumentException(
                "AI provider [{$name}] is not configured. "
                . "Add it under ai-chatbox.providers in your config."
            );
        }

        // Named providers inherit all base settings; only override what they define
        return array_merge($base, $providers[$name]);
    }
}
