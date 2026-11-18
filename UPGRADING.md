# Upgrading

## 0.2 to 0.3

0.3 rebuilds the package on the official Laravel AI SDK (`laravel/ai`). Embedding calls now go through the SDK, so credentials and endpoints move to `config/ai.php`. It needs PHP 8.3+ and Laravel 12.62+ or 13.15+ (0.2 allowed PHP 8.2).

Do Step 0, then follow **one** of Path A or Path B, not both. Path A is for apps that used OpenAI directly. Path B is for apps that set a custom endpoint. If you are not sure which you are, run `php artisan config:show rag.embedding` under 0.2: a `base_url` other than `https://api.openai.com/v1` means Path B.

### Step 0: before you change anything

1. Under 0.2, before upgrading the package, save a baseline (recommended). I have not verified that OpenAI returns identical vectors for 3-small when `dimensions=1536` is sent explicitly, as 0.3 does, so this is how you find out:

   ```bash
   php artisan rag:eval --save=storage/rag-baseline-0.2.json
   ```

2. Upgrade and install the SDK:

   ```bash
   composer require laravel/ai ikramulmustafa/laravel-rag:^0.3
   php artisan vendor:publish --tag=ai-config   # only if config/ai.php does not exist yet
   php artisan migrate
   ```

   `migrate` adds `rag_documents.content` (the source text, so reindexing no longer needs the file) and `rag_eval_runs.config`. If you set `RAG_CONNECTION` under 0.2, your tables were created on the default connection. The new migrations run on the RAG connection and, if the tables are missing there but exist on the default one, stop with a message about moving them or unsetting `RAG_CONNECTION`. They never write to the wrong database.

3. If you ever published `config/rag.php`, re-publish it now and re-apply your edits:

   ```bash
   php artisan vendor:publish --tag=rag-config --force
   ```

   or copy the new blocks from the package's `config/rag.php` into yours. Every top-level block of a published file (`embedding`, `retrieval`, `eval`, and so on) replaces the package's block wholesale, so keys added in 0.3 do not exist in yours and their env vars are ignored: `RAG_EMBEDDING_PROVIDER`, `RAG_EMBEDDING_SEND_DIMENSIONS`, `RAG_EMBEDDING_TIMEOUT`, `RAG_EMBEDDING_CACHE`, `RAG_RERANK*` and `RAG_EVAL_MIN_*`. (`RAG_EMBEDDING_DRIVER`, `RAG_EMBEDDING_MODEL`, `RAG_EMBEDDING_DIMENSIONS` and `OPENAI_API_KEY` still work.) The package logs a warning once when it sees a config without the `provider` key.

4. Check:

   ```bash
   php artisan config:show rag.embedding
   ```

   The output must include a `provider` key. If it does not, the published config is still the old one. Stop and fix that before continuing.

### Path A: you used OpenAI directly

You did not set `base_url` or `RAG_EMBEDDING_BASE_URL`.

1. Set `RAG_EMBEDDING_DRIVER=sdk` and `RAG_EMBEDDING_PROVIDER=openai`, or leave both alone: the `openai` driver still works as a deprecated alias for `sdk` with provider `openai`, logs one deprecation warning, and goes in 1.0.
2. Your key stays in `OPENAI_API_KEY`, now read by `config/ai.php`.
3. Keep the same model and `RAG_EMBEDDING_DIMENSIONS`. Rows written by 0.2 carry an id without the width (`openai:text-embedding-3-small`); 0.3 writes `openai:text-embedding-3-small:1536`, and the two match on read. No reindex and no data migration are needed.
4. Run the eval and compare with the baseline from Step 0:

   ```bash
   php artisan rag:eval --baseline=storage/rag-baseline-0.2.json
   ```

`text-embedding-ada-002` rejects a `dimensions` parameter, and the SDK's `openai` provider always sends one. It only works through an `openai-compatible` provider with `RAG_EMBEDDING_SEND_DIMENSIONS=false`, which is Path B's setup pointed at OpenAI's URL. Native mode omits the parameter only on providers whose default width is 0 (`ollama`, `openai-compatible`).

### Path B: you used a custom endpoint

You set `rag.embedding.base_url` or `RAG_EMBEDDING_BASE_URL` (a gateway, a proxy, a local server).

0.3 no longer uses that value to send anything. While it is set to anything other than `https://api.openai.com/v1`, the `sdk` driver and the `openai` alias refuse to embed: the first embedding call throws `InvalidArgumentException` before any request is made, naming the host. Without that refusal the SDK would send your documents to api.openai.com. (The `fake` driver and drivers you register with `Rag::extend()` are not checked.)

Do these in order, and do each check.

