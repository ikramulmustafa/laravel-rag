# laravel-rag v0.3.0: Laravel AI SDK Integration — Implementation Plan

**Goal:** Rebuild `ikramulmustafa/laravel-rag` on the official `laravel/ai` SDK: SDK embeddings, an agent tool with scored citations, optional reranking, pgvector resize, after fixing the correctness bugs the audit found.

**Architecture:** The package keeps its own storage layer (pgvector raw SQL + JSON/in-PHP cosine). Embedding moves behind an `EmbedderRegistry` whose default `sdk` driver calls `Laravel\Ai\Embeddings`. Retrieval is exposed to SDK agents through a `Laravel\Ai\Contracts\Tool` implementation, and reranking is a `Retriever` decorator. All bug-fix tasks (1–6) land before SDK tasks (7–12).

**Tech Stack:** PHP 8.3+, Laravel 12.62+/13.15+, laravel/ai ^1.4, Pest 3/4, Orchestra Testbench 10/11, PHPStan level 6, Pint (repo `pint.json`), Postgres 16 + pgvector (CI only).

**Spec:** `docs/superpowers/specs/2026-11-18-laravel-ai-sdk-integration-design.md` — read it first; this plan cites its sections (§).

## Global Constraints

- Repo: `d:/laragon/www/laravel-rag`, branch `feat/laravel-ai-sdk`. Never commit to `main`.
- `composer.json` require: `php ^8.3`, `laravel/ai ^1.4`, `illuminate/{console,database,http,support,validation} ^12.62|^13.15`, `symfony/finder ^7.0|^8.0`.
- Every PHP file starts with `declare(strict_types=1);`; every class in `src/` is `final` (except contracts).
- Every task ends green on: `vendor/bin/pest`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pint --test`, `php tests/verify-standalone.php`. (This repo **is** pint-clean; running `vendor/bin/pint` on it is safe.)
- `tests/verify-standalone.php` must never load a class that references `Laravel\Ai\*` or `Illuminate\*` at runtime. New SDK-dependent classes live under `src/Ai/` and `src/Embedding/SdkEmbedder.php` and are not touched by it.
- Tests use no network: `Embeddings::fake(...)`/`Reranking::fake(...)` with `preventStray…()`, or the `fake` driver.
- Tool output, exception messages that may reach an LLM, and `EmbeddingFailed` messages never contain HTTP bodies, stack traces or raw exception messages from providers.
- Commit messages: a plain sentence in the author's style (e.g. "Store the source text so reindex works for database content"). **No `Co-Authored-By`, no "Generated with" lines, no `feat:` prefixes.** Stage explicit paths only.
- New migration files: `database/migrations/2026_11_19_000000_add_content_and_eval_config.php`. Do not edit the meaning of the 2026_01_01 migration except the two changes named in Tasks 2 and 9.
- Postgres-only behaviour is tested in the `pgsql` Pest group (`->group('pgsql')`), skipped unless `DB_CONNECTION=pgsql`; the CI pgvector job runs it. No local Postgres exists — never point tests at the user's pgAdmin server (it is the production n8n database).

## Review Focus

1. **Blank or whitespace-only query** (`Rag::retrieve('  ')`, tool `query: " "`) → empty `RetrievalResult`, no embedding call, no exception. (Task 3, Task 11)
2. **A published 0.2 `config/rag.php`** (`driver => 'openai'`, no `provider/timeout/cache/send_dimensions/rerank` keys) → app boots, alias resolves to the SDK embedder, one deprecation warning. (Task 7)
3. **A collection holding both legacy (`openai:text-embedding-3-small`) and new (`…:1536`) ids** → searchable, no `MixedEmbeddingModels`. (Task 3)
4. **Tool output with multibyte text right at the budget** → valid UTF-8 JSON, `mb_strlen(json) <= maxChars`, `truncated:true`. (Task 11)
5. **Reranker returning fewer items than asked, or an index out of range** → no crash; unknown indices ignored; result never exceeds `limit`. (Task 10)

---

### Task 1: Platform floor and SDK dependency

**Files:**
- Modify: `composer.json`, `.github/workflows/ci.yml` (matrix + standalone PHP), `tests/TestCase.php`, `README.md` (badges line only), `phpstan.neon` (only if needed for laravel/ai stubs)

**Interfaces:**
- Produces: `laravel/ai` installed; `Laravel\Ai\AiServiceProvider` registered in tests; config `ai.providers.openai` present in tests with key `'test-key'`.

- [ ] **Step 1:** Update `composer.json` require per Global Constraints; require-dev unchanged.
- [ ] **Step 2:** Run `composer update -W --no-interaction`. Expected: resolves; `composer show laravel/ai` ≥ 1.4.0.
- [ ] **Step 3:** In `tests/TestCase.php::getPackageProviders` return `[AiServiceProvider::class, RagServiceProvider::class]`; in `defineEnvironment` set `ai.default_for_embeddings = 'openai'`, `ai.providers.openai.key = 'test-key'`.
- [ ] **Step 4:** CI: matrix `laravel: ['12','13']`, `php: ['8.3','8.4']`, drop the 8.2 exclude; standalone job `php-version: '8.3'`. README badge: Laravel 12 | 13, PHP 8.3+.
- [ ] **Step 5:** Run the four Global-Constraint checks. Expected: 70 tests pass, phpstan OK, pint OK, standalone OK.
- [ ] **Step 6:** Commit "Require PHP 8.3 and the Laravel AI SDK".

### Task 2: One database connection for everything

Spec §6.3 #1, #2.

**Files:**
- Modify: `src/Documents/Document.php`, `src/Documents/Chunk.php`, `database/migrations/2026_01_01_000000_create_rag_tables.php`, `src/Console/ReindexCommand.php` (connection only)
- Test: `tests/Feature/ConnectionTest.php`

**Interfaces:**
- Produces: `Document::getConnectionName(): ?string` and `Chunk::getConnectionName(): ?string` returning `config('rag.connection') ?? parent::getConnectionName()`. Migration has `public function getConnection(): ?string` returning `config('rag.connection')` and uses `Schema::connection($this->getConnection())` / `DB::connection($this->getConnection())` throughout (driver detection included).

- [ ] **Step 1: Write the failing test** `it stores documents and chunks on the rag connection when it differs from the default`: configure a second sqlite connection `rag` (a temp file path), set `rag.connection = 'rag'`, run migrations, `Rag::ingest('a.md', 'alpha beta gamma')`; assert `DB::connection('rag')->table('rag_documents')->count() === 1`, `…rag_chunks… >= 1`, and `Schema::connection('testing')->hasTable('rag_documents') === false`; then `Rag::retrieve('alpha')` returns 1+ citations.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/ConnectionTest.php`. Expected: FAIL (tables on default connection).
- [ ] **Step 3:** Implement the Interfaces above. The migration's `down()` uses the same connection.
- [ ] **Step 4:** Run the test and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit "Keep documents, chunks and migrations on the configured connection".

