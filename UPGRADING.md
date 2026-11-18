# Upgrading

## 0.2 to 0.3

0.3 rebuilds the package on the official Laravel AI SDK (`laravel/ai`). Embedding calls now go through the SDK, so credentials and endpoints move to `config/ai.php`. Most 0.2 installs need a handful of changes and no reindex.

### Before you start: did you set `base_url`?

If you set `rag.embedding.base_url` or `RAG_EMBEDDING_BASE_URL` under 0.2 (an OpenAI-compatible gateway, a proxy, a local server), do the migration below first.

0.3 no longer reads that value to send anything. It refuses to embed, whichever driver is configured, while `base_url` is set to anything other than `https://api.openai.com/v1`: the first embedding call throws `InvalidArgumentException` before any request is made, naming the host. Left alone, the SDK would send your documents to api.openai.com instead. Check what your app sees with:

```bash
php artisan config:show rag.embedding
```

1. Your 0.2 token lived in `OPENAI_API_KEY`. Move it to the key of the new provider, `OPENAI_COMPATIBLE_API_KEY`, and make sure `OPENAI_API_KEY` no longer holds an internal token.
2. In `config/ai.php`, point an `openai-compatible` provider at your endpoint (`OPENAI_COMPATIBLE_URL`).
3. Set `RAG_EMBEDDING_DRIVER=sdk` and `RAG_EMBEDDING_PROVIDER=openai-compatible`.
4. Remove `base_url` from `config/rag.php` and `RAG_EMBEDDING_BASE_URL` from the environment.
5. Run `php artisan rag:reindex --stale`. The driver part of the embedding id changes, so every chunk counts as stale.

