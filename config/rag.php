<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    | null uses the default. Postgres with the pgvector extension is the only
    | configuration suitable for production; other drivers fall back to computing
    | cosine similarity in PHP, which is correct but loads every candidate row
    | into memory.
    */

    'connection' => env('RAG_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Embedding
    |--------------------------------------------------------------------------
    | `dimensions` must match the model. It is written into the pgvector column
    | at migration time, so changing it later requires a migration and a reindex,
    | not just a config edit.
    |
    | The `fake` driver is deterministic and network-free. It exists so the test
    | suite can assert real retrieval ordering without an API key.
    */

    'embedding' => [
        'driver' => env('RAG_EMBEDDING_DRIVER', 'openai'),
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('RAG_EMBEDDING_BASE_URL', 'https://api.openai.com/v1'),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 96),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    | Overlap must be smaller than size. These defaults suit prose; dense reference
    | material (tables, specs, legal text) generally wants smaller chunks with
    | proportionally more overlap.
    |
    | Do not tune these by intuition. Change one value, run `php artisan rag:eval`,
    | and keep the change only if recall improves. That loop is the whole reason
    | the eval harness ships with the package.
    */

    'chunking' => [
        'size' => (int) env('RAG_CHUNK_SIZE', 1000),
        'overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    | `min_score` drops weak matches instead of padding the prompt with them. A
    | retriever that always returns k results will happily hand the model irrelevant
    | context for a question the corpus cannot answer, and the model will use it.
    |
    | Returning nothing is a valid, useful answer. 0.0 disables the floor; raise it
    | once your eval suite includes cases with `expect_no_answer: true`.
    */

    'retrieval' => [
        'default_limit' => (int) env('RAG_RETRIEVAL_LIMIT', 5),
        'min_score' => (float) env('RAG_RETRIEVAL_MIN_SCORE', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    | Above this estimated token count, `rag:ingest` asks for confirmation before
    | calling the embedding API. Embedding spend is invisible until the invoice.
    */

    'ingestion' => [
        'token_warning_threshold' => (int) env('RAG_TOKEN_WARNING_THRESHOLD', 100_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation
    |--------------------------------------------------------------------------
    | Thresholds are enforced by `rag:eval --ci`, which exits non-zero when any
    | metric falls below its floor. Wire that into CI and retrieval regressions
    | fail the build instead of reaching production silently.
    |
    | `tolerances` applies only when `--baseline` names a previous run. Thresholds
    | answer "is this bad?"; tolerances answer "did this get worse?", which is the
    | more useful question once a corpus is large enough that no absolute number is
    | obviously right. A tolerance is how far a metric may drop before `--ci` treats
    | the drop as a regression; 0.0 means any measurable drop fails.
    */

    'eval' => [
        'suite_path' => env('RAG_EVAL_SUITE', base_path('tests/rag/suite.json')),
        'k' => (int) env('RAG_EVAL_K', 5),
        'thresholds' => [
            'pass_rate' => 0.90,
            'recall_at_k' => 0.80,
            'mrr' => 0.60,
        ],
        'tolerances' => [
            'pass_rate' => 0.0,
            'recall_at_k' => 0.0,
            'mrr' => 0.0,
            // Absolute similarity drifts a little with any corpus change, so this one
            // is given room. A sustained slide here usually means corpus dilution.
            'mean_top_score' => 0.02,
        ],
    ],

];
