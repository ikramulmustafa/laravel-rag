# Tests

```bash
composer test          # full Pest suite (requires composer install)
php tests/verify-standalone.php   # framework-free subset, no dependencies
```

`verify-standalone.php` exercises the parts of the package that carry no Laravel
dependency — chunking, embedding, cosine similarity, vector literal formatting,
and the whole eval scoring path. It needs nothing but PHP 8.2, which makes it a
fast smoke test when you do not want to wait on `composer install`.

The Pest suite is the real one. It additionally covers ingestion, database-backed
retrieval, the mixed-model guard, and the console commands, using SQLite in memory
with the deterministic `FakeEmbedder` — no network, no API key.
