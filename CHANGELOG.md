# Changelog

All notable changes to `laravel-rag` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the major version is `0`, the public API may change in a minor release. Any
such change will be listed here under **Changed** with an upgrade note.

## [Unreleased]

0.3.0 rebuilds the package on the official Laravel AI SDK. Read [UPGRADING.md](UPGRADING.md) before upgrading; it has separate paths for apps that used OpenAI directly and apps that set a custom `base_url`, and the second must be followed in order.

### Added

- Embeddings go through `laravel/ai`: the new default `sdk` driver, with `provider`, `send_dimensions`, `timeout` and `cache` under `rag.embedding`, and `Rag::extend()` to register other drivers.
- `Rag::tool()`, a cited search tool for SDK agents, with a fixed collection and filters, allow-listed model filters and an output budget.
- Optional reranking through the SDK (`rag.retrieval.rerank.*`); citations carry `rerank_score`.
- `rag:reindex --stale` (documents with chunks from another model, or without an embedding) and `--resize` (change the pgvector column width).
- `rag_documents.content` stores the source text, and `rag_eval_runs.config` records the settings of each eval run; `--baseline` warns when they differ.
- `Ingestor::ingest(force:)` re-embeds a document whose text is unchanged.
- `RAG_EVAL_MIN_PASS_RATE`, `RAG_EVAL_MIN_RECALL_AT_K` and `RAG_EVAL_MIN_MRR` override the `rag.eval.thresholds` floors (defaults unchanged: 0.90, 0.80, 0.60; empty values are ignored).
- A weekly real-model eval workflow (`eval-real.yml`) using Ollama and `nomic-embed-text`, gated with `--ci`.
- `UPGRADING.md`.

### Changed

- Requires PHP 8.3+ and Laravel 12.62+ / 13.15+, plus `laravel/ai` `^1.4`.
- Embedding ids are `{driver}:{model}:{dimensions}`. Ids written by 0.2 still match without a migration. The driver is the SDK provider's driver, not its config key, so two providers with the same driver and model name share an id.
- Breaking: the `Retriever` contract is now `retrieve(string $query, ?int $limit = null, array $filters = [])` (it was `int $limit = 5`). Custom implementations must update their signature.
- Breaking: `RagManager` takes `(Container $container, EmbedderRegistry $embedders)` and resolves the retriever and ingestor lazily.
- Breaking: `Ingestor::confirmWith()` returns a configured clone instead of mutating the shared instance.
- `retrieval.default_limit` is used when no limit is passed; a limit below 1 throws; blank queries return an empty result without an embedding call.
- `topScore()` is the highest vector score among the citations.
- The mixed-model check is scoped to the searched collection and filters.
- The `fake` driver's width comes from `rag.embedding.dimensions`.
- Filter keys are validated against `/^[A-Za-z0-9_]+$/`.
- README, badges, eval numbers and issue templates updated for 0.3.

### Fixed

- Documents and chunks, the migrations and the eval recorder now honour `rag.connection`; before, they could end up on different connections.
- Content that becomes empty on re-ingest removes its stale chunks, and a skipped ingest returns the existing document id instead of 0.
- Vector widths are checked on ingest and retrieval instead of being trusted.
- Reindex works for content ingested from a database and no longer blanks hashes before ingesting; a failure on one document no longer aborts the run.
- The migration no longer fails on a vector wider than pgvector's HNSW limit of 2000; it leaves the column unindexed.

### Deprecated

- The `openai` embedding driver name, an alias for `sdk` with provider `openai`. It is removed in 1.0.
- `rag.embedding.base_url` and `RAG_EMBEDDING_BASE_URL`. They are no longer used to send anything, and while set to anything other than `https://api.openai.com/v1` the embedder refuses to run, for the `sdk` driver and the `openai` alias (not `fake` or drivers added with `Rag::extend()`), so documents meant for a custom endpoint are not sent to OpenAI. Move to an `openai-compatible` provider in `config/ai.php`.

### Removed

