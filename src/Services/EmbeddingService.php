<?php
namespace SyafiqUnijaya\AiChatbox\Services;

use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    /**
     * @param  string|null  $url      Embedding endpoint — must come from the resolved provider config.
     * @param  string|null  $model    Embedding model name — must come from the resolved provider config.
     * @param  string|null  $token    API token — must come from the resolved provider config.
     * @param  int|null     $timeout  Request timeout in seconds — must come from the resolved provider config.
     */
    public function __construct(
        private readonly ?string $url = null,
        private readonly ?string $model = null,
        private readonly ?string $token = null,
        private readonly ?int $timeout = null,
    ) {}

    /**
     * Generate an embedding vector for the given text.
     *
     * Tries the OpenAI-compatible format first (used by OpenAI, LM Studio,
     * Ollama /v1/embeddings). Falls back to Ollama native response shapes
     * (/api/embed → embeddings[0], /api/embeddings → embedding).
     *
     * @return float[]|null  Null when the API call fails or returns no vector.
     */
    public function resolvedUrl(): string
    {
        return $this->url ?? '';
    }

    public function resolvedModel(): string
    {
        return $this->model ?? '';
    }

    public function embed(string $text): ?array
    {
        $url     = $this->url ?? '';
        $model   = $this->model ?? '';
        $token   = $this->token ?? '';
        $timeout = $this->timeout ?? 10;

        if (empty($url)) {
            Log::warning('AI Chatbox RAG: rag_embedding_url is not configured.');
            return null;
        }

        try {
            $client = app('ai-chatbox.guzzle')(['timeout' => $timeout]);

            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'input' => $text, // OpenAI + Ollama /v1/embeddings
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // OpenAI / LM Studio / Ollama v1 format: data[0].embedding
            if (isset($data['data'][0]['embedding']) && is_array($data['data'][0]['embedding'])) {
                return array_map('floatval', $data['data'][0]['embedding']);
            }

            // Ollama /api/embed format: embeddings[0]
            if (isset($data['embeddings'][0]) && is_array($data['embeddings'][0])) {
                return array_map('floatval', $data['embeddings'][0]);
            }

            // Ollama /api/embeddings format: embedding
            if (isset($data['embedding']) && is_array($data['embedding'])) {
                return array_map('floatval', $data['embedding']);
            }

            Log::warning('AI Chatbox RAG: Embedding API returned an unrecognised format.', [
                'url' => $url,
                'keys' => array_keys($data ?? []),
            ]);

            return null;

        } catch (\Throwable $e) {
            Log::error('AI Chatbox RAG: Embedding API call failed.', [
                'url' => $url,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
