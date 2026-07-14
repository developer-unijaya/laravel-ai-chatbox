<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class MakeAiToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('make_tool_widgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if (is_dir($this->app->path('AiTools'))) {
            File::deleteDirectory($this->app->path('AiTools'));
        }

        parent::tearDown();
    }

    private function toolPath(string $class): string
    {
        return $this->app->path('AiTools/' . $class . '.php');
    }

    // ── Blank mode ────────────────────────────────────────────────────────────

    public function test_generates_a_blank_tool_skeleton(): void
    {
        $this->artisan('ai-chatbox:make-tool', ['name' => 'GadgetTool'])->assertSuccessful();

        $path = $this->toolPath('GadgetTool');
        $this->assertFileExists($path);

        $contents = File::get($path);
        $this->assertStringContainsString('class GadgetTool implements ToolInterface', $contents);
        $this->assertStringContainsString("return 'gadget';", $contents);           // derived name()
        $this->assertStringContainsString('Not implemented yet.', $contents);        // TODO handle()
    }

    public function test_requires_a_name_or_model(): void
    {
        $this->artisan('ai-chatbox:make-tool')->assertFailed();
    }

    // ── Model mode ────────────────────────────────────────────────────────────

    public function test_generates_a_model_backed_tool_with_typed_filters(): void
    {
        $this->artisan('ai-chatbox:make-tool', [
            'name' => 'WidgetsTool',
            '--model' => MakeToolWidget::class,
        ])->assertSuccessful();

        $contents = File::get($this->toolPath('WidgetsTool'));

        // Typed filter arguments from the table schema
        $this->assertStringContainsString("'name' => ['type' => 'string'", $contents);
        $this->assertStringContainsString("'quantity' => ['type' => 'integer'", $contents);

        // handle() queries the model
        $this->assertStringContainsString(MakeToolWidget::class . '::query()', $contents);

        // Timestamps and (as a filter) the primary key are excluded
        $this->assertStringNotContainsString("'created_at'", $contents);
        $this->assertStringNotContainsString("array_key_exists('id'", $contents);
    }

    public function test_filterable_option_limits_the_arguments(): void
    {
        $this->artisan('ai-chatbox:make-tool', [
            'name' => 'WidgetsTool',
            '--model' => MakeToolWidget::class,
            '--filterable' => 'name',
        ])->assertSuccessful();

        $contents = File::get($this->toolPath('WidgetsTool'));

        $this->assertStringContainsString("'name' => ['type' => 'string'", $contents);
        $this->assertStringNotContainsString("'quantity' =>", $contents);
    }

    public function test_tool_name_option_overrides_the_derived_name(): void
    {
        $this->artisan('ai-chatbox:make-tool', [
            'name' => 'WidgetsTool',
            '--model' => MakeToolWidget::class,
            '--tool-name' => 'find_widgets',
        ])->assertSuccessful();

        $this->assertStringContainsString("return 'find_widgets';", File::get($this->toolPath('WidgetsTool')));
    }

    public function test_unresolvable_model_falls_back_to_blank(): void
    {
        $this->artisan('ai-chatbox:make-tool', [
            'name' => 'FallbackTool',
            '--model' => 'App\\Models\\DoesNotExist',
        ])->assertSuccessful();

        $this->assertStringContainsString('Not implemented yet.', File::get($this->toolPath('FallbackTool')));
    }

    // ── Overwrite protection ──────────────────────────────────────────────────

    public function test_does_not_overwrite_without_force(): void
    {
        $this->artisan('ai-chatbox:make-tool', ['name' => 'DupeTool'])->assertSuccessful();
        $this->artisan('ai-chatbox:make-tool', ['name' => 'DupeTool'])->assertFailed();
        $this->artisan('ai-chatbox:make-tool', ['name' => 'DupeTool', '--force' => true])->assertSuccessful();
    }

    // ── Generated class is valid and implements the contract ──────────────────

    public function test_generated_class_implements_tool_interface(): void
    {
        $this->artisan('ai-chatbox:make-tool', ['name' => 'ValidTool'])->assertSuccessful();

        require_once $this->toolPath('ValidTool');

        $this->assertTrue(
            is_subclass_of('App\\AiTools\\ValidTool', \DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface::class)
        );
    }
}

class MakeToolWidget extends Model
{
    protected $table = 'make_tool_widgets';
    protected $guarded = [];
}
