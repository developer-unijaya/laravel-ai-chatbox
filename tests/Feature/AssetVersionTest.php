<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use Illuminate\Support\Facades\View;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class AssetVersionTest extends TestCase
{
    public function test_asset_cache_buster_is_not_empty(): void
    {
        // The published CSS/JS are cache-busted with ?v=<aiChatboxVersion>.
        // It must never be empty (the old config('app.version') resolved to ''
        // for every install, so browsers served stale assets after upgrades).
        $version = View::shared('aiChatboxVersion');

        $this->assertNotEmpty($version);
        $this->assertIsString($version);
    }
}