### Task 3: Model ids, the mixed-model guard and vector width checks

Spec §6.2 (`modelId`), §6.3 #3, #5, #6, #10, #14, #17, "Legacy ids". Review Focus 1, 3.

**Files:**
- Create: `src/Embedding/EmbeddingModelId.php`, `src/Exceptions/DimensionMismatch.php`
- Modify: `src/Embedding/FakeEmbedder.php`, `src/Retrieval/VectorRetriever.php`, `src/Contracts/Retriever.php`, `src/RagManager.php`, `src/Facades/Rag.php`, `src/RagServiceProvider.php`, `src/Documents/Ingestor.php` (count/width validation + skip check only), `tests/verify-standalone.php` (its Retriever implementation signature + new checks), `.github/workflows/ci.yml` (recorded-model assertion → `fake:deterministic-hash:64`)
- Test: `tests/Unit/EmbeddingModelIdTest.php`, `tests/Feature/RetrievalTest.php`, `tests/Feature/IngestionTest.php`

**Interfaces:**
- Produces:
  - `EmbeddingModelId::make(string $driver, string $model, int $dimensions): string` → `"{$driver}:{$model}:{$dimensions}"`
  - `EmbeddingModelId::legacyForm(string $id): ?string` → id without a trailing `:<digits>`, or null if there is none
  - `EmbeddingModelId::matches(string $stored, string $current): bool` → equal, or `$stored === legacyForm($current)`
  - `FakeEmbedder::modelId()` → `'fake:deterministic-hash:'.$this->dimensions`
  - `Retriever::retrieve(string $query, ?int $limit = null, array $filters = []): RetrievalResult`
  - `VectorRetriever::__construct(Embedder $embedder, Connection $connection, float $minScore = 0.0, int $defaultLimit = 5)`
  - `final class DimensionMismatch extends RuntimeException`
  - `InvalidArgumentException` for filter keys not matching `/^[A-Za-z0-9_]+$/`

