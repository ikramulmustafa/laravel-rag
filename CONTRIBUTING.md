# Contributing

Thanks for considering a contribution.

## Getting set up

```bash
git clone https://github.com/ikramulmustafa/laravel-rag.git
cd laravel-rag
composer install
composer test
```

No API key, no Postgres and no network are needed to run the suite. It uses
SQLite in memory and the deterministic `FakeEmbedder`. If any of that stops being
true, that is a bug worth reporting on its own.

## Before opening a pull request

```bash
composer test      # pest
composer lint      # pint --test  (composer fix applies it)
composer analyse   # phpstan level 6
composer verify    # the dependency-free standalone verifier
```

All four run in CI, along with an eval job against real Postgres with pgvector.

PHPStan runs at level 6 with no baseline. Please keep it that way — if an error
looks like a false positive, say so in the pull request rather than suppressing it
quietly.

## If you change anything touching retrieval

Chunking, scoring, filtering, the embedder, the query path: include the
before-and-after `rag:eval` output in the pull request description.

```bash
# the same settings as the CI eval job
export RAG_EMBEDDING_DRIVER=fake RAG_EMBEDDING_DIMENSIONS=64 RAG_CHUNK_SIZE=200 RAG_CHUNK_OVERLAP=40

vendor/bin/testbench migrate --force
vendor/bin/testbench rag:ingest tests/Fixtures/corpus --force
vendor/bin/testbench rag:eval --suite=tests/Fixtures/suite.json --k=2 --save=before.json

# make your change, then:
vendor/bin/testbench rag:reindex --force
vendor/bin/testbench rag:eval --suite=tests/Fixtures/suite.json --k=2 --baseline=before.json
```

That is the standard this package asks of its users, so it holds itself to it. A
retrieval change with no eval output attached is difficult to review, because the
tests passing tells you almost nothing about whether retrieval got better.

Note the `rag:reindex` between the change and the second eval. Ingestion skips
documents whose content hash has not changed, so a chunking change does not
re-chunk anything until the hashes are cleared, and an eval run straight after
`rag:ingest` will score the old configuration.

## Scope

Two things are deliberately absent and are listed as limitations in the README:
**reranking** and **hybrid (BM25 + dense) search**. Both are real improvements
and both are out of scope for now. Please open an issue to discuss before
building either.

Four design decisions are also deliberate, and each exists because the
alternative fails silently:

1. Mixed embedding models throw rather than warn.
2. Ingestion asks before spending above a token threshold.
3. `RetrievalResult` has no API for context without citations.
4. `min_score` allows returning nothing.

If you think one of these is wrong, that is a conversation worth having — open an
issue and make the case. Pull requests that quietly remove one are unlikely to be
merged.

## Tests

New behaviour needs a test. Two things to know about the test embedder:

- `FakeEmbedder` hashes tokens into buckets, and at the 64 dimensions the suite
  uses there are collisions — `md5('refund')` and `md5('shipping')` share one. If
  you assert on ranking, use a realistic multi-word query, not a single word.
- The suite runs on SQLite, which takes the in-PHP cosine path. Changes to the
  pgvector query path are only exercised by the `eval` job in CI, so call them out
  in the pull request description.

## Reporting bugs

Open an issue with the version, the PHP and Laravel versions, and the smallest
reproduction you can manage. For a retrieval problem, the `rag:eval --json`
output is more useful than a description of what looked wrong.

## Security

Please do not open a public issue for a security problem. See
[SECURITY.md](SECURITY.md).
