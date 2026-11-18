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
    | Embeddings go through the Laravel AI SDK. Credentials and endpoints live
    | in config/ai.php; `provider` names one of its providers, and null means
    | `ai.default_for_embeddings`. It must be a single name: an SDK failover
    | list would store another model's vectors under this model's id.
    |
    | `model` null means the provider's default embeddings model.
    |
    | `dimensions` must match the model. Every returned vector is checked against
    | it, and it is written into the pgvector column at migration time, so
    | changing it later requires a migration and a reindex, not just a config
    | edit. `send_dimensions` asks the provider for that width; turn it off
    | ("native" mode) for models that reject a dimensions parameter, such as
    | Ollama models and many OpenAI-compatible servers. The width is still
    | checked.
    |
    | The `fake` driver is deterministic and network-free. It exists so the test
    | suite can assert real retrieval ordering without an API key.
    */

    'embedding' => [
        'driver' => env('RAG_EMBEDDING_DRIVER', 'sdk'),
        'provider' => env('RAG_EMBEDDING_PROVIDER'),
        'model' => env('RAG_EMBEDDING_MODEL'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),
        'send_dimensions' => (bool) env('RAG_EMBEDDING_SEND_DIMENSIONS', true),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 96),
        'timeout' => (int) env('RAG_EMBEDDING_TIMEOUT', 30),
        'cache' => (bool) env('RAG_EMBEDDING_CACHE', false),

        // Deprecated, and not used to send anything. It exists only so the
        // package can refuse a custom endpoint left over from 0.2, instead of
        // quietly sending that endpoint's documents and token to OpenAI. Move
        // to an "openai-compatible" provider in config/ai.php, then remove this.
        'base_url' => env('RAG_EMBEDDING_BASE_URL'),
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

        // Optional second pass: fetch `limit * candidates` by vector similarity, then
        // let a reranking model (through the SDK) pick the best `limit`. `provider` null
        // means `ai.default_for_reranking`; it must be a single name, not a failover
        // list, so scores stay comparable across a run. If the reranker fails, the
        // vector order is returned and a warning is logged; `timeout` (seconds) keeps
        // a hung reranker from holding every search. Empty provider or model values
        // count as unset. Off by default: it adds one provider call per search.
        'rerank' => [
            'enabled' => (bool) env('RAG_RERANK', false),
            'provider' => env('RAG_RERANK_PROVIDER'),
            'model' => env('RAG_RERANK_MODEL'),
            'candidates' => (int) env('RAG_RERANK_CANDIDATES', 3),
            'timeout' => in_array(env('RAG_RERANK_TIMEOUT'), [null, ''], true) ? 10 : (int) env('RAG_RERANK_TIMEOUT'),
        ],
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
    | metric falls below its floor. Each can be overridden per environment with
    | RAG_EVAL_MIN_PASS_RATE, RAG_EVAL_MIN_RECALL_AT_K and RAG_EVAL_MIN_MRR, which
    | is how a real embedding model gets higher floors than the hashed fake one.
    | An empty value is ignored rather than read as 0.0, which would switch the
    | floor off. Wire that into CI and retrieval regressions
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
            'pass_rate' => in_array(env('RAG_EVAL_MIN_PASS_RATE'), [null, ''], true) ? 0.90 : (float) env('RAG_EVAL_MIN_PASS_RATE'),
            'recall_at_k' => in_array(env('RAG_EVAL_MIN_RECALL_AT_K'), [null, ''], true) ? 0.80 : (float) env('RAG_EVAL_MIN_RECALL_AT_K'),
            'mrr' => in_array(env('RAG_EVAL_MIN_MRR'), [null, ''], true) ? 0.60 : (float) env('RAG_EVAL_MIN_MRR'),
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
