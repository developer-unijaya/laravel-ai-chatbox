<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

/**
 * Tests for the ai-chatbox:graphify Artisan command.
 *
 * Covers:
 *  - Failure when the graphify-out directory is missing
 *  - Failure when the directory contains no markdown
 *  - Recursive import of markdown into RagDocument + chunks
 *  - original_filename is prefixed with graphify-out/ (the rebuild marker)
 *  - --dry-run previews without writing
 *  - Rebuild replaces previously imported graphify docs (no duplication)
 *  - --keep appends instead of replacing
 *  - Documents from other sources (uploads) are never touched
 */
class GraphifyImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] Temp directories created during a test, removed in tearDown. */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Keyword-only mode (no embedding endpoint) — mirrors the Anthropic setup and
        // keeps ingestion offline so no real HTTP embedding calls are made.
        $this->app['config']->set('ai-chatbox.rag_embedding_url', '');
        $this->app['config']->set('ai-chatbox.rag_enabled', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->deleteDir($dir);
        }
        parent::tearDown();
    }

    // ── Pre-flight ─────────────────────────────────────────────────────────────

    public function test_fails_when_directory_does_not_exist(): void
    {
        $this->artisan('ai-chatbox:graphify', ['--path' => $this->tmpPath('does-not-exist')])
            ->expectsOutputToContain('graphify-out directory not found')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_fails_when_no_markdown_files_present(): void
    {
        $dir = $this->makeGraphDir(['graph.json' => '{}', 'graph.html' => '<html></html>']);

        $this->artisan('ai-chatbox:graphify', ['--path' => $dir])
            ->expectsOutputToContain('No markdown files found')
            ->assertExitCode(Command::FAILURE);
    }

    // ── Import ──────────────────────────────────────────────────────────────────

    public function test_imports_markdown_recursively_into_knowledge_base(): void
    {
        $dir = $this->makeGraphDir([
            'GRAPH_REPORT.md' => "# Report\n\nGod node: OrderService connects checkout to billing.",
            'wiki/index.md' => "# Index\n\n- Billing\n- Checkout",
            'wiki/billing.md' => "# Billing\n\nThe billing community handles invoices and refunds.",
        ]);

        $this->artisan('ai-chatbox:graphify', ['--path' => $dir])
            ->expectsOutputToContain('Knowledge base rebuilt')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('ai_chatbox_rag_documents', 3);

        // Every imported doc is tagged with the graphify-out/ prefix and is ready with chunks.
        $docs = RagDocument::all();
        foreach ($docs as $doc) {
            $this->assertStringStartsWith('graphify-out/', $doc->original_filename);
            $this->assertSame('ready', $doc->status);
            $this->assertGreaterThan(0, $doc->chunk_count);
        }

        $this->assertDatabaseHas('ai_chatbox_rag_documents', ['original_filename' => 'graphify-out/wiki/billing.md']);
    }

    public function test_dry_run_lists_files_without_importing(): void
    {
        $dir = $this->makeGraphDir(['GRAPH_REPORT.md' => "# Report\n\nSome content."]);

        $exitCode = Artisan::call('ai-chatbox:graphify', ['--path' => $dir, '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString('GRAPH_REPORT.md', $output);
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertDatabaseCount('ai_chatbox_rag_documents', 0);
    }

    // ── Rebuild semantics ───────────────────────────────────────────────────────

    public function test_rebuild_replaces_previous_graphify_documents(): void
    {
        $dir = $this->makeGraphDir([
            'GRAPH_REPORT.md' => "# Report\n\nContent.",
            'wiki/a.md' => "# A\n\nAlpha.",
        ]);

        Artisan::call('ai-chatbox:graphify', ['--path' => $dir]);
        $this->assertDatabaseCount('ai_chatbox_rag_documents', 2);

        // Second run must replace, not duplicate.
        Artisan::call('ai-chatbox:graphify', ['--path' => $dir]);
        $this->assertDatabaseCount('ai_chatbox_rag_documents', 2);
    }

    public function test_keep_flag_appends_instead_of_replacing(): void
    {
        $dir = $this->makeGraphDir(['GRAPH_REPORT.md' => "# Report\n\nContent."]);

        Artisan::call('ai-chatbox:graphify', ['--path' => $dir]);
        $this->assertDatabaseCount('ai_chatbox_rag_documents', 1);

        Artisan::call('ai-chatbox:graphify', ['--path' => $dir, '--keep' => true]);
        $this->assertDatabaseCount('ai_chatbox_rag_documents', 2);
    }

    public function test_rebuild_does_not_touch_documents_from_other_sources(): void
    {
        // A document that came from a normal upload (no graphify-out/ prefix).
        $uploaded = RagDocument::create([
            'title' => 'Manual upload',
            'original_filename' => 'handbook.md',
            'file_type' => 'md',
            'status' => 'ready',
            'chunk_count' => 1,
            'content' => 'Manually uploaded content.',
        ]);

        $dir = $this->makeGraphDir(['GRAPH_REPORT.md' => "# Report\n\nContent."]);
        Artisan::call('ai-chatbox:graphify', ['--path' => $dir]);

        // The uploaded document must survive the graphify rebuild.
        $this->assertDatabaseHas('ai_chatbox_rag_documents', [
            'id' => $uploaded->id,
            'original_filename' => 'handbook.md',
        ]);
        $this->assertDatabaseHas('ai_chatbox_rag_documents', ['original_filename' => 'graphify-out/GRAPH_REPORT.md']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Create a temporary directory populated with the given relative-path => content files.
     *
     * @param  array<string, string>  $files
     */
    private function makeGraphDir(array $files): string
    {
        $dir = $this->tmpPath('graphify-' . uniqid());
        @mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;

        foreach ($files as $relative => $content) {
            $full = $dir . DIRECTORY_SEPARATOR . $relative;
            @mkdir(dirname($full), 0777, true);
            file_put_contents($full, $content);
        }

        return $dir;
    }

    private function tmpPath(string $name): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ai-chatbox-tests' . DIRECTORY_SEPARATOR . $name;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
