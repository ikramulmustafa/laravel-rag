<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $dimensions = (int) config('rag.embedding.dimensions', 1536);
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('rag_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->comment('URI, file path, or opaque identifier for the origin');
            $table->string('collection')->default('default');
            $table->string('content_hash', 64)->comment('sha256 of source text; skips re-embedding unchanged docs');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source', 'collection']);
            $table->index('content_hash');
        });

        Schema::create('rag_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('rag_documents')->cascadeOnDelete();
            $table->text('text');
            $table->unsignedInteger('position');
            $table->json('metadata')->nullable();

            // Provenance. Vectors from different models occupy different spaces and
            // are not comparable. Without this column a model swap produces a corpus
            // that returns confident nonsense instead of an error.
            $table->string('embedding_model', 128);

            $table->timestamps();

            $table->index(['document_id', 'position']);
            $table->index('embedding_model');
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE rag_chunks ADD COLUMN embedding vector({$dimensions})");

            // HNSW over cosine distance. Built after the column exists so the
            // dimensionality is already fixed.
            DB::statement(
                'CREATE INDEX rag_chunks_embedding_idx ON rag_chunks '
                .'USING hnsw (embedding vector_cosine_ops)'
            );
        } else {
            // SQLite and MySQL have no native vector type. Storing JSON keeps the
            // test suite runnable everywhere; the retriever falls back to in-PHP
            // cosine similarity, which is correct but only viable on small corpora.
            Schema::table('rag_chunks', function (Blueprint $table): void {
                $table->json('embedding')->nullable();
            });
        }

        Schema::create('rag_eval_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('suite');
            $table->string('embedding_model', 128);
            $table->unsignedInteger('total_cases');
            $table->json('metrics')->comment('metric name => score');
            $table->json('failures')->nullable();
            $table->string('git_sha', 40)->nullable();
            $table->timestamps();

            $table->index(['suite', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_eval_runs');
        Schema::dropIfExists('rag_chunks');
        Schema::dropIfExists('rag_documents');
    }
};
