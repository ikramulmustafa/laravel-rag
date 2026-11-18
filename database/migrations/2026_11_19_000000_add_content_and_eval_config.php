<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same connection as the create migration, so the columns land beside the tables.
     */
    public function getConnection(): ?string
    {
        return config('rag.connection');
    }

    public function up(): void
    {
        $rag = $this->getConnection();

        // Setting RAG_CONNECTION after the tables were created on the default
        // connection would make this migration fail on a missing table, or worse,
        // leave the package reading an empty database. Say what happened instead.
        if (! Schema::connection($rag)->hasTable('rag_documents')
            && $rag !== null
            && Schema::hasTable('rag_documents')) {
            throw new RuntimeException(
                "The rag_* tables live on the default database connection, but RAG_CONNECTION is set to '{$rag}'. "
                .'Move the tables to that connection, or unset RAG_CONNECTION, then run this migration again.'
            );
        }

        Schema::connection($rag)->table('rag_documents', function (Blueprint $table): void {
            // The source text, so a reindex can rebuild without the original file.
            $table->longText('content')->nullable();
        });

        Schema::connection($rag)->table('rag_eval_runs', function (Blueprint $table): void {
            $table->json('config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('rag_documents', function (Blueprint $table): void {
            $table->dropColumn('content');
        });

        Schema::connection($this->getConnection())->table('rag_eval_runs', function (Blueprint $table): void {
            $table->dropColumn('config');
        });
    }
};