- [ ] **Step 1: Write failing unit tests** in `EmbeddingModelIdTest`: `make('openai','text-embedding-3-small',1536) === 'openai:text-embedding-3-small:1536'`; `legacyForm('openai:text-embedding-3-small:1536') === 'openai:text-embedding-3-small'`; `legacyForm('ollama:nomic-embed-text') === null`; `legacyForm('fake:deterministic-hash:64') === 'fake:deterministic-hash'`; `matches('openai:x', 'openai:x:1536') === true`; `matches('openai:x:768', 'openai:x:1536') === false`; `matches('openai:x:1536', 'openai:x') === false`.
- [ ] **Step 2: Write failing feature tests**:
  - `it treats legacy suffix-less ids as the current model` — ingest, then `DB::table('rag_chunks')->update(['embedding_model' => 'fake:deterministic-hash'])` for half the rows; `Rag::retrieve('…')` returns results (Review Focus 3).
  - `it scopes the mixed-model guard to the searched collection` — collection `a` with current id, collection `b` rows set to `other:model:64`; retrieve with `['collection' => 'a']` succeeds; without filter throws `MixedEmbeddingModels`.
  - `it re-checks the corpus on every search` — retrieve once OK, then poison a row, retrieve again → throws (no memoization).
  - `it rejects a stored vector of another width on the in-PHP path` — overwrite one row's `embedding` JSON with 32 floats → `DimensionMismatch`.
  - `it returns an empty result for a blank query without embedding` — bind a spy `Embedder` that throws if `embed` is called; `Rag::retrieve("   ")->isEmpty() === true` (Review Focus 1).
  - `it uses retrieval.default_limit when no limit is given` — config 2, ingest 5 docs, `count(Rag::retrieve('x')) <= 2`.
  - `it rejects filter keys that are not plain identifiers` — `Rag::retrieve('x', null, ['a->b' => 1])` throws `InvalidArgumentException`.
  - `it refuses an embedder that returns the wrong number of vectors` / `…the wrong width` — bind stub embedders; `Rag::ingest` throws `EmbeddingFailed`; no rows written.
- [ ] **Step 3:** Run them. Expected: FAIL.
- [ ] **Step 4:** Implement. Guard = one query on `rag_chunks as c` joined to `rag_documents as d`, filters applied, `whereNotIn('c.embedding_model', array_filter([$current, EmbeddingModelId::legacyForm($current)]))`, `->exists()`; only on failure fetch up to 5 distinct offending ids for the message. Width check: query vector length must equal `$embedder->dimensions()` (`DimensionMismatch`); in-PHP path compares each stored vector's length to the query's. Pgvector path adds `->whereNotNull('c.embedding')`. Ingestor validates count and width before opening the transaction and throws `EmbeddingFailed`. Ingestor's skip check uses `EmbeddingModelId::matches`. Update the standalone verifier's Retriever stub to the new signature.
- [ ] **Step 5:** Run all four checks. Expected: PASS.
- [ ] **Step 6:** Commit "Match legacy model ids, scope the mixed-model guard and check vector widths".

### Task 4: Ingestor stores content and stops leaking state

Spec §6.3 #7 (storage half), #8, #9, #11; "Migrations and RAG_CONNECTION".

**Files:**
- Create: `database/migrations/2026_11_19_000000_add_content_and_eval_config.php`
- Modify: `src/Documents/Ingestor.php`, `src/Documents/Document.php` (`@property ?string $content`), `src/Console/IngestCommand.php` (use returned clone)
- Test: `tests/Feature/IngestionTest.php`, `tests/Feature/MigrationGuardTest.php`

**Interfaces:**
- Produces:
  - Migration (rag connection, via `getConnection()`): `rag_documents.content` longText nullable; `rag_eval_runs.config` json nullable. Before altering: if `! Schema::connection($rag)->hasTable('rag_documents')` **and** `config('rag.connection') !== null` **and** the default connection has `rag_documents` → throw `RuntimeException` whose message says the tables live on the default connection and to move them or unset `RAG_CONNECTION`.
  - `Ingestor::ingest(string $source, string $content, string $collection = 'default', array $metadata = [], bool $force = false): IngestResult`
  - `Ingestor::confirmWith(callable $callback): self` returns a **clone**; the original is unchanged.

