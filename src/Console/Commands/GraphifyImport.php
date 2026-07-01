<?php
namespace DeveloperUnijaya\AiChatbox\Console\Commands;

use DeveloperUnijaya\AiChatbox\AiManager;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use DeveloperUnijaya\AiChatbox\Services\RagIngestor;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Rebuilds the RAG knowledge base from a graphify knowledge graph.
 *
 * graphify (https://github.com/safishamsi/graphify) is run once against the host
 * application and its markdown outputs (GRAPH_REPORT.md, and the per-community
 * `--wiki` / `--obsidian` articles) are committed to the repo under graphify-out/.
 * This command imports those markdown files into the chatbox's knowledge base so
 * the assistant can answer questions about the system it is embedded in.
 *
 * Each run replaces the documents it previously imported (matched by the
 * `graphify-out/` original_filename prefix), so the knowledge base always mirrors
 * the committed graph. Pass --keep to append instead of replace.
 */
class GraphifyImport extends Command
{
    protected $signature = 'ai-chatbox:graphify
        {--path= : Path to the graphify-out directory (defaults to base_path("graphify-out"))}
        {--keep : Keep previously imported graphify documents instead of replacing them}
        {--dry-run : List the markdown files that would be imported without changing anything}';

    protected $description = 'Rebuild the RAG knowledge base from a graphify knowledge graph (graphify-out/ markdown).';

    /** original_filename prefix that tags documents created by this command. */
    private const SOURCE_PREFIX = 'graphify-out/';

    public function handle(AiManager $aiManager, RagIngestor $ingestor): int
    {
        $dir = rtrim($this->option('path') ?: base_path('graphify-out'), "/\\");

        if (!is_dir($dir)) {
            $this->error("graphify-out directory not found: {$dir}");
            $this->line('Run graphify on this project first (e.g. `graphify . --wiki`) and commit the resulting');
            $this->line('graphify-out/ folder, then re-run this command — or pass --path=<dir>.');
            return self::FAILURE;
        }

        /** @var \Symfony\Component\Finder\SplFileInfo[] $files */
        $files = array_values(iterator_to_array(
            (new Finder())->files()->in($dir)->name('*.md')->sortByName(),
            false
        ));

        if ($files === []) {
            $this->error("No markdown files found under {$dir}.");
            $this->line('graphify must export markdown: GRAPH_REPORT.md is always produced; `--wiki` and `--obsidian` add more.');
            return self::FAILURE;
        }

        $this->info(count($files) . ' markdown file(s) found in ' . $dir . ':');
        foreach ($files as $f) {
            $this->line('  • ' . str_replace('\\', '/', $f->getRelativePathname()) . '  (' . $this->human($f->getSize()) . ')');
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was imported.');
            return self::SUCCESS;
        }

        $cfg = $aiManager->resolveConfig(config('ai-chatbox.active_provider', 'default'));

        if (!$this->option('keep')) {
            $stale = RagDocument::where('original_filename', 'like', self::SOURCE_PREFIX . '%')->get();
            if ($stale->isNotEmpty()) {
                foreach ($stale as $doc) {
                    $doc->chunks()->delete();
                    $doc->delete();
                }
                $this->line("Replaced {$stale->count()} previously imported graphify document(s).");
            }
        }

        $imported = 0;
        $totalChunks = 0;

        foreach ($files as $file) {
            $rel = self::SOURCE_PREFIX . str_replace('\\', '/', $file->getRelativePathname());
            $content = @file_get_contents($file->getRealPath());

            if ($content === false || trim($content) === '') {
                $this->warn("  skipped (empty/unreadable): {$rel}");
                continue;
            }

            $document = RagDocument::create([
                'title' => 'Graphify — ' . str_replace('\\', '/', $file->getRelativePathname()),
                'original_filename' => $rel,
                'file_type' => 'md',
                'status' => 'processing',
                'chunk_count' => 0,
                'content' => $content,
            ]);

            try {
                $ingestor->ingest($document, $content, $cfg);
            } catch (Throwable $e) {
                $document->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                $this->warn("  failed: {$rel} — {$e->getMessage()}");
                continue;
            }

            $chunks = (int) $document->fresh()->chunk_count;
            $totalChunks += $chunks;
            $imported++;
            $this->line("  indexed: {$rel} ({$chunks} chunks)");
        }

        $this->newLine();
        $this->info("Knowledge base rebuilt: {$imported} document(s), {$totalChunks} chunk(s) from graphify.");

        if (!config('ai-chatbox.rag_enabled')) {
            $this->warn('RAG is currently disabled — set AI_CHATBOX_RAG=true so the chatbox uses these documents.');
        }

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }
}
