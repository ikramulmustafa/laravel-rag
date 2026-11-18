# Upgrading

## 0.2 to 0.3

0.3 rebuilds the package on the official Laravel AI SDK (`laravel/ai`). Embedding calls now go through the SDK, so credentials and endpoints move to `config/ai.php`. Most 0.2 installs need a handful of changes and no reindex.

### Requirements

- PHP 8.3 or newer (0.2 allowed 8.2).
- Laravel 12.62+ or 13.15+.
- `laravel/ai` `^1.4`: `composer require laravel/ai`, then `php artisan vendor:publish --tag=ai-config`.

### Configuration

1. Set `RAG_EMBEDDING_DRIVER=sdk` (the new default) and `RAG_EMBEDDING_PROVIDER=openai`, with your key in `OPENAI_API_KEY` as before, now read by `config/ai.php`.
2. The `rag.embedding.api_key` and `rag.embedding.base_url` keys are removed. Remove them from a published `config/rag.php`.
3. A published 0.2 config that still says `driver => 'openai'` keeps working: the `openai` driver is a deprecated alias for `sdk` with provider `openai`, logs one deprecation warning, and goes in 1.0. New keys that a 0.2 config lacks fall back to their defaults.
4. New keys: `provider`, `send_dimensions`, `batch_size`, `timeout` and `cache` under `embedding`, and `rerank.*` under `retrieval`. See `config/rag.php`.

### If you set `base_url`

The `openai` alias refuses to run when `rag.embedding.base_url` is set to anything other than `https://api.openai.com/v1`. It throws `InvalidArgumentException` the first time the embedder is used (not at boot), with migration advice. This is deliberate: the alias can no longer honour that URL, and carrying on would send your documents, and any token meant for your own endpoint, to api.openai.com.

Move to the SDK's `openai-compatible` provider:

1. In `config/ai.php`, point an `openai-compatible` provider at your endpoint (`OPENAI_COMPATIBLE_URL`, `OPENAI_COMPATIBLE_API_KEY`).
2. Set `RAG_EMBEDDING_DRIVER=sdk` and `RAG_EMBEDDING_PROVIDER=openai-compatible`.
3. Run `php artisan rag:reindex --stale`. The driver part of the embedding id changes, so every chunk counts as stale.

### `text-embedding-ada-002`

This model rejects a `dimensions` parameter, and the SDK's `openai` provider always sends one. Use the `openai-compatible` provider with `RAG_EMBEDDING_SEND_DIMENSIONS=false`. Native mode omits the parameter only on providers whose default width is 0 (`ollama`, `openai-compatible`).

### Database

Run `php artisan migrate`. Two columns are added: `rag_documents.content` (the source text, so reindexing no longer needs the file) and `rag_eval_runs.config`.

If you set `RAG_CONNECTION` under 0.2, the tables were created on the default connection. The new migrations run on the RAG connection, and if the tables are missing there but exist on the default one they stop with a message explaining how to move them or unset `RAG_CONNECTION`. They never write to the wrong database.

Documents ingested before 0.3 have no stored `content`. `rag:reindex` re-reads their files, as 0.2 did, and stores the content from then on.

### Embedding ids and reindexing

Ids are now `{driver}:{model}:{dimensions}`, for example `openai:text-embedding-3-small:1536`. Rows written by 0.2 carry the old form without the width. They are matched on read and never rewritten, so with the same driver, model and width you need no reindex and no data migration.

I have not verified that OpenAI returns identical vectors for 3-small when `dimensions=1536` is sent explicitly, as 0.3 does. After upgrading, run `php artisan rag:eval` and compare against a saved baseline.

- A change of provider or model at the same width needs `php artisan rag:reindex --stale`.
- A change of width on Postgres needs `php artisan rag:reindex --resize`, which changes the column, clears the vectors and re-embeds. It refuses non-`vector` columns and `--collection`, and until it finishes search returns fewer results. Rerun `rag:reindex --stale` to finish a partial run.
- The `fake` driver's id now includes its width (`fake:deterministic-hash:64`), and its width comes from `rag.embedding.dimensions` (default 1536). Set 64 explicitly in tests that expect it.

### Code changes

- `Ikram\Rag\Embedding\OpenAIEmbedder` is removed. Use the `sdk` driver, or register your own `Embedder` with `Rag::extend()` from a service provider, before the embedder is first used.
- `RagManager`'s constructor is now `(Container $container, EmbedderRegistry $registry)`, and it resolves the retriever and ingestor lazily. This only matters if you construct it by hand.
- `Ingestor::confirmWith()` returns a configured clone instead of changing the shared instance. Use its return value.
- `retrieval.default_limit` is now honoured: pass no limit and it is used. A limit below 1 throws `InvalidArgumentException`. Blank queries return an empty result without calling the provider.
- Filter keys must match `/^[A-Za-z0-9_]+$/`.
- The mixed-model check is scoped to the collection and filters you search, and ignores rows without a vector.
- Vector widths are checked on ingest and retrieval, and a mismatch throws before any SQL runs.
- Token-usage reporting from embedding calls is removed. The SDK reports 0 for cached and faked calls, so the number would mislead.

### Eval

Runs now record a `config` object (`k`, chunking, `min_score`, reranking, embedding id). `--baseline` warns when the settings differ from the baseline's. A baseline saved by 0.2 is accepted with a note that it predates config recording, and does not raise a false "different embedding model" warning.

### New in 0.3

`Rag::tool()` (a cited search tool for SDK agents), optional reranking, and `rag:reindex --stale` / `--resize`. See the README.