- [ ] **Step 1: Write failing tests**:
  - `it stores the source text on the document` — `Document::first()->content === $text`.
  - `it backfills content when an unchanged legacy document is skipped` — null the column, re-ingest same text → skipped, content now set, no new embedding call (spy).
  - `it re-embeds unchanged content when forced` — second ingest with `force: true` → `skipped === false`.
  - `it removes stale chunks when content becomes empty` — ingest text, then ingest `''` → chunk count 0, hash updated, `skipped === true`, `documentId === $first->documentId`.
  - `it returns the existing document id when skipping` — second identical ingest → `documentId` equals the first.
  - `it does not keep a confirm callback on the shared ingestor` — `app(Ingestor::class)->confirmWith(fn () => false)`; then `Rag::ingest` of a large text (threshold set to 1) succeeds.
  - `MigrationGuardTest`: tables migrated on `testing`, then set `rag.connection = 'other'` (fresh sqlite file) and run the new migration's `up()` → throws `RuntimeException` mentioning `RAG_CONNECTION`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement per Interfaces.
- [ ] **Step 4:** Run all four checks. Expected: PASS.
- [ ] **Step 5:** Commit "Store the source text and stop the ingest confirmation leaking between calls".

### Task 5: Reindex that works for database content, with `--stale`

Spec §6.3 #7 (command half).

**Files:**
- Modify: `src/Console/ReindexCommand.php`
- Test: `tests/Feature/CommandsTest.php` (reindex cases)

**Interfaces:**
- Consumes: `Ingestor::ingest(..., force: true)`, `EmbeddingModelId::matches`, `Document::$content`.
- Produces: signature `rag:reindex {--collection=} {--stale} {--force}` (`--resize` added in Task 9). Iterates `Document` ids in ascending chunks of 100 (`chunkById`). Text = `$document->content` ?? readable file at `source` ?? failure ("source text not stored and the file is not readable"). Per-document `Throwable` → recorded as failure (`class_basename` + message, console only), continue. `--stale` selects documents having a chunk whose `embedding_model` is not in `[current, legacyForm(current)]` or whose `embedding` is null. Exit `FAILURE` if any failed, listing them.

- [ ] **Step 1: Write failing tests**: `it reindexes content that was ingested from the database` (ingest via `Rag::ingest('db:1', …)`, switch fake dimensions 64→32 by rebinding the embedder, `rag:reindex --force` exit 0, all chunks carry the new id); `it reindexes only stale documents with --stale` (two docs, poison one doc's ids → only that doc's `updated_at`/chunks change; assert via a counting spy embedder); `it keeps going and fails at the end when one document cannot be reindexed` (doc with null content and unreadable source + one good doc → exit 1, good doc reindexed, output names the bad source); `it does not blank the hash when reindexing fails` (embedder throws → document `content_hash` unchanged).
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement per Interfaces.
- [ ] **Step 4:** Run all four checks. Expected: PASS.
- [ ] **Step 5:** Commit "Reindex from stored text, add --stale, and report failures per document".

### Task 6: Record the eval configuration

Spec §6.3 #27.

**Files:**
- Create: `src/Eval/EvalConfig.php`
- Modify: `src/Eval/EvalReport.php`, `src/Eval/EvalRunRecorder.php`, `src/Console/EvalCommand.php`
- Test: `tests/Feature/EvalHistoryTest.php`, `tests/Feature/EvalSuiteTest.php`

**Interfaces:**
- Produces:
  - `EvalConfig::current(Embedder $embedder, int $k): array<string,mixed>` → keys exactly: `k`, `chunk_size`, `chunk_overlap`, `min_score`, `rerank` (`['enabled'=>bool,'provider'=>?string,'model'=>?string,'candidates'=>int]`, reading `rag.retrieval.rerank.*` with defaults false/null/null/3), `embedding_model`.
  - `EvalReport` gains trailing ctor param `public readonly array $config = []`, method `withConfig(array $config): self`; `jsonSerialize()` adds `'config' => $this->config`; `baselineFromArray()` reads `config` (array, default `[]`).
  - `EvalRunRecorder::record()` writes `config` as JSON (null when empty).
  - `EvalCommand`: model comparison uses `EmbeddingModelId::matches($baseline->embeddingModel, $report->embeddingModel)`; if baseline `config === []` print one note "Baseline predates config recording; settings were not compared."; else compare configs minus `embedding_model` and warn listing differing keys.

- [ ] **Step 1: Write failing tests**: recorded row's `config` JSON has `k`/`chunk_size`/`embedding_model`; `--save` file contains `config`; a baseline file with legacy id `fake:deterministic-hash` and no `config` → output contains the "predates" note and **not** "different embedding model"; a baseline whose config has `k: 9` → output warns `k`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all four checks. Expected: PASS.
- [ ] **Step 5:** Commit "Record the configuration each eval ran with".

### Task 7: SDK embedder and the driver registry

Spec §6.2. Review Focus 2.

