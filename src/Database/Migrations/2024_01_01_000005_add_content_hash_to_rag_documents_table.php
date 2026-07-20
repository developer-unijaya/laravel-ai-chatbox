<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbox_rag_documents', function (Blueprint $table) {
            // sha256 of the ingested content — lets callers skip re-embedding an
            // unchanged document (e.g. the graphify importer on repeat runs).
            $table->string('content_hash', 64)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbox_rag_documents', function (Blueprint $table) {
            $table->dropColumn('content_hash');
        });
    }
};
