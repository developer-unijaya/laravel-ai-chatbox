<?php
namespace SyafiqUnijaya\AiChatbox\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SyafiqUnijaya\AiChatbox\Services\DocumentChunker;

class DocumentChunkerTest extends TestCase
{
    private DocumentChunker $chunker;

    protected function setUp(): void
    {
        $this->chunker = new DocumentChunker();
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function test_empty_string_returns_empty_array(): void
    {
        $this->assertSame([], $this->chunker->chunk(''));
    }

    public function test_whitespace_only_returns_empty_array(): void
    {
        $this->assertSame([], $this->chunker->chunk("   \n\n  \n  "));
    }

    // ── Single chunk ──────────────────────────────────────────────────────────

    public function test_short_text_returns_single_chunk(): void
    {
        $chunks = $this->chunker->chunk('Hello world. This is a simple sentence.');
        $this->assertCount(1, $chunks);
        $this->assertSame('Hello world. This is a simple sentence.', $chunks[0]);
    }

    public function test_single_short_paragraph_is_not_split(): void
    {
        $text = "Line one.\nLine two.\nLine three."; // single blank-line-delimited block
        $chunks = $this->chunker->chunk($text);
        $this->assertCount(1, $chunks);
    }

    // ── Input normalisation ────────────────────────────────────────────────────

    public function test_strips_utf8_bom(): void
    {
        $bom = "\xEF\xBB\xBF";
        $chunks = $this->chunker->chunk($bom . 'Hello world.');
        $this->assertCount(1, $chunks);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $chunks[0]);
        $this->assertSame('Hello world.', $chunks[0]);
    }

    public function test_normalises_crlf_line_endings(): void
    {
        $text = "Paragraph one.\r\n\r\nParagraph two.";
        $chunks = $this->chunker->chunk($text, 500, 0);
        $this->assertStringNotContainsString("\r", implode('', $chunks));
    }

    // ── Paragraph splitting ────────────────────────────────────────────────────

    public function test_two_short_paragraphs_that_together_exceed_chunk_size_are_split(): void
    {
        // chunkBytes = 5 * 4 = 20; para1=12, para2=14, together=28 > 20 → split
        $para1 = 'Hello world.';  // 12 chars
        $para2 = 'Goodbye world.'; // 14 chars
        $text  = $para1 . "\n\n" . $para2;

        $chunks = $this->chunker->chunk($text, 5, 0);

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('Hello', $chunks[0]);
        $this->assertStringContainsString('Goodbye', $chunks[1]);
    }

    public function test_multiple_blank_lines_between_paragraphs_are_treated_as_one_boundary(): void
    {
        $para1 = 'Hello world.';   // 12 chars
        $para2 = 'Goodbye world.'; // 14 chars
        $text  = $para1 . "\n\n\n\n" . $para2; // 4 blank lines

        $chunks = $this->chunker->chunk($text, 5, 0);

        $this->assertCount(2, $chunks);
    }

    // ── Overlap carry-over ────────────────────────────────────────────────────

    public function test_overlap_carries_tail_of_previous_chunk_into_next(): void
    {
        // chunkBytes = 3 * 4 = 12, overlapBytes = 1 * 4 = 4
        // Para1 = 12 chars (exactly fills chunk); Para2 overflows → split with 4-char tail carried
        $para1 = 'ABCDEFGHIJKL'; // 12 chars
        $para2 = 'MNOPQRSTUVWX'; // 12 chars; together 12+2+12=26 > 12 → overflow
        $text  = $para1 . "\n\n" . $para2;

        $chunks = $this->chunker->chunk($text, 3, 1);

        $this->assertGreaterThanOrEqual(2, count($chunks));
        // The last 4 bytes of chunk[0] ('IJKL') should appear at the start of chunk[1]
        $tail = substr($chunks[0], -4);
        $this->assertStringStartsWith($tail, $chunks[1]);
    }

    public function test_zero_overlap_has_no_carryover_between_chunks(): void
    {
        // Same setup but overlap=0 → chunk[1] must not contain any character from chunk[0]
        $para1 = 'ABCDEFGHIJKL'; // 12 chars
        $para2 = 'MNOPQRSTUVWX'; // 12 chars
        $text  = $para1 . "\n\n" . $para2;

        $chunks = $this->chunker->chunk($text, 3, 0);

        $this->assertCount(2, $chunks);
        $this->assertStringNotContainsString('A', $chunks[1]);
        $this->assertStringNotContainsString('L', $chunks[1]);
    }

    // ── Long paragraph → sentence splitting ───────────────────────────────────

    public function test_long_paragraph_is_split_by_sentence_boundaries(): void
    {
        // chunkBytes = 5 * 4 = 20; paragraph = 3 sentences (~48 chars > 20) → sentence split
        $text = 'First sentence. Second sentence. Third sentence.';

        $chunks = $this->chunker->chunk($text, 5, 0);

        $this->assertGreaterThanOrEqual(2, count($chunks));
        $this->assertStringContainsString('First sentence.', $chunks[0]);
    }

    public function test_sentence_split_honours_exclamation_and_question_marks(): void
    {
        $text = 'Is this working? Yes it is! Great news.';

        $chunks = $this->chunker->chunk($text, 2, 0); // chunkBytes=8

        $this->assertGreaterThanOrEqual(2, count($chunks));
    }

    // ── Output guarantees ──────────────────────────────────────────────────────

    public function test_all_returned_chunks_are_trimmed(): void
    {
        $text = "  Leading spaces.\n\n  Trailing spaces.  ";
        $chunks = $this->chunker->chunk($text);

        foreach ($chunks as $chunk) {
            $this->assertSame(trim($chunk), $chunk, "Chunk not trimmed: '{$chunk}'");
        }
    }

    public function test_no_empty_chunks_are_returned(): void
    {
        $text = "Para one.\n\n\n\n\n\nPara two.";
        $chunks = $this->chunker->chunk($text);

        foreach ($chunks as $chunk) {
            $this->assertNotSame('', $chunk, 'Empty chunk found in result');
        }
    }

    public function test_result_indices_are_zero_based_sequential(): void
    {
        $text = "Hello world.\n\nGoodbye world."; // 12 + 14 = together 28
        $chunks = $this->chunker->chunk($text, 5, 0); // force split

        $this->assertSame(array_keys($chunks), range(0, count($chunks) - 1));
    }

    public function test_default_parameters_handle_large_documents(): void
    {
        // 5 paragraphs of 200 words each; default 500-token chunks should group them
        $text = implode("\n\n", array_fill(0, 5, str_repeat('word ', 200)));
        $chunks = $this->chunker->chunk($text);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertNotSame('', trim($chunk));
        }
    }
}
