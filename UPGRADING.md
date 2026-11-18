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
   composer require laravel/ai ikramulmustafa/laravel-rag:^0.3 -W
   php artisan vendor:publish --tag=ai-config   # only if config/ai.php does not exist yet
   php artisan migrate
   ```

   `-W` lets Composer update the framework in the same step: an app locked below Laravel 12.62 (or 13.15) cannot install 0.3 without it, and Composer stops with a conflict.

   `migrate` adds `rag_documents.content` (the source text, so reindexing no longer needs the file) and `rag_eval_runs.config`. If you set `RAG_CONNECTION` under 0.2, your tables were created on the default connection. The new migrations run on the RAG connection and, if the tables are missing there but exist on the default one, stop with a message about moving them or unsetting `RAG_CONNECTION`. They never write to the wrong database.

3. If you ever published `config/rag.php`, re-publish it now and re-apply your edits. Two edits need care first:

   - **Path B, a custom URL in the file.** If your published file sets `base_url` to anything other than `https://api.openai.com/v1`, as a literal or as the `env()` default, set `RAG_EMBEDDING_BASE_URL=<that url>` in `.env` before you re-publish. The re-published file reads only the env var, and that value is what stops the package from sending your documents to OpenAI until you finish Path B. You remove it in Path B step 4.
   - **A key in another variable.** If your published file read `api_key` from a variable other than `OPENAI_API_KEY`, that mapping is gone: 0.3 ignores `rag.embedding.api_key`. On Path A, put that key in `OPENAI_API_KEY` or in the `key` of the `openai` provider in `config/ai.php`. On Path B it goes to the `openai-compatible` provider (Path B step 1).

   Then:

   ```bash
   php artisan vendor:publish --tag=rag-config --force
   ```

   or copy the new blocks from the package's `config/rag.php` into yours. Every top-level block of a published file (`embedding`, `retrieval`, `eval`, and so on) replaces the package's block wholesale, so keys added in 0.3 do not exist in yours and their env vars are ignored: `RAG_EMBEDDING_PROVIDER`, `RAG_EMBEDDING_SEND_DIMENSIONS`, `RAG_EMBEDDING_TIMEOUT`, `RAG_EMBEDDING_CACHE`, `RAG_RERANK*` and `RAG_EVAL_MIN_*`. (`RAG_EMBEDDING_DRIVER`, `RAG_EMBEDDING_MODEL`, `RAG_EMBEDDING_DIMENSIONS` and `OPENAI_API_KEY` still work.) The package logs a warning once when it sees a config without the `provider` key, and the `sdk` driver refuses such a config outright.

4. Check:

   ```bash
   php artisan config:show rag.embedding
   ```

   The output must include a `provider` key. If it does not, the published config is still the old one. Stop and fix that before continuing. On Path B, `base_url` must also still show your custom endpoint at this point. If it shows null, set `RAG_EMBEDDING_BASE_URL` to it before anything runs (and `php artisan config:clear` if you cache config).

### Path A: you used OpenAI directly

You did not set `base_url` or `RAG_EMBEDDING_BASE_URL`.

1. Set all four explicitly, to what 0.2 used:

   ```env
   RAG_EMBEDDING_DRIVER=sdk
   RAG_EMBEDDING_PROVIDER=openai
   RAG_EMBEDDING_MODEL=text-embedding-3-small
   RAG_EMBEDDING_DIMENSIONS=1536
   ```

   `text-embedding-3-small` and `1536` were 0.2's defaults; if you set other values, keep yours. Ship these in the same deploy as the package upgrade, not after it: 0.2 ignores `RAG_EMBEDDING_PROVIDER`, so setting it early costs nothing, while setting it late leaves a window in which 0.3 embeds with `ai.default_for_embeddings`. Do not leave the provider and model unset: 0.3 then uses `ai.default_for_embeddings` and that provider's default embeddings model, which match 0.2 only with the SDK's stock `config/ai.php`. An app that already points `ai.default_for_embeddings` at another provider would send its documents there, and search would throw `MixedEmbeddingModels`. `RAG_EMBEDDING_DRIVER=openai` also still works, as a deprecated alias for `sdk` that defaults the provider to `openai`; it logs one deprecation warning and goes in 1.0.