- `OpenAIEmbedder`, and the `rag.embedding.api_key` key; credentials and endpoints live in `config/ai.php`.
- Token-usage reporting from embedding calls.
- Support for PHP 8.2.

## [0.2.0] - 2026-10-09

Laravel 13 support, and a chunker fix that changes chunk counts: run
`php artisan rag:reindex` after upgrading to drop the duplicate chunks from an
existing index.

### Added

- Laravel 13 support. `illuminate/*` now accepts `^12.0|^13.0` and `symfony/finder`
  accepts `^7.0|^8.0`. Laravel 13 requires PHP 8.3+; Laravel 12 still runs on PHP 8.2+.
  CI tests both majors.

### Fixed

- The chunker no longer emits a duplicate chunk at the end of a document. Once a
  chunk reached the end of the text, the loop stepped back by the overlap and
  emitted the tail again, so any document longer than the overlap ended in a chunk
  that repeated the previous one and took a retrieval slot. Re-run
  `php artisan rag:reindex` to drop the duplicates from an existing index.
- The CI eval job ingests the fixtures in 200-character chunks (seven chunks) and
  scores them at `k=2`; with the duplicates gone, one chunk per document left the
  hashed test embedder tying two documents. README example and CONTRIBUTING updated.

## [0.1.1] - 2026-08-25

Packaging metadata only. No functional change, no API change; upgrading from
0.1.0 requires nothing.

### Added

- A top-level `homepage` field in `composer.json`. Packagist renders this as the
  package Homepage link; `authors[].homepage` is a separate field and does not
  produce it, so the package page previously had no link to the project site.
- A Packagist version badge in the README, and an author line linking the site.

## [0.1.0] - 2026-08-25

First public release. Requires PHP 8.2+ and Laravel 12.

Laravel 11 is not supported: every 11.x release carries a Packagist security
advisory, so Composer refuses to install it under its default policy and there is
no version of it this package could claim support for and also test.

### Added

- `Rag::ingest()` and `Rag::retrieve()`, plus the `Ingestor` and `VectorRetriever`
  services behind them.
- `RecursiveChunker`, which splits on paragraph, then line, then sentence
  boundaries before falling back to a hard cut.
- `OpenAIEmbedder` for `text-embedding-3-*`, and `FakeEmbedder`, a deterministic
  network-free embedder that makes the test suite runnable with no API key.
- pgvector retrieval over an HNSW index on cosine distance, with an in-PHP cosine
  fallback so SQLite and MySQL work for tests and small corpora.
- `RetrievalResult`, which carries every chunk together with its source, position
  and score, and has no API for context without citations.
- `MixedEmbeddingModels`, thrown rather than warned when the corpus was embedded
  with a model other than the configured one. Distances between vectors from
  different models are real numbers with no meaning.
- A `min_score` floor that allows retrieval to return nothing, rather than padding
  a prompt to `k` with irrelevant context.
- Content hashing on ingest, so unchanged documents are not re-embedded, and a
  confirmation prompt above a configurable token estimate.
- `EvalSuite`, `EvalReport` and the `rag:eval` command, scoring `pass_rate`,
  `recall_at_k`, `mrr` and `mean_top_score` against a golden question set.
- `rag:eval --ci`, which exits non-zero when a metric falls below its configured
  floor or, with `--baseline`, regresses beyond its configured tolerance.
- `rag:eval --save` / `--baseline` for run-to-run comparison, and a warning when
  the baseline was recorded with a different embedding model.
- Eval history in `rag_eval_runs`: scores, failure reasons, the embedding model
  and the commit where one can be determined. `--no-record` skips the write.
- `rag:ingest` and `rag:reindex` commands.
- `tests/verify-standalone.php`, which covers chunking, the fake embedder, cosine
  similarity, pgvector literal formatting and the full eval scoring path on bare
  PHP with no Composer dependencies.

[Unreleased]: https://github.com/ikramulmustafa/laravel-rag/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/ikramulmustafa/laravel-rag/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/ikramulmustafa/laravel-rag/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ikramulmustafa/laravel-rag/releases/tag/v0.1.0
