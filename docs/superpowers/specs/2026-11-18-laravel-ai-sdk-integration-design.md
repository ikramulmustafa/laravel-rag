# laravel-rag v0.3.0: built on the official Laravel AI SDK

Status: draft for approval, revised after builder review · 2026-11-18 · branch `feat/laravel-ai-sdk`

## 1. Intent

Laravel shipped a first-party AI SDK (`laravel/ai`, v1.4.0, 14.7M installs). Its built-in RAG piece, `SimilaritySearch`, is thin. It has no chunking, no ingestion, no similarity scores, no citations and no evals. It throws on MySQL and SQLite, and on Laravel 12 it works on Postgres only.

`ikramulmustafa/laravel-rag` already does the parts the SDK lacks. This release rebuilds the package on top of the SDK, so the positioning is simple: **the RAG layer for the official Laravel AI SDK. Ingestion, cited retrieval on any database, and an eval harness that fails CI. Small, correct, measurable.**

Why now:
- The only visible competitor, `moneo/laravel-rag` (40 stars, 25 installs), is tied to Prism 0.100.x and hasn't had a human commit since March 2026. Its only tagged release does not install, and its "nightly evals" never run an eval.
- None of the few packages built on `laravel/ai` combines cited retrieval with a CI eval gate, and each has fewer than 200 installs.
- This package is the author's most linkable portfolio asset, and it only stays relevant if it fits the SDK Laravel developers now default to.
- It has 5 installs, so breaking changes are cheap now and expensive later.

## 2. Goals

1. Embeddings go through the SDK, so any of the SDK's 11 embedding providers works with no hardcoded OpenAI.
2. The SDK agent gets a retrieval tool, `Rag::tool()`, that returns scored citations as JSON.
3. Optional reranking through the SDK, with scores kept.
4. Fix the correctness bugs listed in §6.3 before building on the code.
5. Existing pgvector or JSON data keeps working after `migrate`, with no reindex, as long as the provider, model and dimensions are unchanged.
6. Every README claim is backed by a test or CI job.

## 3. Non-goals (v0.3.0)

These are explicitly deferred to v0.4 or later:
- Hybrid/BM25 search and RRF.
- An MCP server via `laravel/mcp`.
- "Always-retrieve" agent middleware.
- Answer-level / LLM-judge evals. These are where the author's Anthropic credits fit, since Anthropic has no embeddings API.
- `halfvec` for vectors wider than 2000 dimensions on pgvector.
- MySQL 9's native `VECTOR` type.
- Laravel's built-in vector query methods (`whereVectorSimilarTo` etc.).
- Filament UI, streaming, and per-chunk metadata such as headings and offsets.
- Dogfooding on the portfolio site. That needs a framework upgrade to 12.62 or later plus PHP 8.3 on prod, so it is a separate task.

## 4. Anti-goals

These would make the release a failure even if it works:
- **Shallow breadth.** A long feature list where features are stubs is the competitor's trap. Every shipped feature has tests.
- **README claims not proven in CI.** No badge or number goes in the README unless CI produces it.
- **Paid services in the test path.** The test suite and the PR CI need no API key and no paid service.
- **Silently corrupting or orphaning existing vectors.** An embedding model, dimension or provider mismatch must raise an error, never return results quietly.
- **Leaking internals to the LLM provider.** Tool output never contains exception messages, HTTP bodies or stack traces.

## 5. Approaches considered

**SDK dependency**
- **Hard requirement (CHOSEN by the author).** One code path. The SDK becomes the embedder and `OpenAIEmbedder` is removed. The floor is PHP 8.3 and Laravel 12.62/13.15.
- Optional bridge: keeps PHP 8.2 support but needs two code paths and extra CI jobs. Rejected; with 5 installs, holding back for compatibility isn't worth it.

**Release shape**
- **One release, v0.3.0 (CHOSEN by the author).** Bug-fix commits come first, then the SDK work, in one PR, with one upgrade note.
- v0.2.1 followed by v0.3.0: two upgrade notes for no real benefit to users.

