# What this changes

<!-- One or two sentences. What problem does this solve? -->

## Checks

- [ ] `composer test` passes
- [ ] `composer lint` passes
- [ ] `composer analyse` passes (level 6, no new baseline entries)
- [ ] `composer verify` passes

## Does this touch retrieval?

Chunking, scoring, filtering, the embedder, or the query path.

- [ ] No — nothing below applies.
- [ ] Yes — before-and-after `rag:eval` output is included below.

<details>
<summary>Eval output</summary>

```
paste the --baseline diff here
```

</details>

Remember `rag:reindex` between the change and the second eval, or ingestion will
skip everything and the run will score the old configuration.

## Anything a reviewer should know

<!--
Worth calling out if either applies:
- The change affects the pgvector query path, which the SQLite suite does not
  reach — only the CI eval job does.
- The change touches one of the four design decisions in the README.
-->
