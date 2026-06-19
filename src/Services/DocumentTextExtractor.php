<?php
namespace SyafiqUnijaya\AiChatbox\Services;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * Extracts plain UTF-8 text from an uploaded knowledge-base document.
 *
 * Supported formats:
 *   - .txt / .md  → read as-is
 *   - .docx       → parsed from word/document.xml via the bundled ext-zip
 *   - .pdf        → parsed via the optional "smalot/pdfparser" package
 *
 * The extracted text is what gets stored on the RagDocument and re-chunked on
 * reprocess, so binary formats are converted to text exactly once at upload.
 */
class DocumentTextExtractor
{
    /** File extensions this extractor can handle. */
    public const SUPPORTED = ['txt', 'md', 'pdf', 'docx'];

    /**
     * @param  string  $path       Absolute path to the uploaded file on disk.
     * @param  string  $extension  File extension (case-insensitive).
     * @return string  Extracted, normalised UTF-8 text.
     *
     * @throws RuntimeException  When the type is unsupported, a required library
     *                           is missing, or the file cannot be read.
     */
    public function extract(string $path, string $extension): string
    {
        $extension = strtolower($extension);

        $text = match ($extension) {
            'txt', 'md' => (string) file_get_contents($path),
            'pdf' => $this->extractPdf($path),
            'docx' => $this->extractDocx($path),
            default => throw new RuntimeException(
                "Unsupported file type '.{$extension}'. Accepted: " . implode(', ', self::SUPPORTED) . '.'
            ),
        };

        return $this->normalize($text);
    }

    // ── PDF ────────────────────────────────────────────────────────────────────

    private function extractPdf(string $path): string
    {
        if (!class_exists(PdfParser::class)) {
            throw new RuntimeException(
                'PDF support requires the "smalot/pdfparser" package. '
                . 'Install it with: composer require smalot/pdfparser'
            );
        }

        try {
            return (new PdfParser())->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not read the PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── DOCX ───────────────────────────────────────────────────────────────────

    private function extractDocx(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('DOCX support requires the PHP zip extension (ext-zip).');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the DOCX file — it is not a valid Office Open XML archive.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('The DOCX file has no readable document body (word/document.xml is missing).');
        }

        // Preserve structure before stripping tags: paragraph ends become blank
        // lines (so the chunker can split on them), tabs/breaks become whitespace.
        $xml = str_replace(
            ['</w:p>', '<w:tab/>', '<w:br/>', '<w:br />'],
            ["\n\n", "\t", "\n", "\n"],
            $xml
        );

        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Normalise line endings, collapse runs of blank lines, and trim. */
    private function normalize(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
