<?php
namespace DeveloperUnijaya\AiChatbox\Services;

class DocumentChunker
{
    /**
     * Split raw text into overlapping chunks.
     *
     * Chunks are built by accumulating paragraphs (blocks separated by two or
     * more newlines). When adding the next paragraph would exceed the target
     * size, the current buffer is saved and a new one starts with the trailing
     * overlap carried over.
     *
     * Sizing is measured in CHARACTERS (multibyte-aware), not bytes: slicing on
     * a byte boundary can cut a multibyte UTF-8 codepoint in half, producing an
     * invalid byte sequence that a utf8mb4 column rejects ("Incorrect string
     * value") or that the embedding request's JSON encoder throws on. Since a
     * token is roughly 4 characters, character-based sizing is also the more
     * accurate estimate.
     *
     * @param  string  $text            Raw document text (plain text or Markdown).
     * @param  int     $chunkSizeTokens Target chunk size in tokens (~4 chars/token).
     * @param  int     $overlapTokens   Overlap between consecutive chunks in tokens.
     * @return string[]
     */
    public function chunk(string $text, int $chunkSizeTokens = 500, int $overlapTokens = 50): array
    {
        $chunkChars = $chunkSizeTokens * 4;
        $overlapChars = $overlapTokens * 4;

        // Normalize line endings and strip a single leading UTF-8 BOM.
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

        // Split on paragraph boundaries (2+ blank lines)
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            // If the paragraph itself is longer than one chunk, split it by sentences
            if (mb_strlen($para) > $chunkChars) {
                // Flush current buffer first
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = $overlapChars > 0 ? $this->tail($current, $overlapChars) : '';
                }

                // Split long paragraph on sentence boundaries (. ! ?)
                $sentences = preg_split('/(?<=[.!?])\s+/', $para) ?: [$para];
                foreach ($sentences as $sentence) {
                    // Hard-wrap a single sentence longer than one chunk so no
                    // chunk exceeds the target (a delimiter-less blob otherwise
                    // becomes one oversize chunk that can exceed the embedding
                    // model's input limit).
                    foreach ($this->hardWrap($sentence, $chunkChars) as $piece) {
                        if (mb_strlen($current) + mb_strlen($piece) + 1 > $chunkChars && $current !== '') {
                            $chunks[] = $current;
                            $current = $overlapChars > 0 ? $this->tail($current, $overlapChars) : '';
                        }
                        $current .= ($current !== '' ? ' ' : '') . $piece;
                    }
                }
                continue;
            }

            // Would adding this paragraph overflow the current chunk?
            $separator = $current !== '' ? "\n\n" : '';
            if ($current !== '' && mb_strlen($current) + mb_strlen($separator) + mb_strlen($para) > $chunkChars) {
                $chunks[] = $current;
                $current = $overlapChars > 0 ? $this->tail($current, $overlapChars) : '';
                $separator = $current !== '' ? "\n\n" : '';
            }

            $current .= $separator . $para;
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter(array_map('trim', $chunks), fn($c) => $c !== ''));
    }

    /** Return the last $chars characters of $str (for overlap carry-over). */
    private function tail(string $str, int $chars): string
    {
        return mb_strlen($str) > $chars ? mb_substr($str, -$chars) : $str;
    }

    /**
     * Split a segment longer than $maxChars into $maxChars-sized pieces on
     * character boundaries; returns [$segment] when it already fits.
     *
     * @return string[]
     */
    private function hardWrap(string $segment, int $maxChars): array
    {
        if ($maxChars < 1 || mb_strlen($segment) <= $maxChars) {
            return [$segment];
        }

        $pieces = [];
        $length = mb_strlen($segment);
        for ($offset = 0; $offset < $length; $offset += $maxChars) {
            $pieces[] = mb_substr($segment, $offset, $maxChars);
        }

        return $pieces;
    }
}