**Storage (author's call delegated; decided by Claude)**
- **Keep the package's own storage layer (CHOSEN).** pgvector raw SQL with HNSW, and a JSON column plus in-PHP cosine on MySQL/SQLite. Working on every database is the differentiator against SDK `SimilaritySearch`.
- Laravel's built-in vector methods: they throw on MySQL/SQLite, and on Laravel 12 they are Postgres-only. Rejected.
- Hybrid (built-in methods on Postgres/MariaDB, own path elsewhere): two paths for little gain. Rejected.

**Agent integration (decided by Claude)**
- **Own `Tool` class (CHOSEN).** Gives control over scores, the citation format, safe errors, the output cap and the filter allow-list.
- Wrap SDK `SimilaritySearch(using: closure)`: loses scores and the output cap, has a generic description, and adds the SDK's prose wrapper. Rejected.
- Agent middleware that injects context into every prompt: complementary rather than an alternative. Deferred to v0.4.

## 6. Design

### 6.1 Platform

- `composer.json` require:
  - `php ^8.3`
  - `laravel/ai ^1.4`. 1.4 fixes the batch-embedding order (#1172) and lets agents recover from tool failures (#1191).
  - `illuminate/{console,database,http,support} ^12.62|^13.15`. These floors match what `laravel/ai` effectively requires via `illuminate/json-schema`.
  - `symfony/finder ^7.0|^8.0`
  - `illuminate/validation` at the same range as the other illuminate packages. The tool's `Request::validate()` needs it, and neither `laravel/ai` nor the package currently requires it.
- CI test matrix:
  - Laravel 12 × PHP 8.3, 8.4
  - Laravel 13 × PHP 8.3, 8.4
  - The standalone-verifier job moves to PHP 8.3.
- The pgvector eval job keeps using the `fake` driver, but it must change:
  - Its "run was recorded" step asserts on `fake:deterministic-hash` and must be updated to the new id `fake:deterministic-hash:64`.
  - It gains a legacy-compat step: insert rows with the old suffix-less id, then assert that retrieval and `--stale` treat them as current (§6.3, "Legacy ids").
- `.gitattributes`: add `/docs export-ignore`.

### 6.2 Embedding through the SDK

**New `Ikram\Rag\Embedding\SdkEmbedder implements Embedder`.** It is the default driver, `sdk`.

Config, `rag.embedding`:

| key | env | default | notes |
|---|---|---|---|
| `driver` | `RAG_EMBEDDING_DRIVER` | `sdk` | `sdk`, `fake`, or a name registered with `Rag::extend()` |
| `provider` | `RAG_EMBEDDING_PROVIDER` | `null` | Name of a provider in `config/ai.php`. `null` means `ai.default_for_embeddings`. **Must be a single string.** An array (SDK failover) is rejected at resolve time with a clear `InvalidArgumentException`, because failover would store vectors from another model under the wrong id. |
| `model` | `RAG_EMBEDDING_MODEL` | `null` | `null` means the provider's `defaultEmbeddingsModel()`, resolved once at construction so `modelId()` is concrete. |
| `dimensions` | `RAG_EMBEDDING_DIMENSIONS` | `1536` | The expected vector width. Every returned vector is checked against it, and it sets the pgvector column width. |
| `send_dimensions` | `RAG_EMBEDDING_SEND_DIMENSIONS` | `true` | `true` sends `->dimensions($d)`, which guards against the SDK's per-provider default (e.g. 3-large silently truncated to 1536). `false` is "native" mode: it sends `->dimensions(0)`, which the Ollama and openai-compatible gateways drop from the request, while the width is still validated against `dimensions`. Use it for models that reject a `dimensions` parameter (Ollama models, many OpenAI-compatible servers). The SDK's `openai` gateway always sends a width, so `text-embedding-ada-002` (which rejects one) is reachable only through `openai-compatible` with native mode. UPGRADING says so. |
| `batch_size` | `RAG_EMBEDDING_BATCH_SIZE` | `96` | The SDK does not split batches, so the package does. |
| `timeout` | `RAG_EMBEDDING_TIMEOUT` | `30` | |
| `cache` | `RAG_EMBEDDING_CACHE` | `false` | Maps to `->cache()`. |

Removed keys: `api_key` and `base_url`. Credentials now live in `config/ai.php`, and OpenAI-compatible endpoints use the SDK's `openai-compatible` provider.

**Each batch:**
- The call is `Embeddings::for($batch)->dimensions($d)->timeout($t)->when($cache, fn ($p) => $p->cache())->generate($provider, $model)`.
- Then validate, throwing `EmbeddingFailed` with a **safe message** (no response body) on any failure:
  1. `array_values($response->embeddings)`. This is cheap defence for third-party or fake gateways; the SDK's own OpenAI and Azure gateways already return a list.
  2. The count equals the batch count.
  3. Every vector has exactly `$d` floats.
- No check on `meta->model`: every SDK gateway fills it from the *requested* model, so it can never detect substitution. The real protections are the width check and rejecting failover lists.
- SDK exceptions are wrapped in `EmbeddingFailed`, with the original kept as `previous`.

**Other methods:**
- `modelId()` returns `"{driver}:{model}:{dimensions}"`, e.g. `openai:text-embedding-3-small:1536`.
  - `{driver}` is the SDK provider's *driver* (`Provider::driver()`), not its config key. Renaming a key in `config/ai.php` or using a key other than `openai` therefore doesn't invalidate a corpus, and legacy `openai:<model>` ids line up.
  - Accepted trade-off: two config keys with the same driver and model name but different endpoints would share an id. That is rare, and documented.
  - Encoding the dimensions fixes the case where two corpora at different widths look like the same model.
- `dimensions()` returns the configured value. It is now actually called (see 6.3 #3).
- `estimateTokens()`: kept at 4 characters per token, because the SDK has no token estimator.
- Token-usage reporting is **dropped** from this release. The SDK reports 0 for cache hits and fakes, so a usage number would be misleading; revisit with v0.4 observability.

**Drivers:**
- `OpenAIEmbedder` is deleted.
- **Deprecated alias:** driver `openai` maps to `sdk` with provider `openai`. Published configs from 0.2 still say `driver => 'openai'`, and `mergeConfigFrom` is shallow, so they would otherwise break on upgrade. The alias logs a single deprecation warning per process; removal is planned for 1.0. Removed 0.2 keys (`api_key`, `base_url`) are ignored, with a deprecation warning when `base_url` is not the OpenAI default, because those users must move to `openai-compatible`.
- Code reads every config key with an explicit default (`config('rag.embedding.timeout', 30)` etc.), so a published 0.2 config that lacks the new keys still works.
- `FakeEmbedder` stays, so users' CI runs need no API key. Its id becomes `fake:deterministic-hash:{dimensions}`.
- The `match` in `RagServiceProvider` becomes a small driver registry: `Rag::extend(string $driver, Closure $factory)`. Built-ins are `sdk`, `fake` and the `openai` alias, and an unknown driver throws, listing the registered ones.

### 6.3 Correctness fixes (land first, as separate commits)

Numbers refer to the 2026-11-18 package audit. Each row says enough to work from without it.

| # | Problem | Fix |
|---|---|---|
| 1 | Documents and chunks can be written to different connections | `Document` and `Chunk` resolve their connection from `config('rag.connection')` (via a `getConnectionName()` override). `Ingestor`, `VectorRetriever`, `ReindexCommand` and `EvalRunRecorder` all use that connection, and the ingest transaction covers the Document save. |
| 2 | The migration ignores `rag.connection` | The migrations implement `getConnection()` returning `config('rag.connection')` and use `Schema::connection(...)`; the driver is detected on that connection. |
| 3 | `dimensions` is never sent or checked | Sent by `SdkEmbedder`. The `Ingestor` and `VectorRetriever` check every vector's width against `Embedder::dimensions()` and throw a clear error on a mismatch, before any SQL. |
| 5 | The fake driver width defaults to 1536, not 64 | The fake driver reads `rag.embedding.dimensions` like everything else; the README states this. The tests/CI set 64 explicitly. |
| 6 | `retrieval.default_limit` is ignored | `$limit` becomes `?int`, and null means the config value, in RagManager, the Facade, the Retriever contract and the tool. |
| 7 | Reindex can't handle DB-ingested content and blanks the hash before ingesting | A new migration adds `rag_documents.content` (longText, nullable), and the Ingestor stores the source text. Reindex uses the stored content, and falls back to re-reading the file for legacy rows where it is null. Ingest gets a `force` flag instead of blanking the hash. Documents are processed in id-ordered chunks, not a cursor over a table being written. A failure is reported per document and doesn't abort the run; the exit code is non-zero if any document failed. New option `--stale` reindexes only documents with chunks whose `embedding_model` differs from the current `modelId()`. This is the path after switching provider or model. |
| 8 | Content that becomes empty leaves stale chunks | Delete the chunks and update the hash, then return skipped. |
| 9 | A skipped ingest returns `documentId: 0` | Return the existing id. |
| 10 | The Ingestor trusts the embedder's vector count | Validate the count (and width, see #3) before the transaction. |
| 11 | `confirmWith()` mutates the singleton | It returns a configured clone. The behaviour change is noted in UPGRADING. |
| 14 | The mixed-model guard is global and runs on every retrieve | Scope it to the rows matching the query's collection and filters. Replace `SELECT DISTINCT` with one cheap indexed existence query (`… WHERE <scope> AND embedding_model NOT IN (<current id>, <legacy form>) LIMIT 1`). **No memoization:** the retriever is a singleton that lives as long as Octane or queue workers, and a cached "OK" would hide a corpus that becomes mixed mid-life. The in-PHP path also checks that each stored vector's width equals the query width and throws on a mismatch, instead of padding with zeros. |
| 17 | Filter keys are interpolated into the JSON path with no allow-list | Keys are validated against `/^[A-Za-z0-9_]+$/` in `applyFilters` (throws `InvalidArgumentException`). The tool adds its own allow-list on top (6.4). |
| 27 | Eval runs don't record k or the config | A new `rag_eval_runs.config` JSON column stores k, chunk size/overlap, min_score, the rerank settings and the embedder id. The same `config` object goes into `EvalReport::jsonSerialize()` and is read back by `baselineFromArray()`, because `--baseline` reads a `--save` JSON file, not the table. The comparison warns when the configs differ. A pre-0.3 baseline file with no `config` is accepted with a single "baseline predates config recording" note. Its legacy id is compared under the "Legacy ids" rule below, so it does **not** raise a false "different embedding model" warning. |
| 36–41 | Doc drift | Fix the README badges and numbers and the docblocks; regenerate the README eval numbers from the current CI configuration. |

Out of scope, and left alone: #4 beyond the >2000-width guard below, #12, #13, #15, #16, #18–#20 (except reranking, in 6.5), #25, #28–#35.

**Changing the vector width on pgvector: `rag:reindex --resize`** (CHOSEN by the author). On Postgres the column is a fixed `vector(N)`, so switching to a provider of a different width needs a resize. Behaviour:
- **When it applies.**
  - Postgres only. On JSON storage `--resize` is a no-op with a note, because widths aren't fixed there; it then behaves as `--stale`.
  - It compares the current column width (from `pg_attribute.atttypmod` / `format_type`) with `rag.embedding.dimensions`. When they are equal it says so and does a normal `--stale` reindex.
- **Confirmation.**
  - It asks for confirmation unless `--force` is given. The prompt states the corpus size and that search returns no results until re-embedding finishes.
- **Steps, in one transaction:**
  - Drop the HNSW index.
  - `ALTER TABLE rag_chunks ALTER COLUMN embedding TYPE vector(M) USING NULL`, which clears every vector.
  - Recreate the HNSW index, **only if M ≤ 2000**, pgvector's HNSW limit.
- **Width above 2000.**
  - The command leaves the column unindexed (an exact scan) and prints a warning recommending a smaller `dimensions` (e.g. 3-large at 1536).
  - The original migration gets the same guard, so a fresh install at 3072 no longer fails on `migrate` (audit #4, now partly addressed). `halfvec` stays out of scope.
- **Re-embedding.**
  - Every document is re-embedded with the stored `content`, or the file for legacy rows.
  - Rows the retriever finds with a NULL embedding are excluded, so a partly finished resize returns fewer results rather than errors.
- **Failures.**
  - Failures are reported per document, the exit code is non-zero, and running it again resumes. Only documents with NULL embeddings or a non-current id are processed.
- **Tests.** The pgvector CI job tests a 64 → 32 resize end to end: migrate at 64, ingest, resize to 32, reindex, retrieve, and check the index exists.

**Legacy ids: matched on read, never rewritten.** Ids are not rewritten in place: a full-table `UPDATE` of an indexed column on pgvector re-inserts every row into the HNSW index inside the migration transaction, which means a long lock and index bloat on a large corpus.
- Instead, one helper, `EmbeddingModelId::matches(string $stored, string $current): bool`, is true when the two are equal, **or** when `$stored` has no `:<int>` suffix and equals `$current` with its suffix removed.
- Every comparison uses it: the mixed-model guard, `rag:reindex --stale`, the baseline model check and eval history.
- Width safety still holds, because stored-vector widths are checked on every path (pgvector rejects a mismatch in SQL; the in-PHP path checks per row, #14).
- Result: a user who keeps driver `openai`, model `text-embedding-3-small` and 1536 dimensions needs no reindex and no data migration.
- Assumption: OpenAI returns identical vectors when `dimensions=1536` is sent explicitly for 3-small. This is unverified, so UPGRADING states it and recommends running `rag:eval` after upgrading.
- Newly written rows always carry the full id.

**Migrations and `RAG_CONNECTION`.** Under 0.2, users who set `RAG_CONNECTION` had their tables created on the *default* connection (#2).
- The new 0.3 migrations (`rag_documents.content`, `rag_eval_runs.config`) run on the rag connection.
- If the tables are missing there but exist on the default connection, the migration stops with an explicit message explaining how to move the tables or unset `RAG_CONNECTION`. It never writes to the wrong database.

### 6.4 Agent tool: `Rag::tool()`

The class is `Ikram\Rag\Ai\SearchKnowledgeBase implements Laravel\Ai\Contracts\Tool`. It is built by:

```php
Rag::tool(
    ?string $collection = null,       // fixed; the model cannot change it
    array $filters = [],              // fixed filters, always applied, override model-supplied ones
    array $allowedFilters = [],       // metadata keys the model MAY filter on (strings)
    ?int $limit = null,               // default and max results; null = config default_limit
    ?string $name = null,             // default 'search_knowledge_base'
    ?string $description = null,      // default describes cited search over the knowledge base
    int $maxChars = 8000,             // output budget
): SearchKnowledgeBase
```

Usage in an agent:
```php
public function tools(): iterable { return [Rag::tool(collection: 'docs', allowedFilters: ['product'])]; }
```

**`schema()`:**
- `query`: string, required.
- `limit`: integer, 1..max.
- One optional string property per `allowedFilters` key.
- Nothing else exists in the schema. Strictness differs by provider: OpenAI and xAI enforce `additionalProperties:false`, Anthropic and Ollama don't set it, and Gemini strips it. So the guard is in code: `handle()` reads only `query`, `limit` and the allow-listed keys from the `Request` and ignores everything else.

**Tool name:** the class has a public `name()` method returning the configured name. The SDK finds it via `is_callable`, and otherwise falls back to the class basename. Without the method, both the `$name` option and `ToolCall('…','search_knowledge_base',…)` would not match.

**`handle(Request)`:**
- `validate()`: query required, 1–1000 characters; limit 1..max.
- Retrieve with the fixed collection and filters merged over the allow-listed model filters.
- Return compact JSON:
  ```json
  {"query":"…","count":2,"citations":[{"id":12,"source":"docs/refunds.md","score":0.8123,"rerank_score":0.91,"text":"…"}],"truncated":false}
  ```
  - `rerank_score` appears only when reranking is on.
  - With no results: `{"query":"…","count":0,"citations":[],"note":"No relevant passages found."}`.
- **Output budget.** If the JSON would exceed `maxChars`, drop the lowest-ranked citations first. If a single citation still exceeds it, trim its text at a word boundary with `…`. Set `truncated:true`.
- **Errors.**
  - Any package exception, or `Throwable` from retrieval, is caught and passed to `report()`.
  - The tool then returns `{"error":"The knowledge base search failed. Answer without it or ask the user to retry."}`.
  - The exception's message is never returned to the model, because SDK 1.4 forwards tool failure text to the provider.
  - `ValidationException` is rethrown, since the SDK turns it into a helpful message for the model.
- Citation `id` is the chunk row id. It is stable within a conversation, not across reindexes; this is documented.

### 6.5 Optional reranking

Config `rag.retrieval.rerank`:

| key | default |
|---|---|
| `enabled` (`RAG_RERANK`) | `false` |
| `provider` | `null` (`ai.default_for_reranking`, single string only) |
| `model` | `null` |
| `candidates` | `3` (fetch limit × 3) |

How it works:
- `RerankingRetriever implements Retriever` decorates `VectorRetriever`. The container wraps the retriever when the feature is enabled.
- It fetches `limit × candidates` (with `min_score` applied to the vector scores first), then calls `Reranking::of(texts)->limit($limit)->rerank($query, $provider, $model)` and maps each `RankedDocument->index` back to its Citation.
- `Citation` gains `?float $rerankScore` (in the JSON when non-null). `score` stays the vector score, and the order follows the rerank.
- `RetrievalResult::topScore()` becomes the **maximum vector score** among the citations, instead of `citations[0]->score`. That keeps `mean_top_score` meaning "best similarity", so turning reranking on doesn't trip its baseline tolerance. Without reranking, the result is identical.
- If the rerank call fails, it falls back to vector order truncated to `limit`, logs a warning, and **does not** throw. Retrieval should still work when an optional enhancement fails.
- `rag:eval` uses whatever retriever is bound, so reranked quality is measurable. Rerank settings are recorded in the eval-run config.

### 6.6 Tests

- The existing suite keeps passing (with the fake driver, 64 dimensions set explicitly).
- **`SdkEmbedder`:**
  - Uses `Embeddings::fake(closure)` that returns deterministic vectors, with `preventStrayEmbeddings()`.
  - Asserts:
  - dimensions sent when `send_dimensions` is true, and 0 sent in native mode
  - batch splitting and `array_values`
  - count and width mismatches each raise `EmbeddingFailed` with a body-free message
  - failover arrays rejected
  - the `modelId` format uses the driver, not the config key
  - the `openai` alias, plus a published 0.2 config with missing keys
  - the `EmbeddingModelId::matches` legacy rule
- **Tool:**
  - Called directly with `handle(new Request([...]))`: schema shape, allow-list enforcement, fixed collection not overridable, output budget and truncation, empty result, safe error JSON.
  - End to end: a test agent with `TestAgent::fake([new ToolCall('c1','search_knowledge_base',['query'=>'refund window']), 'final'])` asserts the real tool ran against fixture data.
- **Reranking:** `Reranking::fake([...])` for the order mapping, score attachment, the fallback on failure, and that it is disabled by default.
- **Fixes:** a regression test for each row in 6.3, including:
  - a two-connection test (sqlite file + `:memory:`)
  - reindex of DB-ingested content and the `--stale` option
  - legacy suffix-less ids being accepted
  - a stored-width mismatch on the in-PHP path throwing
  - the migration refusing to run when `RAG_CONNECTION` points away from the existing tables
- **pgvector CI job:** see §6.1.

### 6.7 Real-model eval job (CI, free)

- A new workflow job, `eval-real`, runs on `schedule` (weekly) and `workflow_dispatch` only, never on PRs.
- It starts Ollama in the runner, pulls `nomic-embed-text`, and runs `rag:eval --ci` over the fixture corpus with driver `sdk` and provider `ollama`.
  - Settings: `RAG_EMBEDDING_DIMENSIONS=768`, `RAG_EMBEDDING_SEND_DIMENSIONS=false` (native mode, so no `dimensions` parameter is sent).
  - It uses a SQLite file database, so it doesn't depend on the pgvector column width.
- It proves real semantic retrieval with no paid key.
- The thresholds for this job are set from its first green run and recorded in the README with the date.

### 6.8 Docs and release

- **README:** new install section (`composer require laravel/ai ikramulmustafa/laravel-rag`, publish the `ai` config, set the provider); a "Use it from an agent" section; reranking; "Why not SimilaritySearch?" (a factual comparison table); correct badges (Laravel 12 | 13, PHP 8.3+).
- **New `UPGRADING.md`, 0.2 → 0.3:**
  - PHP 8.3 and Laravel 12.62+/13.15+.
  - Install `laravel/ai`.
  - Driver `openai` becomes `sdk` + `RAG_EMBEDDING_PROVIDER=openai`.
  - The removed config keys.
  - Run `migrate`. It adds two columns; existing ids are left alone and still match.
  - Run `rag:eval` to confirm.
  - `text-embedding-ada-002` users must switch to the `openai-compatible` driver with native mode.
  - Users of the old `base_url` setting move to `openai-compatible`, which changes their id driver and needs a reindex.
  - A change of provider or model at the same width needs `rag:reindex --stale`. A change of width on Postgres needs `rag:reindex --resize`.
  - The `confirmWith` clone behaviour.
- **CHANGELOG:** under [Unreleased], then recorded as [0.3.0] by the author's release process (squash-merge, then "Record 0.3.0", green CI, `gh release create v0.3.0`).
- **Portfolio follow-ups**, done separately: update the case-study page and blog post with the v0.3 facts after release.

## 7. Left to the builder

- Exact class layout inside `src/Ai/` and the registry implementation.
- How deterministic fake vectors are built in tests. Reusing `FakeEmbedder`'s hashing inside the `Embeddings::fake` closure is suggested.
- The exact wording of the tool's description and of the README prose (plain, factual, no hype).
- Commit granularity, as long as the bug-fix commits come before the SDK commits.
- Whether the facade docblocks are generated or hand-written.

## Amendments during implementation

Where the build differs from the text above, these rulings win.

- R1: The "once per process" deprecation warning is tracked on the `EmbedderRegistry` singleton, not a static, so each app instance warns once.
- R2: `eval-real` lives in its own workflow file, `.github/workflows/eval-real.yml`, triggered by `schedule`, `workflow_dispatch` and `pull_request` filtered to that file, so `ci.yml` triggers are unchanged.
- R3: `EmbeddingModelId::matches` follows 6.3 literally (a stored id with a `:<int>` suffix never matches by suffix removal), a limit below 1 throws, and blank detection covers Unicode whitespace.
- R4: The deprecated `openai` alias fails closed with `InvalidArgumentException` when `base_url` is set to anything but the OpenAI default; 6.2 said it would only warn.
- R5: CI runs only on push to `main` and on pull requests, so the controller pushes the branch and opens a draft PR to validate the pgvector job.
- R6: `--resize` clears vectors with `DROP COLUMN` plus `ADD COLUMN`, not `ALTER COLUMN TYPE ... USING NULL`, to avoid a full table rewrite under an exclusive lock.
- R7: Rerank `candidates` must be 1..10 and the pool sent to the reranker is capped at 100.
- R8: The tool shortens its echoed query to stay within `maxChars`; superseded by R8b.
- R8b: The echoed query is capped at 200 characters first, then citations are dropped or trimmed, then the query is shortened further if still needed.
