<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use DeveloperUnijaya\AiChatbox\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Covers the fail-closed tripwire: outside local/testing, the admin and RAG
 * routes must refuse the bare default [web, auth] gate with a 403 until the
 * integrator configures a real gate. In local/testing they stay open so a
 * fresh install works with zero configuration.
 */
class AdminAccessTripwireTest extends TestCase
{
    /**
     * Force a non-local/testing environment before the service provider boots
     * and registers routes, so guardAdminMiddleware() sees "production".
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['env'] = 'production';
        $app->detectEnvironment(fn () => 'production');

        // The provider's enforceConfigPublished() guard requires a published
        // config file outside the testing environment — provide one so boot
        // reaches route registration.
        $target = $app->configPath('ai-chatbox.php');
        if (!file_exists($target)) {
            @copy(__DIR__ . '/../../src/Config/ai-chatbox.php', $target);
        }
    }

    protected function tearDown(): void
    {
        $target = $this->app?->configPath('ai-chatbox.php');
        if ($target && file_exists($target)) {
            @unlink($target);
        }

        parent::tearDown();
    }

    /** Extra environment hook: replace the bare default with a real gate. */
    protected function withConfiguredGate($app): void
    {
        $app['config']->set('ai-chatbox.admin_middleware', ['web']);
        $app['config']->set('ai-chatbox.rag_admin_middleware', ['web']);
    }

    public function test_admin_route_is_sealed_with_bare_default_gate_in_production(): void
    {
        // Shipped default: rag_admin_middleware = [web, auth], admin inherits it.
        $this->get('/ai-chatbox/admin')
            ->assertStatus(403)
            ->assertSee('admin_middleware', false);
    }

    public function test_rag_route_is_sealed_with_bare_default_gate_in_production(): void
    {
        $this->get('/ai-chatbox/rag')
            ->assertStatus(403)
            ->assertSee('rag_admin_middleware', false);
    }

    #[DefineEnvironment('withConfiguredGate')]
    public function test_configured_gate_disables_the_tripwire(): void
    {
        // The RAG index lists documents from the database (--force: prod env).
        $this->artisan('migrate', ['--force' => true]);

        // A customised gate (here [web] to avoid needing an auth guard in the
        // test) is treated as intentional — the tripwire must not fire, so the
        // request reaches the controller (200) instead of a 403.
        $this->get('/ai-chatbox/admin')->assertOk();
        $this->get('/ai-chatbox/rag')->assertOk();
    }
}
