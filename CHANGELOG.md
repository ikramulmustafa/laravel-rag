# Changelog

All notable changes to `laravel-rag` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the major version is `0`, the public API may change in a minor release. Any
such change will be listed here under **Changed** with an upgrade note.

## [Unreleased]

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

[Unreleased]: https://github.com/ikramulmustafa/laravel-rag/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/ikramulmustafa/laravel-rag/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ikramulmustafa/laravel-rag/releases/tag/v0.1.0