**Files:**
- Create: `src/Embedding/SdkEmbedder.php`, `src/Embedding/EmbedderRegistry.php`
- Delete: `src/Embedding/OpenAIEmbedder.php` (and any test referencing it)
- Modify: `config/rag.php` (embedding block per spec table: driver default `sdk`, `provider`, `model` null, `dimensions` 1536, `send_dimensions` true, `batch_size` 96, `timeout` 30, `cache` false; remove `api_key`, `base_url`), `src/RagServiceProvider.php`, `src/RagManager.php`, `src/Facades/Rag.php`
- Test: `tests/Unit/SdkEmbedderTest.php`, `tests/Feature/EmbedderRegistryTest.php`

**Interfaces:**
- Produces:
  - `SdkEmbedder::__construct(string $provider, string $driver, string $model, int $dimensions, bool $sendDimensions = true, int $batchSize = 96, int $timeout = 30, bool $cache = false)`
  - `SdkEmbedder::fromConfig(array $config, ?string $defaultProvider): self` — provider = `$config['provider'] ?? $defaultProvider`; non-string (array) → `InvalidArgumentException` ("must name a single provider; failover would store vectors under the wrong model id"); resolves `Ai::embeddingProvider($provider)` for `driver()` (guard with `instanceof Laravel\Ai\Providers\Provider`) and, when model is null, `defaultEmbeddingsModel()`.
  - `embed()`: `[]` → `[]` with no call; batches of `batchSize`; `Embeddings::for($batch)->dimensions($sendDimensions ? $dimensions : 0)->timeout($timeout)->when($cache, fn ($p) => $p->cache())->generate($provider, $model)`; `array_values`; count and width validation; any `Throwable` from the SDK → `EmbeddingFailed("Embedding request to provider [{$provider}] failed (".class_basename($e).').', previous: $e)`.
  - `modelId()` = `EmbeddingModelId::make($driver, $model, $dimensions)`; `estimateTokens()` = `(int) ceil(total mb_strlen / 4)`.
  - `EmbedderRegistry`: `extend(string $driver, Closure $factory): void`, `make(string $driver): Embedder`, `drivers(): list<string>`. Built-ins: `sdk`, `fake` (`new FakeEmbedder((int) config('rag.embedding.dimensions', 1536))`), `openai` (alias: logs `logger()->warning('[laravel-rag] The "openai" embedding driver is deprecated; use "sdk" with RAG_EMBEDDING_PROVIDER=openai.')` once per process, then `SdkEmbedder::fromConfig([...$config, 'provider' => $config['provider'] ?? 'openai'], …)`; additionally warns once if `base_url` is set and not `https://api.openai.com/v1`). Unknown driver → `InvalidArgumentException` listing `drivers()`.
  - Every `rag.embedding.*` read supplies an explicit default.
  - `RagManager::extend(string $driver, Closure $factory): void` delegates to the registry; facade docblock lists `retrieve`, `ingest`, `extend`, `retriever`, `ingestor` (and `tool` in Task 11).

- [ ] **Step 1: Write failing tests** (use `Embeddings::fake(fn (EmbeddingsPrompt $p) => (new FakeEmbedder($p->dimensions ?: 64))->embed($p->inputs))->preventStrayEmbeddings()`; configure `rag.embedding.dimensions = 64`):
  - sends `dimensions` 64 (`Embeddings::assertGenerated(fn ($p) => $p->dimensions === 64)`); native mode sends 0.
  - splits 5 inputs at `batch_size` 2 into 3 requests (count via closure counter).
  - wrong count / wrong width from the fake → `EmbeddingFailed`, message contains neither the vector data nor "http".
  - SDK exception → `EmbeddingFailed` with `getPrevious()` set.
  - `provider` array → `InvalidArgumentException`.
  - `modelId()` uses the driver: with `ai.providers.main = ['driver'=>'openai','key'=>'x']` and `provider = 'main'`, id starts `openai:`.
  - `embed([])` makes no request (`Embeddings::assertNothingGenerated()`).
  - Registry: unknown driver message lists `sdk, fake, openai`; `Rag::extend('custom', fn () => new FakeEmbedder(64))` + `driver=custom` resolves it.
  - **Review Focus 2**: replace the whole `rag.embedding` config with the 0.2 shape (`driver=openai, model=text-embedding-3-small, dimensions=64, api_key=…, base_url=https://api.openai.com/v1, batch_size=96`) → `app(Embedder::class)` is `SdkEmbedder`, `modelId() === 'openai:text-embedding-3-small:64'`, exactly one warning logged (`Log::spy()`), and an ingest + retrieve round-trip works under the fake.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement; delete `OpenAIEmbedder`.