If your endpoint is a proxy for the same OpenAI model, you can instead keep driver `openai` and set `OPENAI_URL` (the SDK's `openai` provider reads it), with your token in `OPENAI_API_KEY`. You still have to remove `base_url` / `RAG_EMBEDDING_BASE_URL`. See the id trade-off below: that change does not alter the embedding id.

### Requirements

- PHP 8.3 or newer (0.2 allowed 8.2).
- Laravel 12.62+ or 13.15+.
- `laravel/ai` `^1.4`: `composer require laravel/ai`, then `php artisan vendor:publish --tag=ai-config`.

### A published `config/rag.php`

If you published `config/rag.php` under 0.2, it replaces the package's `embedding` block wholesale, so the new keys do not exist and their env vars (`RAG_EMBEDDING_PROVIDER`, `RAG_RERANK`, `RAG_EVAL_MIN_*`, and the rest) do nothing. The package logs a warning once when it sees a config without the `provider` key. Either re-publish and re-apply your edits:

```bash
php artisan vendor:publish --tag=rag-config --force
```

or copy the `embedding`, `retrieval.rerank` and `eval.thresholds` blocks from the package's `config/rag.php` into yours.

### Configuration

1. Set `RAG_EMBEDDING_DRIVER=sdk` (the new default) and `RAG_EMBEDDING_PROVIDER=openai`, with your key in `OPENAI_API_KEY` as before, now read by `config/ai.php`.
2. `rag.embedding.api_key` is removed. `rag.embedding.base_url` is deprecated, is not used to send anything, and is refused when it is not the OpenAI default (see above). Remove both from a published config.
3. A published 0.2 config that still says `driver => 'openai'` keeps working: the `openai` driver is a deprecated alias for `sdk` with provider `openai`, logs one deprecation warning, and goes in 1.0.
4. New keys: `provider`, `send_dimensions` and `cache` under `embedding`, `rerank.*` under `retrieval`, and the `RAG_EVAL_MIN_*` overrides under `eval.thresholds`. Keys your config lacks fall back to their defaults in code, but their env vars only take effect once the keys exist in the file.

### `text-embedding-ada-002`

This model rejects a `dimensions` parameter, and the SDK's `openai` provider always sends one. Use the `openai-compatible` provider with `RAG_EMBEDDING_SEND_DIMENSIONS=false`. Native mode omits the parameter only on providers whose default width is 0 (`ollama`, `openai-compatible`).

### Database

Run `php artisan migrate`. Two columns are added: `rag_documents.content` (the source text, so reindexing no longer needs the file) and `rag_eval_runs.config`.

If you set `RAG_CONNECTION` under 0.2, the tables were created on the default connection. The new migrations run on the RAG connection, and if the tables are missing there but exist on the default one they stop with a message explaining how to move them or unset `RAG_CONNECTION`. They never write to the wrong database.

### Embedding ids and reindexing

Ids are now `{driver}:{model}:{dimensions}`, for example `openai:text-embedding-3-small:1536`. Rows written by 0.2 carry the old form without the width. They are matched on read and never rewritten, so with the same driver, model and width you need no reindex and no data migration.

I have not verified that OpenAI returns identical vectors for 3-small when `dimensions=1536` is sent explicitly, as 0.3 does. Before upgrading, save a baseline under 0.2 with `php artisan rag:eval --save=baseline.json`. After upgrading, run `php artisan rag:eval --baseline=baseline.json` and compare.

- A change of provider or model name at the same width needs `php artisan rag:reindex --stale`.
- A change of width on Postgres needs `php artisan rag:reindex --resize`, which changes the column, clears the vectors and re-embeds. It refuses non-`vector` columns and `--collection`. Until it finishes, search returns fewer results, and any document that failed to re-embed stays unsearchable until you fix it and rerun.
- The `fake` driver's id now includes its width (`fake:deterministic-hash:64`), and its width comes from `rag.embedding.dimensions` (default 1536). Set 64 explicitly in tests that expect it.

**Before `--stale` or `--resize` on a 0.2 corpus**, re-run your normal ingestion once. Documents ingested under 0.2 have no stored text. Unchanged documents are skipped by the ingest but now save their text, and without that `rag:reindex` falls back to reading each document's `source` as a file path, which fails for content that came from a database or a path that has moved.

**The id uses the provider's driver, not its config key.** Two providers with the same driver and model name (two `openai-compatible` endpoints, or an `openai` provider repointed with `OPENAI_URL`) share an id. The mixed-model guard cannot tell them apart and `--stale` will not pick up the change. When you swap such an endpoint, rebuild with `php artisan rag:reindex --force`.

### Code changes

- `Ikram\Rag\Embedding\OpenAIEmbedder` is removed. Use the `sdk` driver, or register your own `Embedder` with `Rag::extend()` from a service provider, before the embedder is first used.
- The `Retriever` contract changed: `retrieve(string $query, ?int $limit = null, array $filters = [])`. It was `int $limit = 5`. A custom `Retriever` implementation must update its signature or PHP fails when it loads.
- `RagManager`'s constructor is now `(Container $container, EmbedderRegistry $embedders)`, and it resolves the retriever and ingestor lazily. This only matters if you construct it by hand.
- `Ingestor::confirmWith()` returns a configured clone instead of changing the shared instance. Use its return value.
- `retrieval.default_limit` is now honoured: pass no limit and it is used. A limit below 1 throws `InvalidArgumentException`. Blank queries return an empty result without calling the provider.
- Filter keys must match `/^[A-Za-z0-9_]+$/`.
- The mixed-model check is scoped to the collection and filters you search, and ignores rows without a vector.
- Vector widths are checked against the embedder's width. On ingest a mismatch throws before anything is written, and on retrieval before the vector search runs.
- Token-usage reporting from embedding calls is removed. The SDK reports 0 for cached and faked calls, so the number would mislead.

### Eval

Runs now record a `config` object (`k`, chunking, `min_score`, reranking, embedding id). `--baseline` warns when the settings differ from the baseline's. A baseline saved by 0.2 is accepted with a note that it predates config recording, and does not raise a false "different embedding model" warning.

The floors in `rag.eval.thresholds` can now be overridden with `RAG_EVAL_MIN_PASS_RATE`, `RAG_EVAL_MIN_RECALL_AT_K` and `RAG_EVAL_MIN_MRR`. Empty values are ignored.

### New in 0.3

`Rag::tool()` (a cited search tool for SDK agents), optional reranking, and `rag:reindex --stale` / `--resize`. See the README.