1. In `config/ai.php`, use the `openai-compatible` provider (the SDK's default file already has one) and set `OPENAI_COMPATIBLE_URL` to your endpoint and `OPENAI_COMPATIBLE_API_KEY` to your endpoint's token.
2. Move the token out of `OPENAI_API_KEY`. Your 0.2 token lived there. Unset it, or set it to a real OpenAI key only if you also use OpenAI elsewhere in the app.
3. Set:

   ```env
   RAG_EMBEDDING_DRIVER=sdk
   RAG_EMBEDDING_PROVIDER=openai-compatible
   RAG_EMBEDDING_MODEL=<the model your endpoint serves>
   RAG_EMBEDDING_DIMENSIONS=<its width>
   ```

   `RAG_EMBEDDING_MODEL` is required: the `openai-compatible` provider has no default embeddings model and throws without one. If your endpoint rejects a `dimensions` parameter, also set `RAG_EMBEDDING_SEND_DIMENSIONS=false`.
4. Remove `base_url` from `config/rag.php` and `RAG_EMBEDDING_BASE_URL` from `.env`.
5. Check `php artisan config:show rag.embedding`. It must show `provider` as your `openai-compatible` key and `base_url` as null or absent. Do not continue until it does.
6. Re-embed. The driver part of the embedding id changed (`openai` to `openai-compatible`), so every stored chunk now counts as another model's. What each command does:
   - Running your normal ingestion again re-embeds each document it covers. It does not skip them as unchanged, because the stored chunks do not match the current id, and it stores the document's text. This spends embedding calls, and asks for confirmation above `rag.ingestion.token_warning_threshold`.
   - `php artisan rag:reindex --stale --force` re-embeds every document that still has a chunk from another id, from its stored text (falling back to reading `source` as a file path for a document that has no stored text, which fails if that is not a readable path). If the width changed on Postgres, run `php artisan rag:reindex --resize --force` first instead; it changes the column, clears every vector and then re-embeds.
   - Until it finishes, search throws `MixedEmbeddingModels` on a mixed corpus (or, after `--resize`, returns fewer results). A failure on one document does not stop the run: the command lists the failed documents, exits non-zero, and those documents keep their old vectors (or, after a resize, have none and stay out of search). Fix the cause, then run `php artisan rag:reindex --stale --force` again; it only touches documents still on another id.
7. Run `php artisan rag:eval` and compare with your baseline.

Optional alternative: if your endpoint is a proxy serving the same OpenAI model, you can keep driver `openai` and set `OPENAI_URL`, which the SDK's `openai` provider reads. That repoints every SDK `openai` call in the app (chat, agents, everything), not only embeddings, and you must still remove `RAG_EMBEDDING_BASE_URL`. The embedding id does not change, so see the next section.

### Embedding ids, and when to reindex

Ids are `{driver}:{model}:{dimensions}`, for example `openai:text-embedding-3-small:1536`. The driver is the SDK provider's driver, not its config key.

- A change of provider driver or model name needs `php artisan rag:reindex --stale`.
- Two providers with the same driver and model name (two `openai-compatible` endpoints, or an `openai` provider repointed with `OPENAI_URL`) share an id. The mixed-model guard cannot tell them apart and `--stale` will not pick up the change. When you swap such an endpoint, rebuild with `php artisan rag:reindex --force`.
- A change of width on Postgres needs `php artisan rag:reindex --resize`. It refuses non-`vector` columns and `--collection`.
- The `fake` driver's id includes its width (`fake:deterministic-hash:64`), and its width comes from `rag.embedding.dimensions` (default 1536). Set 64 explicitly in tests that expect it.

### Code changes

None of these touch provider settings.

- `Ikram\Rag\Embedding\OpenAIEmbedder` is removed. Use the `sdk` driver, or register your own `Embedder` with `Rag::extend()` from a service provider, before the embedder is first used.
- The `Retriever` contract changed: `retrieve(string $query, ?int $limit = null, array $filters = [])`. It was `int $limit = 5`. A custom `Retriever` implementation must update its signature or PHP fails when it loads.
- `RagManager`'s constructor is now `(Container $container, EmbedderRegistry $embedders)`, and it resolves the retriever and ingestor lazily. This only matters if you construct it by hand.
- `Ingestor::confirmWith()` returns a configured clone instead of changing the shared instance. Use its return value.

### Behaviour changes

- `retrieval.default_limit` is now honoured: pass no limit and it is used. A limit below 1 throws `InvalidArgumentException`. Blank queries return an empty result without calling the provider.
- Filter keys must match `/^[A-Za-z0-9_]+$/`.
- The mixed-model check is scoped to the collection and filters you search, and ignores rows without a vector.
- Vector widths are checked against the embedder's width. On ingest a mismatch throws before anything is written, and on retrieval before the vector search runs.
- Token-usage reporting from embedding calls is removed. The SDK reports 0 for cached and faked calls, so the number would mislead.
- Eval runs record a `config` object (`k`, chunking, `min_score`, reranking, embedding id). `--baseline` warns when the settings differ from the baseline's. A baseline saved by 0.2 is accepted with a note that it predates config recording, and does not raise a false "different embedding model" warning.
- The floors in `rag.eval.thresholds` can be overridden with `RAG_EVAL_MIN_PASS_RATE`, `RAG_EVAL_MIN_RECALL_AT_K` and `RAG_EVAL_MIN_MRR`. Empty values are ignored.

### New in 0.3

`Rag::tool()` (a cited search tool for SDK agents), optional reranking, and `rag:reindex --stale` / `--resize`. New config keys: `provider`, `send_dimensions`, `timeout` and `cache` under `embedding`, and `retrieval.rerank.*`. `rag.embedding.api_key` is removed, and `rag.embedding.base_url` is deprecated. See the README.