- [ ] **Step 4:** Run all four checks. Expected: PASS.
- [ ] **Step 5:** Commit "Embed through the Laravel AI SDK and add a driver registry".

### Task 8: pgsql test group in CI

Spec §6.1 (pgvector job), §6.6.

**Files:**
- Modify: `tests/TestCase.php` (when `env('DB_CONNECTION') === 'pgsql'`, use a `pgsql` connection from `DB_*` env instead of sqlite), `tests/Pest.php` or a helper `skipUnlessPgsql()`, `phpunit.xml` (exclude group `pgsql` by default), `.github/workflows/ci.yml` (pgvector job: add `vendor/bin/pest --group=pgsql` before the ingest step; keep the eval steps; recorded-model assertion already updated in Task 3; add the legacy-compat step: `psql`-free PHP one-liner that updates all `embedding_model` rows to `fake:deterministic-hash` then runs `testbench rag:eval --suite=tests/Fixtures/suite.json --k=2 --ci --no-record` → must exit 0)
- Test: `tests/Feature/PgvectorTest.php` (`->group('pgsql')`)

**Interfaces:**
- Produces: `skipUnlessPgsql(): void` Pest helper used by Task 9.

- [ ] **Step 1:** Write `PgvectorTest`: `it retrieves through the pgvector operator`, `it excludes chunks whose embedding is null`, `it scopes the guard per collection on pgsql` (mirror of Task 3 tests on the pgvector path).
- [ ] **Step 2:** Run `vendor/bin/pest` locally. Expected: PASS with the `pgsql` group excluded (shown as skipped/not run).
- [ ] **Step 3:** Commit "Run the Postgres-only tests against pgvector in CI".
- [ ] **Step 4:** Push the branch (`git push -u origin feat/laravel-ai-sdk`) and watch CI (`gh run watch`). Expected: all jobs green, including `pest --group=pgsql`.

### Task 9: `rag:reindex --resize` and the >2000-dimension guard

Spec §6.3 "Changing the vector width on pgvector".

**Files:**
- Create: `src/Storage/PgvectorColumn.php`
- Modify: `src/Console/ReindexCommand.php`, `database/migrations/2026_01_01_000000_create_rag_tables.php` (create the HNSW index only when dimensions ≤ 2000)
- Test: `tests/Feature/PgvectorTest.php` (group `pgsql`), `tests/Feature/CommandsTest.php` (sqlite no-op), `tests/Unit/PgvectorColumnTest.php`

**Interfaces:**
- Produces:
  - `PgvectorColumn::MAX_INDEXED_DIMENSIONS = 2000`
  - `PgvectorColumn::parseWidth(string $formatType): ?int` — `'vector(64)'` → 64, `'vector'` → null
  - `PgvectorColumn::currentWidth(Connection $c): ?int` — `SELECT format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = 'rag_chunks'::regclass AND attname = 'embedding'`
  - `PgvectorColumn::resize(Connection $c, int $dimensions): bool` — in one transaction: `DROP INDEX IF EXISTS rag_chunks_embedding_idx`; `ALTER TABLE rag_chunks ALTER COLUMN embedding TYPE vector({$dimensions}) USING NULL`; create the HNSW index iff `$dimensions <= 2000`; returns whether indexed.
  - `rag:reindex --resize`: non-pgsql → note "Vector width is not fixed on this database; running --stale." then behaves as `--stale`; `--resize` with `--collection` → error exit; equal widths → note, then `--stale`; otherwise confirmation (unless `--force`) stating document count and that search returns nothing until done; then resize; warn when not indexed (>2000); then reindex all documents whose embedding is null or id is stale.

- [ ] **Step 1: Write failing tests**: unit `parseWidth`; sqlite `--resize` prints the note and reindexes stale docs; pgsql group: `it resizes the vector column and reindexes` (migrate at 64, ingest fixture corpus, set dims 32 + rebind fake, `rag:reindex --resize --force` exit 0, `currentWidth === 32`, index `rag_chunks_embedding_idx` exists in `pg_indexes`, retrieval returns results); `it leaves wide columns unindexed` (resize to 2001 → returns false, no index, warning printed).
- [ ] **Step 2:** Run locally (sqlite parts FAIL), push, CI pgsql parts FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the four checks locally; push; `gh run watch` → all green.
- [ ] **Step 5:** Commit "Add rag:reindex --resize for changing the pgvector width".

### Task 10: Optional reranking

Spec §6.5. Review Focus 5.

