<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use DeveloperUnijaya\AiChatbox\Tests\TestCase;

/**
 * The admin config viewer must never render secret values (API tokens, keys)
 * in cleartext — previously only `api_token` was masked, leaking
 * `rag_embedding_token` and any other secret key.
 */
class AdminConfigMaskingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(); // Bypass [web, auth] on the admin route
    }

    public function test_embedding_token_is_masked_in_the_config_viewer(): void
    {
        $secret = 'embed-secret-abcd1234';
        $this->app['config']->set('ai-chatbox.rag_embedding_token', $secret);

        $response = $this->get('/ai-chatbox/admin')->assertOk();

        // The raw secret must not appear; only the masked tail (last 4) may.
        $response->assertDontSee($secret, false);
        $response->assertDontSee('embed-secret-abcd', false);
        $response->assertSee('1234', false); // last-4 tail confirms the key is set
    }

    public function test_api_token_is_still_masked_in_the_config_viewer(): void
    {
        $this->app['config']->set('ai-chatbox.providers.testprovider.api_token', 'sk-topsecret-wxyz9876');

        $this->get('/ai-chatbox/admin')
            ->assertOk()
            ->assertDontSee('sk-topsecret-wxyz9876', false)
            ->assertDontSee('sk-topsecret', false);
    }

    public function test_named_provider_embedding_token_is_masked(): void
    {
        $this->app['config']->set('ai-chatbox.providers.testprovider.rag_embedding_token', 'prov-embed-secret-6789');

        $this->get('/ai-chatbox/admin')
            ->assertOk()
            ->assertDontSee('prov-embed-secret-6789', false)
            ->assertDontSee('prov-embed-secret', false);
    }

    public function test_non_secret_token_key_is_not_masked(): void
    {
        // `max_tokens` ends in "tokens" (plural) and must NOT be treated as a secret.
        $this->app['config']->set('ai-chatbox.max_tokens', 4096);

        $this->get('/ai-chatbox/admin')
            ->assertOk()
            ->assertSee('4096', false);
    }
}