2. Your key stays in `OPENAI_API_KEY`, now read by `config/ai.php` (unless your published config read it from another variable: see Step 0.3). The endpoint is now `ai.providers.openai.url`, which is `OPENAI_URL` (default `https://api.openai.com/v1`). If the app sets `OPENAI_URL` for other SDK or Prism use, embeddings follow it, and the embedding id does not change, so the mixed-model guard will not notice.
3. Keep the same model and `RAG_EMBEDDING_DIMENSIONS`. Rows written by 0.2 carry an id without the width (`openai:text-embedding-3-small`); 0.3 writes `openai:text-embedding-3-small:1536`, and the two match on read. No reindex and no data migration are needed.
4. Run the eval and compare with the baseline from Step 0:

   ```bash
   php artisan rag:eval --baseline=storage/rag-baseline-0.2.json
   ```

`text-embedding-ada-002` rejects a `dimensions` parameter, and the SDK's `openai` provider always sends one. It only works through an `openai-compatible` provider with `RAG_EMBEDDING_SEND_DIMENSIONS=false`. That changes the id's driver (`openai` to `openai-compatible`), so the whole corpus has to be re-embedded: follow Path B steps 1, 3, 5, 6 and 7, with `OPENAI_COMPATIBLE_URL=https://api.openai.com/v1` and your OpenAI key as `OPENAI_COMPATIBLE_API_KEY`. Your OpenAI key can stay in `OPENAI_API_KEY` as well; Path B step 2 is for gateway tokens. Native mode omits the parameter only on providers whose default width is 0 (`ollama`, `openai-compatible`), and only while that provider has no `models.embeddings.dimensions` set in `config/ai.php`: the SDK sends that value in place of 0.

### Path B: you used a custom endpoint

You set `rag.embedding.base_url` or `RAG_EMBEDDING_BASE_URL` (a gateway, a proxy, a local server).

0.3 no longer uses that value to send anything. While it is set to anything other than `https://api.openai.com/v1`, the `sdk` driver and the `openai` alias refuse to embed: the first embedding call throws `InvalidArgumentException` before any request is made, naming the host. Without that refusal the SDK would send your documents to api.openai.com. If Step 0's re-publish removed it, set it again first (see Step 0.3). (The `fake` driver and drivers you register with `Rag::extend()` are not checked.) The `sdk` driver also refuses a published config without a `provider` key, which is why Step 0 re-publishes it.

So search and ingestion fail from the moment 0.3 is installed until step 6 finishes: first on the refusal, then, after step 4, on `MixedEmbeddingModels`. Do it in a maintenance window, or use the `OPENAI_URL` alternative below, which needs no re-embed.

Do these in order, and do each check.