**Files:**
- Create: `src/Retrieval/RerankingRetriever.php`
- Modify: `src/Retrieval/Citation.php`, `src/Retrieval/RetrievalResult.php` (`topScore`), `config/rag.php` (`retrieval.rerank` block: `enabled` env `RAG_RERANK` false, `provider` null, `model` null, `candidates` 3), `src/RagServiceProvider.php`
- Test: `tests/Feature/RerankingTest.php`, `tests/Unit/RetrievalResultTest.php`

**Interfaces:**
- Produces:
  - `Citation` trailing ctor param `public readonly ?float $rerankScore = null`; `withRerankScore(float $score): self`; JSON adds `rerank_score` (rounded 4) only when non-null.
  - `RetrievalResult::topScore()` = max citation `score` (0.0 when empty).
  - `RerankingRetriever::__construct(Retriever $inner, ?string $provider, ?string $model, int $candidates, int $defaultLimit, LoggerInterface $logger)` implements `Retriever`. `retrieve`: `$limit ??= $defaultLimit`; inner with `$limit * $candidates`; `< 2` citations → return as-is (≤ limit); else `Reranking::of($texts)->limit($limit)->rerank($query, $provider, $model)`; map each `RankedDocument->index` to its citation (ignore out-of-range indices), attach `rerankScore`, cap at `$limit`; any `Throwable` → `$logger->warning(...)` and return the first `$limit` inner citations.
  - Container wraps `Retriever` when `rag.retrieval.rerank.enabled` is true; `provider` array → `InvalidArgumentException`.

- [ ] **Step 1: Write failing tests** with `Reranking::fake([...])`: order follows ranked indices and `rerank_score` present; disabled by default (`Reranking::assertNothingReranked()`); reranker throws → vector order, no exception, warning logged; ranked results with index 99 and only 1 entry → no crash, ≤ limit (Review Focus 5); `topScore()` returns the max score when citations are out of score order.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all four checks. Expected: PASS.
- [ ] **Step 5:** Commit "Add optional reranking through the SDK".

### Task 11: The agent tool, `Rag::tool()`

Spec §6.4. Review Focus 1, 4.

**Files:**
- Create: `src/Ai/SearchKnowledgeBase.php`
- Modify: `src/RagManager.php`, `src/Facades/Rag.php`
- Test: `tests/Feature/SearchKnowledgeBaseTest.php`, `tests/Fixtures/KnowledgeAgent.php` (test agent: `implements Agent, HasTools`, `use Promptable`, `tools()` returns `[Rag::tool()]`)

