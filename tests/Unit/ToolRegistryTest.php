<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Unit;

use Illuminate\Http\Request;
use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;
use DeveloperUnijaya\AiChatbox\Orchestration\ToolRegistry;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    public function test_register_and_get(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new RegistryEchoTool());

        $this->assertTrue($registry->has('echo_tool'));
        $this->assertInstanceOf(ToolInterface::class, $registry->get('echo_tool'));
        $this->assertNull($registry->get('nope'));
    }

    public function test_authorized_filters_out_denied_tools(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new RegistryEchoTool());               // allowed
        $registry->register(new RegistryEchoTool('secret', false)); // denied

        $authorized = $registry->authorized(null);

        $this->assertArrayHasKey('echo_tool', $authorized);
        $this->assertArrayNotHasKey('secret', $authorized);
    }

    public function test_schemas_exposes_normalized_shape(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new RegistryEchoTool());

        $schemas = $registry->schemas(null);

        $this->assertSame('echo_tool', $schemas[0]['name']);
        $this->assertArrayHasKey('description', $schemas[0]);
        $this->assertArrayHasKey('parameters', $schemas[0]);
    }

    public function test_load_from_config_skips_invalid_entries(): void
    {
        $registry = new ToolRegistry();
        $registry->loadFromConfig([
            RegistryEchoTool::class,   // valid
            \stdClass::class,          // not a ToolInterface — skipped
            'NoSuchClass\\Nope',       // unresolvable — skipped
            '',                        // empty — skipped
        ]);

        $this->assertTrue($registry->has('echo_tool'));
        $this->assertCount(1, $registry->all());
    }

    public function test_load_from_config_is_idempotent(): void
    {
        $registry = new ToolRegistry();
        $registry->loadFromConfig([RegistryEchoTool::class]);
        $registry->loadFromConfig([RegistryEchoTool::class]); // second call no-ops

        $this->assertCount(1, $registry->all());
    }
}

class RegistryEchoTool implements ToolInterface
{
    public function __construct(private string $toolName = 'echo_tool', private bool $authorized = true) {}

    public function name(): string
    {
        return $this->toolName;
    }
    public function description(): string
    {
        return 'Echo.';
    }
    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
    public function authorize(?Request $request = null): bool
    {
        return $this->authorized;
    }
    public function handle(array $arguments): mixed
    {
        return 'ok';
    }
}
