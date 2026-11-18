# laravel-rag

**The RAG layer for the official Laravel AI SDK: ingestion, cited retrieval on any database, and an eval harness that fails your build when retrieval quality drops.**

[![CI](https://github.com/ikramulmustafa/laravel-rag/actions/workflows/ci.yml/badge.svg)](https://github.com/ikramulmustafa/laravel-rag/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/ikramulmustafa/laravel-rag.svg)](https://packagist.org/packages/ikramulmustafa/laravel-rag)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.3%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20.svg?logo=laravel&logoColor=white)](https://laravel.com/)

---

## Why this exists

A traditional pipeline that breaks throws an error. Monitoring catches it, someone gets paged, you fix it. The failure is loud.

An LLM pipeline that breaks produces output. Clean, well-formed, plausible output. It just happens to be wrong.

You change the chunk size to fit a longer document. You swap the embedding model because a cheaper one shipped. You add four hundred pages to the corpus and the index dilutes. In every case the system keeps answering, keeps sounding confident, and keeps citing sources — just the wrong ones. No exception is thrown. No alert fires. You find out when a customer does.

So this package treats retrieval quality as something you **test**, the same way you test anything else that can silently break. `php artisan rag:eval` runs a golden set of questions against your corpus, scores recall and MRR, and exits non-zero when either falls below a floor you set. Put it in CI and a retrieval regression fails the build instead of reaching production.

The RAG parts are deliberately unremarkable. The eval loop is the point.

---

## Contents

- [Install](#install) · [Quick start](#quick-start) · [Use it from an agent](#use-it-from-an-agent) · [Why not SimilaritySearch?](#why-not-similaritysearch) · [How it works](#how-it-works)
- [The eval harness](#the-eval-harness) · [Wiring it into CI](#wiring-it-into-ci)
- [Design decisions](#design-decisions) · [Commands](#commands) · [Configuration](#configuration)
- [Testing](#testing) · [Limitations](#limitations)
- [CONTRIBUTING](CONTRIBUTING.md) · [CHANGELOG](CHANGELOG.md) · [SECURITY](SECURITY.md)

---

## Install

```bash
composer require laravel/ai ikramulmustafa/laravel-rag
php artisan vendor:publish --tag=ai-config
php artisan vendor:publish --tag=rag-config
php artisan migrate
```

Requires **PHP 8.3+** with **Laravel 12.62+ or 13.15+**, and the official [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`), which makes the embedding calls. **PostgreSQL with [pgvector](https://github.com/pgvector/pgvector)** is for production use; the migration creates the extension for you if the role has permission. SQLite and MySQL also work, by computing cosine similarity in PHP.

Laravel 11 is not supported. Every 11.x release now carries a Packagist security advisory, so Composer blocks it under its default policy and the version cannot be installed or tested at all. Claiming support for something CI cannot verify seemed worse than saying so here.

Upgrading from 0.2? Read [UPGRADING.md](UPGRADING.md).

### Choose an embedding provider

Credentials and endpoints live in `config/ai.php`, which is the SDK's file. This package only says which provider and model to use:

```env
OPENAI_API_KEY=sk-...
RAG_EMBEDDING_PROVIDER=openai
RAG_EMBEDDING_MODEL=text-embedding-3-small
RAG_EMBEDDING_DIMENSIONS=1536
```

`RAG_EMBEDDING_PROVIDER` names a provider in `config/ai.php`. Left empty, it uses the SDK's `ai.default_for_embeddings`. It must be a single name: an SDK failover list is rejected, because a fallback provider would store another model's vectors under this model's id.

Any SDK provider that supports embeddings works. For a local model through Ollama, with no key and no cost:

```env
RAG_EMBEDDING_PROVIDER=ollama
RAG_EMBEDDING_MODEL=nomic-embed-text
RAG_EMBEDDING_DIMENSIONS=768
RAG_EMBEDDING_SEND_DIMENSIONS=false
```

`RAG_EMBEDDING_SEND_DIMENSIONS=false` is "native" mode: no `dimensions` parameter is sent, for models that reject one. The width is still checked on every vector. Native mode only omits the parameter on providers whose SDK default width is 0 (`ollama` and `openai-compatible`). The SDK's `openai` provider always sends a width, so `text-embedding-ada-002` only works through `openai-compatible` in native mode.

`RAG_EMBEDDING_DRIVER` is `sdk` by default. `fake` is a deterministic, network-free embedder for tests. To add your own, call `Rag::extend('name', fn ($app) => new MyEmbedder)` from a service provider, before the embedder is first used.

The `fake` driver builds vectors of `rag.embedding.dimensions` (default 1536), not a fixed 64. This repository's tests and CI set `RAG_EMBEDDING_DIMENSIONS=64` explicitly; a fake corpus ingested at one width cannot be searched at another.

---

## Quick start

```php
use Ikram\Rag\Facades\Rag;

Rag::ingest(
    source: 'policies/refunds.md',
    content: file_get_contents(storage_path('policies/refunds.md')),
    collection: 'policies',
);

$result = Rag::retrieve('How long do customers have to request a refund?');

$result->toPrompt();   // numbered context block, ready for a prompt
$result->sources();    // ['policies/refunds.md']
$result->topScore();   // similarity of the best match
```

Or from the command line:

```bash
php artisan rag:ingest storage/policies --collection=policies
php artisan rag:eval
```

### Answering with citations

`retrieve()` never hands back bare context. Every result carries the chunk, the source, the position and the score, so the answer is traceable and a groundedness check has something to verify against.

```php
$result = Rag::retrieve($question, limit: 5);

if ($result->isEmpty()) {
    return 'I do not have information on that.';   // a valid answer
}

$answer = $llm->complete(<<<PROMPT
    Answer using only the context below. If the context does not contain the
    answer, say so. Cite sources by their bracket number.

    {$result->toPrompt()}

    Question: {$question}
    PROMPT);

return ['answer' => $answer, 'citations' => $result->citations];
```

---

## Use it from an agent

`Rag::tool()` returns a cited search tool for an agent built on the Laravel AI SDK:

```php
use Ikram\Rag\Facades\Rag;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

final class SupportAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Answer from the knowledge base and cite the sources you used.';
    }

    public function tools(): iterable
    {
        return [Rag::tool(collection: 'docs', allowedFilters: ['product'])];
    }
}
```

Options, all optional:

| argument | meaning |
|---|---|
| `collection` | Fixed. The model cannot change it. |
| `filters` | Fixed metadata filters, always applied; they override anything the model supplies. |
| `allowedFilters` | Metadata keys the model may filter on, each exposed as an optional string parameter. |
| `limit` | Default and maximum results. `null` uses `rag.retrieval.default_limit`. |
| `name` | Tool name, default `search_knowledge_base`. Must match `/^[A-Za-z0-9_-]{1,64}$/`. |
| `description` | What the model is told the tool does. |
| `maxChars` | Output budget, default 8000, minimum 500. |

The tool takes `query` and `limit` from the model, plus any allowed filter keys, and ignores everything else it is sent. `query` and `limit` cannot be used as filter keys, and a collection cannot be both fixed and allowed as a filter.

It returns compact JSON the model can quote from:

```json
{"query":"refund window","count":1,"citations":[{"id":12,"source":"policies/refunds.md","score":0.8123,"text":"..."}],"truncated":false}
```

- `id` is the chunk's row id. It is stable within a conversation, not across reindexes.
- `score` is the vector similarity. With reranking on, each citation also carries `rerank_score`.
- When the output would exceed `maxChars`, the echoed query is capped at 200 characters first, then the lowest-ranked citations are dropped, then the last passage is trimmed at a word boundary, and the echoed query is shortened further if that is still not enough. `truncated` is `true` whenever anything was cut.
- With no results the tool returns `count: 0`, an empty `citations` list and a note saying nothing relevant was found.
- Errors never reach the model. A failed search returns a fixed message telling it to answer without the knowledge base, and the exception goes to Laravel's exception reporter.

Retrieved passages are document content, not instructions. The default tool description tells the model so, but a description is not a barrier. Treat everything the tool returns as untrusted input, and see [SECURITY.md](SECURITY.md).

### Reranking

Reranking is off by default. When on, the retriever fetches `limit * candidates` chunks by vector similarity and asks an SDK reranking model to pick the best `limit`:

```env
RAG_RERANK=true
RAG_RERANK_PROVIDER=cohere
RAG_RERANK_CANDIDATES=3
```

- `RAG_RERANK_MODEL` empty means the provider's default reranking model.
- `RAG_RERANK_PROVIDER` empty means `ai.default_for_reranking`. It must be a single provider name.
- `RAG_RERANK_CANDIDATES` must be between 1 and 10, and the pool sent to the reranker is capped at 100. With reranking on, a `limit` above 100 returns at most 100 results.
- `score` stays the vector score, and `topScore()` is the highest vector score. The reranker's score is on the citation as `rerankScore`, and as `rerank_score` in the tool's output.
- If the reranker fails, the search still answers in vector order and logs a warning.
- It adds one provider call per search.

---

## Why not `SimilaritySearch`?

The SDK ships a `SimilaritySearch` tool: you point it at an Eloquent model and a vector column, and it returns matching rows. It is a fine start for a Postgres app that already has vectors in a table. This package covers what it leaves out:

| | `SimilaritySearch` (SDK) | `laravel-rag` |
|---|---|---|
| Databases | pgvector; throws on MySQL and SQLite | pgvector in production; SQLite and MySQL compute cosine in PHP |
| Scores | A `minSimilarity` cutoff, but the score is not returned | Every citation carries its similarity score; `min_score` drops weak matches |
| Citations | The matching rows, minus the vector column | Chunk, source, position and score on every result |
| Chunking and ingestion | None: you bring a table that already holds vectors | `rag:ingest` chunks, hashes, embeds and stores; unchanged documents are skipped |
| Evals | None | `rag:eval` scores recall, MRR and pass rate, and `--ci` fails the build |
| Provider for query embedding | The query is embedded with the SDK's default embeddings provider; nothing records which model made the stored vectors | The embedding model id is stored on every chunk, and a search with a different model throws |
They are not exclusive. `Rag::tool()` is an SDK tool, so it sits in the same `tools()` list.

---

## How it works

```mermaid
flowchart TD
    subgraph Ingest["Ingest"]
        DOC["Document"] --> HASH{"content hash<br/>changed?"}
        HASH -->|"no"| SKIP["skip — no spend"]
        HASH -->|"yes"| CHUNK["RecursiveChunker<br/>paragraph → line → sentence"]
        CHUNK --> GUARD{"over token<br/>threshold?"}
        GUARD -->|"yes"| CONFIRM["ask before spending"]
        GUARD -->|"no"| EMBED["Embedder"]
        CONFIRM -->|"approved"| EMBED
        EMBED --> STORE[("rag_chunks<br/>vector + embedding_model")]
    end

    subgraph Retrieve["Retrieve"]
        Q["query"] --> MODELCHECK{"corpus model ==<br/>configured model?"}
        MODELCHECK -->|"no"| THROW["throw MixedEmbeddingModels"]
        MODELCHECK -->|"yes"| QEMBED["embed query"]
        QEMBED --> ANN["pgvector HNSW<br/>cosine distance"]
        ANN --> FLOOR{"score >= min_score?"}
        FLOOR -->|"no"| DROP["dropped"]
        FLOOR -->|"yes"| CITE["Citation"]
    end

    STORE -.-> ANN

    subgraph Eval["Eval"]
        SUITE["suite.json<br/>golden questions"] --> RUN["EvalSuite::run"]
        RUN --> METRICS["recall@k · MRR · pass rate"]
        METRICS --> GATE{"above floor?"}
        GATE -->|"no"| REDBUILD["exit 1 — build fails"]
        GATE -->|"yes"| GREEN["exit 0"]
    end

    CITE -.-> RUN

    classDef bad fill:#ffd6d6,stroke:#c0392b,color:#4a1210
    classDef good fill:#c7f0d8,stroke:#1a7f45,color:#0b3d22
    classDef store fill:#ffe9b8,stroke:#b07d1a,color:#4a3308

    class THROW,REDBUILD,DROP bad
    class GREEN,SKIP good
    class STORE store
```

---

## The eval harness

An eval suite is a JSON file of questions with known-good answer locations:

```json
{
  "refund-window": {
    "query": "How long do customers have to request a refund?",
    "expected_sources": ["policies/refunds.md"],
    "must_contain": ["30 days"]
  },
  "not-in-corpus": {
    "query": "What is the CEO's home address?",
    "expect_no_answer": true
  }
}
```

Every terminal sample below is real output from this repository's own fixture
suite, which you can reproduce after cloning:

```
$ vendor/bin/testbench rag:ingest tests/Fixtures/corpus --force
$ vendor/bin/testbench rag:eval --suite=tests/Fixtures/suite.json --k=4

   INFO  Suite 'suite' — 5 cases at k=4.

+----------------+-------+-------+-------+
| metric         | score | floor |       |
+----------------+-------+-------+-------+
| pass_rate      | 0.800 | 0.900 | BELOW |
| recall_at_k    | 1.000 | 0.800 | pass  |
| mrr            | 0.900 | 0.600 | pass  |
| mean_top_score | 0.522 | —     |       |
+----------------+-------+-------+-------+

   WARN  1 case(s) failed:


  ✗ leave-not-shipping
    query: What is the annual leave accrual rate?
    → retrieved context contains forbidden text: 'PO boxes'
```

That failure is the point of the example. The fixture corpus is three chunks, one
per document, so a window of four returns all of it, and a `must_not_contain`
assertion against a window that holds the whole corpus fails whatever the ranking
is. It tells you nothing about retrieval. A window has to be smaller than the
corpus to measure anything, which is why CI ingests the fixtures in 200-character
chunks (seven of them) and scores them at `k=2`.

### What gets measured

| metric | what it tells you | how it is computed |
|---|---|---|
| `recall_at_k` | Did the right sources appear in the window at all? | Per case, the fraction of `expected_sources` present in the top `k`; averaged across cases. A case naming no expected sources scores 1.0. |
| `mrr` | Did the right source appear *first*? Most prompts only fit a handful of chunks, so rank matters more than presence. | Per case, `1 / rank` of the first returned chunk whose source is expected, or 0.0 if none is; averaged across cases. Rank is counted over returned **chunks**, not documents. |
| `pass_rate` | Fraction of cases with zero violations. | A case passes only with no violations at all, across `expected_sources`, `must_contain`, `must_not_contain` and `expect_no_answer`. |
| `mean_top_score` | Absolute similarity of the best match. Drifting downward across runs usually means corpus dilution. | Mean of the top score per case. A case that retrieved nothing contributes 0.0. |

One asymmetry worth knowing, because it changes how you read a report:
`pass_rate` counts every case, while `recall_at_k`, `mrr` and `mean_top_score`
are averaged over answerable cases only — cases marked `expect_no_answer` are
excluded from all three, since "the rank of the correct source" is meaningless
when correct means *nothing*. A suite of nothing but `expect_no_answer` cases
therefore reports `recall_at_k` of 0.000, and that number means "not
applicable", not "broken".

### Why retrieval and not answer quality

Scoring the model's final answer needs an LLM in the loop, which is slow, costs money per run, and is itself non-deterministic — the exact properties that stop a check from running on every push.

Retrieval is measurable without any of that. It is deterministic, it costs one embedding call per question, and it is where most RAG regressions actually originate. If the right chunk never reaches the prompt, no amount of prompt engineering downstream recovers it.

Answer-level evaluation is worth doing. It belongs in a nightly job, not a pre-merge gate.

### `expect_no_answer` matters more than it looks

The most damaging RAG failure is not a wrong answer to a hard question. It is a confident answer to a question the corpus cannot address. A retriever that always returns `k` results will hand the model irrelevant context, and the model will use it.

A case marked `expect_no_answer: true` fails if the retriever returns anything at
all, and what "anything" means depends entirely on `min_score`: a chunk is kept
when its score is greater than or equal to the floor. At the default `min_score`
of `0.0` nothing is ever dropped, so these cases fail against any non-empty
corpus. That is not a bug to work around, it is the floor telling you it has not
been set yet — add the cases first, look at what scores come back for questions
the corpus cannot answer, and put the floor between those and your real matches.

Include several. They are the only thing that measures whether your system can
say "I don't know."

They are also excluded from `recall_at_k`, `mrr` and `mean_top_score`, and counted
in `pass_rate` — see the note under [What gets measured](#what-gets-measured).

---

## Wiring it into CI

```yaml
- name: Fail the build if retrieval regressed
  run: php artisan rag:eval --ci
```

That is the shape inside an application. This repository gates itself the same
way — see [`.github/workflows/ci.yml`](.github/workflows/ci.yml), where the
`eval` job stands up Postgres with pgvector, ingests `tests/Fixtures/corpus` and
runs the suite against it. Inside a package there is no application to boot, so
those steps call `vendor/bin/testbench` instead of `php artisan`; the commands and
flags are identical. A weekly real-model run is described under
[Testing](#real-model-eval).

Floors live in `config/rag.php`:

```php
'thresholds' => [
    'pass_rate'   => 0.90,
    'recall_at_k' => 0.80,
    'mrr'         => 0.60,
],
```

### Comparing against a baseline

Absolute floors answer "is this bad?". Once a corpus is large enough that no absolute number is obviously right, the more useful question is "did this get worse?".

```bash
php artisan rag:eval --save=.rag-baseline.json          # on main
php artisan rag:eval --baseline=.rag-baseline.json --ci # on a PR
```

Here is a real one. The baseline was saved at the default chunk size of 1000 and
`k=2`; the comparison run is the same corpus and the same suite re-chunked at 200
with an overlap of 40:

```
   INFO  Against baseline:

  pass_rate        1.000 → 1.000  +0.000
  recall_at_k      1.000 → 1.000  +0.000
  mrr              0.900 → 1.000  +0.100
  mean_top_score   0.522 → 0.550  +0.027

   WARN  Baseline was run with different settings: chunk_size, chunk_overlap.
```

Smaller chunks made ranking better here: `mrr` went from 0.900 to 1.000, so the
right document comes back first for every answerable case. The warning underneath
is the other half of the feature. Each run records the settings it was made with
(`k`, chunking, `min_score`, reranking and the embedding model id), and a
comparison across different settings says so, because a metric that moved after
a chunking change is not a like-for-like regression or improvement.

Both outcomes are possible, and the diff is what tells you which one you got.
Retrieval can get better on one metric and worse on another at the same time with
every unit test still passing and nothing throwing.

`--baseline` on its own reports. `--ci` is what makes any of it fail the build,
and with both flags a build fails on a regression as well as on a floor breach.
How far a metric may drop before it counts as a regression is set per metric in
`config/rag.php`:

```php
'tolerances' => [
    'pass_rate'      => 0.0,   // any measurable drop fails
    'recall_at_k'    => 0.0,
    'mrr'            => 0.0,
    'mean_top_score' => 0.02,  // absolute similarity drifts; give it room
],
```

Scores are compared at the four decimal places a saved baseline actually holds,
so a run compared against its own baseline reports no regression rather than a
rounding artefact.

### Eval history

Every `rag:eval` run is also written to `rag_eval_runs` — scores, failure
reasons, the settings it ran with (`k`, chunking, `min_score`, reranking), the embedding model that produced them, and the commit if one can be
determined from the environment or `.git/HEAD`. `--baseline` compares two points;
the table keeps all of them, which is what you want when a regression surfaces
weeks after the commit that caused it. Pass `--no-record` to skip the write. If
the write fails — a read-only database user in CI, say — it warns on stderr and
the eval still reports and still gates, because recording history is a
convenience and the gate is not.

---

## Design decisions

Four choices here are opinionated. Each solves a failure I would rather not repeat.

### 1 · Every chunk records the model that embedded it

Vectors from different embedding models occupy different spaces. Cosine distance between them is a real number with no meaning — so the query returns results, they rank plausibly, and they are wrong.

Swapping `text-embedding-3-small` for `-3-large` and reindexing only part of a corpus produces exactly this. Nothing errors.

So `embedding_model` is stored on every row, and the retriever refuses to search a corpus whose model does not match the configured one:

```
Ikram\Rag\Exceptions\MixedEmbeddingModels

  The searched corpus was embedded with "openai:text-embedding-3-small:1536" but
  the configured embedder is "openai:text-embedding-3-large:1536". Searching
  across models returns confident nonsense. Either restore the previous model in
  config/rag.php, or run `php artisan rag:reindex`.
```

The id is `{driver}:{model}:{dimensions}`. Ids written by 0.2 have no width
suffix, and they still match the same model at the same width, so upgrading does
not force a reindex.
The check runs on every `retrieve()` call, scoped to the collection and filters being searched, before the query is embedded, so it
fails on the first search after a mismatched model swap rather than at some
later point that happens to notice. A hard failure there beats plausible
nonsense in production.

### 2 · Ingestion asks before spending

Embedding cost is invisible until the invoice. Above a configurable token estimate, `rag:ingest` prints what it is about to spend and waits.

Content is also hashed. Re-ingesting a nightly export of 10,000 documents where nine changed costs nine documents, not ten thousand.

### 3 · Retrieval always carries citations

There is no API for "just give me the context string." `RetrievalResult` holds the chunk, source, position and score together, because an answer you cannot trace is precisely what makes these failures invisible.

### 4 · Returning nothing is a valid answer

`min_score` drops weak matches rather than padding the prompt to reach `k`. Combined with `expect_no_answer` cases in the suite, this is what stops the system inventing answers from irrelevant context.

---

## Commands

| command | purpose |
|---|---|
| `rag:ingest {path}` | Chunk, embed and store a file or directory |
| `rag:eval` | Run the suite; `--ci` exits non-zero below threshold |
| `rag:reindex` | Re-embed stored documents with the current model; `--stale` only those from another model, `--resize` changes the pgvector column width |

<details>
<summary><strong>Full options</strong></summary>

```bash
# Ingest
rag:ingest storage/docs --collection=policies
rag:ingest storage/docs --pattern='*.mdx'   # glob when path is a directory; default *.md
rag:ingest storage/docs --force             # skip the spend prompt

# Eval
rag:eval --suite=tests/rag/suite.json
rag:eval --k=10                             # widen the retrieval window
rag:eval --json                             # machine-readable on stdout, nothing else
rag:eval --ci                               # exit 1 on a floor breach or a regression
rag:eval --save=baseline.json               # write this run, for use as a future baseline
rag:eval --baseline=baseline.json           # compare against a saved run
rag:eval --no-record                        # skip the write to rag_eval_runs

# Reindex
rag:reindex --collection=policies
rag:reindex --stale                        # only documents embedded with another model (or not at all)
rag:reindex --resize                        # pgvector: change the column to rag.embedding.dimensions, then --stale
rag:reindex --force                         # skip confirmation
```

`rag:reindex` re-embeds from the content stored at ingest time, and re-reads the
file only for documents ingested before 0.3. A failure is reported per document
and the exit code is non-zero if any failed; run it again to resume.

`--resize` is for switching to a model of a different width on Postgres. It
refuses non-`vector` columns (for example `halfvec`) and cannot be combined with
`--collection`. It clears every vector by dropping and re-adding the column
(no table rewrite), and recreates the HNSW index only when the width is 2000 or
less. Until re-embedding finishes, search returns fewer results rather than
erroring, because rows without a vector are skipped. Run `rag:reindex --stale`
to finish a partial run.

Directory ingestion records sources with forward slashes on every platform, so a
suite committed on Linux still matches a corpus ingested on Windows.

</details>

---

## Configuration

Every setting is documented inline in `config/rag.php`. The two worth knowing:

**`chunking.size` and `chunking.overlap`** — do not tune these by intuition.
Change one, run `rag:reindex`, run `rag:eval`, and keep the change only if the
scores improve. That loop is why the harness ships with the package.

The `rag:reindex` step is not optional and it is the easy one to miss. Ingestion
skips documents whose content hash is unchanged, and a chunking change does not
change any document's content — so `rag:ingest` after a chunk-size edit reports
everything as unchanged and skipped, leaves the old chunks in place, and the eval
that follows scores the configuration you were trying to replace. `rag:reindex`
clears the hashes and rebuilds, which is what actually applies the change.

**`embedding.*`** — provider, model, width and batching; see [Choose an embedding provider](#choose-an-embedding-provider). A provider or model change at the same width needs `rag:reindex --stale`.

**`retrieval.default_limit`** is used whenever no limit is passed. A limit below 1 throws, and a blank query returns an empty result without calling the embedding provider. Filter keys must match `/^[A-Za-z0-9_]+$/`.

**`retrieval.min_score`** — starts at `0.0` (disabled). Raise it once your suite contains `expect_no_answer` cases, since those are what tell you where the floor belongs.

---

## Testing

```bash
composer test      # pest
composer lint      # pint --test
composer analyse   # phpstan level 6
```

The suite runs against **SQLite in memory with a deterministic hash-based
embedder** — no network, no API key, no Postgres. Retrieval tests assert real
ranking rather than mocked returns, because `FakeEmbedder` places texts sharing
vocabulary genuinely near each other.

That matters more than it sounds. An offline suite runs on every push. A suite
needing an API key runs nightly, if at all.

CI additionally runs the eval job against real Postgres with pgvector, so the ANN
path and the in-PHP fallback are both exercised, and gates it on the thresholds in
`config/rag.php`. Run locally on the in-PHP path (SQLite, the fake embedder at 64
dimensions, 200-character chunks with an overlap of 40, `k=2`), the fixture suite
scores:

| metric | score |
|---|---|
| `pass_rate` | 1.000 |
| `recall_at_k` | 1.000 |
| `mrr` | 1.000 |
| `mean_top_score` | 0.550 |

### Real-model eval

The fake embedder proves the plumbing, not semantic retrieval. A separate
workflow, [`eval-real.yml`](.github/workflows/eval-real.yml), runs weekly (Monday
06:00 UTC) and on demand. It installs Ollama in the runner, pulls
`nomic-embed-text` (768 dimensions, native mode), and runs the same fixture suite
against a SQLite file database. It needs no API key and costs nothing.

<!-- TODO(task-12 phase 2): record the real-model numbers and date here once the
first eval-real run has finished, and set thresholds from them. -->
There is also `tests/verify-standalone.php`, which covers chunking, the fake
embedder, cosine similarity, pgvector literal formatting and the full eval
scoring path on bare PHP with no Composer dependencies at all:

```bash
php tests/verify-standalone.php
```

It exists because a test suite you cannot run when the network is down is a test
suite you do not run.

---

## Limitations

Worth knowing before you adopt this.

**Postgres or nothing, in production.** SQLite and MySQL work, but fall back to computing cosine similarity in PHP, which loads every candidate row into memory. Fine for tests and small corpora. Not fine at scale.

**Reranking is optional and off by default.** Retrieval is single-stage vector search unless you turn on [reranking](#reranking), which adds one provider call per search.

**No hybrid search.** Dense vectors only, no BM25 blending. Exact keyword matches — product codes, names, identifiers — are where pure vector search is weakest.

**Embedding drivers.** `sdk` (any Laravel AI SDK provider that supports embeddings) and `fake`. The old `openai` driver name still works as a deprecated alias and goes in 1.0. For anything else, implementing `Embedder` and registering it with `Rag::extend()` is enough.

**`FakeEmbedder` is not semantic.** It is hash-based bag-of-words. It preserves
enough ordering to test retrieval logic. It is not a small language model, and
the retriever will refuse to mix its vectors with real ones.

Its collisions are real, and worth knowing if you write tests against it. Tokens
are hashed into a fixed number of buckets, and at the 64 dimensions the suite
uses, `md5('refund')` and `md5('shipping')` land in the same one — so a
single-token query is settled by that collision rather than by relevance.
Multi-word queries carry enough signal to rank correctly, which is what the
retrieval tests use. Assert on ranking with realistic queries, not single words.

---

## Contributing

Issues and pull requests welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for
setup and the checks CI runs.

The short version: run `composer test`, `composer lint`, `composer analyse` and
`composer verify` before opening a pull request. If you change anything touching
retrieval — chunking, scoring, filtering, the query path — include the
before-and-after `rag:eval` output in the description. That is the standard this
package asks of its users, so it should hold itself to it.

Released versions are listed in [CHANGELOG.md](CHANGELOG.md). Security reports go
through [SECURITY.md](SECURITY.md), not the public issue tracker.

---

## License

MIT — see [LICENSE](LICENSE).

---

Built by [Ikram UL Mustafa](https://ikramulmustafa.com) — I build RAG and agent systems on Laravel. Notes on the engineering behind this package are at [ikramulmustafa.com](https://ikramulmustafa.com).