**Interfaces:**
- Consumes: `Retriever::retrieve(string, ?int, array)`, `Citation::jsonSerialize()` fields.
- Produces:
  - `SearchKnowledgeBase::__construct(Retriever $retriever, ?string $collection = null, array $filters = [], array $allowedFilters = [], int $limit = 5, string $name = 'search_knowledge_base', ?string $description = null, int $maxChars = 8000)`; constructor throws `InvalidArgumentException` when an allowed key fails `/^[A-Za-z0-9_]+$/`, when `'collection'` is allowed while `$collection` is fixed, when `$limit < 1`, or when `$maxChars < 500`.
  - `name(): string`, `description(): string` (default text: "Search the knowledge base for passages relevant to a question. Returns JSON with cited passages, each with an id, source and similarity score. Cite passages by their source."), `schema(JsonSchema $schema): array` (`query` string min 1 max 1000 required; `limit` integer min 1 max `$limit`; one string per allowed key), `handle(Request $request): string`.
  - `RagManager::tool(?string $collection = null, array $filters = [], array $allowedFilters = [], ?int $limit = null, ?string $name = null, ?string $description = null, int $maxChars = 8000): SearchKnowledgeBase` (`$limit ?? config('rag.retrieval.default_limit', 5)`).
  - Output shapes exactly as spec §6.4: success `{"query","count","citations":[{"id","source","score","rerank_score"?,"text"}],"truncated"}`; empty `{"query","count":0,"citations":[],"note":"No relevant passages found."}`; error `{"error":"The knowledge base search failed. Answer without it or ask the user to retry."}`. Encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE`.
  - Budget: measured with `mb_strlen` on the encoded JSON; drop lowest-ranked citations until it fits or one remains; then shrink the last citation's text at a word boundary (`mb_substr` + last space) appending `…`, repeating until it fits; `truncated: true` whenever anything was dropped or trimmed.
  - Errors: `ValidationException` rethrown; any other `Throwable` → `report($e)` then the error JSON.

- [ ] **Step 1: Write failing tests**:
  - schema has `query`, `limit`, allowed keys only (serialize via `new Illuminate\JsonSchema\JsonSchemaTypeFactory`, check property names).
  - `handle(new Request(['query' => 'refund window']))` on the fixture corpus → JSON decodes, `count >= 1`, first citation has `id`, `source`, `score`.
  - model-supplied `collection` / non-allowed key / unknown key are ignored: fixed `collection: 'a'` with request `['query'=>…, 'collection'=>'b', 'secret'=>'x']` → only collection `a` sources.
  - allowed filter applied: `allowedFilters: ['product']`, request `product: 'pro'` → only matching metadata.
  - blank query `"   "` → `ValidationException` (rules `required|string|max:1000`; after validation, `trim($query) === ''` → `ValidationException::withMessages(['query' => 'The query must not be blank.'])`), and the retriever spy was never called (Review Focus 1).
  - empty corpus → `count 0` + note.
  - retriever throws `RuntimeException('secret DSN postgres://u:p@h')` → output equals the error JSON exactly; output does not contain `secret`.
  - budget: 10 long multibyte (`'é'`-heavy) passages, `maxChars 600` → `mb_strlen <= 600`, `json_decode` succeeds, `truncated === true` (Review Focus 4).
  - name: `Rag::tool(name: 'docs_search')->name() === 'docs_search'`.
  - end to end: `KnowledgeAgent::fake([new ToolCall('c1', 'search_knowledge_base', ['query' => 'refund window']), 'Refunds are allowed within 30 days.'])`; `(new KnowledgeAgent)->prompt('How long for refunds?')`; assert response text and that the tool ran (spy retriever recorded the query).
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all four checks. Expected: PASS.
- [ ] **Step 5:** Commit "Add Rag::tool(), a cited search tool for SDK agents".

### Task 12: Real-model eval job, docs and changelog

Spec §6.7, §6.8, §6.3 #36–41.

**Files:**
- Create: `UPGRADING.md`
- Modify: `.github/workflows/ci.yml` (new job `eval-real`: `on: schedule` weekly cron `0 6 * * 1` + `workflow_dispatch` — add these triggers at the workflow level and gate the job with `if: github.event_name != 'pull_request' && github.event_name != 'push'`; steps: setup PHP 8.3, `composer update`, install Ollama via `curl -fsSL https://ollama.com/install.sh | sh`, `ollama serve &`, wait for `http://127.0.0.1:11434`, `ollama pull nomic-embed-text`, env `RAG_EMBEDDING_DRIVER=sdk RAG_EMBEDDING_PROVIDER=ollama RAG_EMBEDDING_MODEL=nomic-embed-text RAG_EMBEDDING_DIMENSIONS=768 RAG_EMBEDDING_SEND_DIMENSIONS=false DB_CONNECTION=sqlite DB_DATABASE=$RUNNER_TEMP/rag.sqlite`, migrate, ingest fixtures, `rag:eval --suite=tests/Fixtures/suite.json --k=2` — **without** `--ci` until thresholds are set from the first green run), `README.md`, `CHANGELOG.md`, `src/Retrieval/Vector.php` (docblock #39), `.github/ISSUE_TEMPLATE/*` (v0.1.0 placeholder #41)
- Test: none new; docs claims verified by Step 3.

- [ ] **Step 1:** README: install (`composer require laravel/ai ikramulmustafa/laravel-rag`, `php artisan vendor:publish --tag=ai-config` — verify the tag name in `vendor/laravel/ai/src/AiServiceProvider.php`), provider config, "Use it from an agent" (the `tools()` example from spec §6.4), reranking, resize, "Why not SimilaritySearch?" table (rows: databases, scores, citations, chunking/ingestion, evals, provider choice for query embedding — facts from spec §1/§6), fake-driver width note (#5), regenerate the eval numbers section by running `vendor/bin/testbench rag:eval --suite=tests/Fixtures/suite.json --k=2 --json` locally (sqlite, fake 64, chunk 200/40) and pasting the real metrics.
- [ ] **Step 2:** `UPGRADING.md` with every bullet in spec §6.8; `CHANGELOG.md` `[Unreleased]` listing Added/Changed/Fixed/Removed/Deprecated.
- [ ] **Step 3:** Verify every code sample in README/UPGRADING against source (names, signatures, config keys, command options) — grep each identifier in `src/` and `config/`.
- [ ] **Step 4:** Push; `gh run watch` → all PR jobs green; then `gh workflow run ci.yml --ref feat/laravel-ai-sdk` and watch `eval-real`. Expected: completes and prints metrics; record them in README with the date and change the step to `--ci` with thresholds = observed − 0.05 (floored to 2 decimals), push again.
- [ ] **Step 5:** Commit "Document 0.3.0 and add a weekly real-model eval".