1. In `config/ai.php`, use the `openai-compatible` provider (the SDK's default file already has one) and set `OPENAI_COMPATIBLE_URL` to your endpoint and `OPENAI_COMPATIBLE_API_KEY` to your endpoint's token.
2. Move the token out of `OPENAI_API_KEY` (or out of whichever variable your published config read `api_key` from). Unset it, or set `OPENAI_API_KEY` to a real OpenAI key only if you also use OpenAI elsewhere in the app.
3. Set:

   ```env
   RAG_EMBEDDING_DRIVER=sdk
   RAG_EMBEDDING_PROVIDER=openai-compatible
   RAG_EMBEDDING_MODEL=<the model your endpoint serves>
   RAG_EMBEDDING_DIMENSIONS=<its width>
   ```

   `RAG_EMBEDDING_MODEL` is required: the `openai-compatible` provider has no default embeddings model and throws without one. 0.2 never sent a `dimensions` parameter, and 0.3 does by default; `RAG_EMBEDDING_SEND_DIMENSIONS=false` reproduces 0.2's request exactly, and is needed if your endpoint rejects the parameter.
4. Remove `base_url` from `config/rag.php` and `RAG_EMBEDDING_BASE_URL` from `.env`. Then run `php artisan config:clear` (or re-cache, if your deploy runs `config:cache`), and `php artisan queue:restart` and reload Octane if you run them, so no process keeps the old config.
5. Check `php artisan config:show rag.embedding`. It must show `provider` as your `openai-compatible` key and `base_url` as null or absent. Do not continue until it does.
6. Re-embed. The driver part of the embedding id changed (`openai` to `openai-compatible`), so every stored chunk now counts as another model's. Run, in this order:

   1. Postgres, and only if the width changed: `php artisan rag:reindex --resize --force`. It changes the column, clears every vector and re-embeds what it can. Do this first: the column only accepts the new width, so an ingest before it fails.
   2. Re-run your normal ingestion. It re-embeds each document it covers (it does not skip them as unchanged, because the stored chunks do not match the current id) and stores the document's text. This spends embedding calls; `rag:ingest` without `--force` asks for confirmation above `rag.ingestion.token_warning_threshold`, and `Rag::ingest()` never asks.
   3. `php artisan rag:reindex --stale --force` for anything left. It re-embeds every document that still has a chunk from another id (or none), from its stored text, falling back to reading `source` as a file path for a document that has no stored text.
   4. A failure on one document does not stop the run: the command lists each failed document as `collection/source`, exits non-zero, and those documents keep their old vectors (or, after a resize, have none and stay out of search). Fix the cause or re-ingest the document, then repeat step 3; it only touches documents still on another id.

   Until this finishes, search throws `MixedEmbeddingModels` on a mixed corpus (or, after `--resize`, returns fewer results).

   A 0.2 document whose text was never stored and whose source no longer exists (a deleted file, or a database row that is gone) cannot be re-embedded, and its old vectors keep every search on that collection throwing `MixedEmbeddingModels`. Delete it instead; its chunks are removed with it:

   ```php
   Ikram\Rag\Documents\Document::where('collection', $collection)->where('source', $source)->delete();
   ```

   Both values are in the command's failure list. Then repeat step 3.
7. Run `php artisan rag:eval` and compare with your baseline.

Optional alternative: if your endpoint is a proxy serving the same OpenAI model, you can use the SDK's `openai` provider pointed at it. That repoints every SDK `openai` call in the app (chat, agents, everything), not only embeddings. In this order:

1. Set `OPENAI_URL` to the proxy and `OPENAI_API_KEY` to the proxy's token, plus `RAG_EMBEDDING_PROVIDER=openai` and `RAG_EMBEDDING_DRIVER=sdk` (or keep `openai`, the deprecated alias). Leave `base_url` in place for now: while it is set, the refusal stops anything being sent, so nothing can reach api.openai.com before `OPENAI_URL` is in effect.
2. Then remove `base_url` from `config/rag.php` and `RAG_EMBEDDING_BASE_URL` from `.env`, clear the config cache and restart workers as in Path B step 4, and check that `php artisan config:show ai.providers.openai.url` shows the proxy URL.

The embedding id does not change (it stays `openai:…`), so no re-embed is needed; see the next section for what that means for the mixed-model guard.

### Embedding ids, and when to reindex

Ids are `{driver}:{model}:{dimensions}`, for example `openai:text-embedding-3-small:1536`. The driver is the SDK provider's driver, not its config key.

- A change of provider driver or model name needs `php artisan rag:reindex --stale`.
- Two providers with the same driver and model name (two `openai-compatible` endpoints, or an `openai` provider repointed with `OPENAI_URL`) share an id. The mixed-model guard cannot tell them apart and `--stale` will not pick up the change. When you swap such an endpoint, rebuild with `php artisan rag:reindex --force`.
- A change of width on Postgres needs `php artisan rag:reindex --resize`. It refuses non-`vector` columns and `--collection`.
- A change of width on SQLite or MySQL, with rows written by 0.2, needs a full `php artisan rag:reindex`. Their ids carry no width, so `--stale` does not see them, and search throws `DimensionMismatch` until they are rebuilt.
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
