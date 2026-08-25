<?php

declare(strict_types=1);

// Minimal PSR-4 autoloader for the framework-independent parts of the package.
spl_autoload_register(function (string $class): void {
    if (! str_starts_with($class, 'Ikram\\Rag\\')) {
        return;
    }

    $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, 10)).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use Ikram\Rag\Chunking\RecursiveChunker;
use Ikram\Rag\Contracts\Retriever;
use Ikram\Rag\Embedding\FakeEmbedder;
use Ikram\Rag\Eval\EvalCase;
use Ikram\Rag\Eval\EvalSuite;
use Ikram\Rag\Exceptions\EvalThresholdNotMet;
use Ikram\Rag\Retrieval\Citation;
use Ikram\Rag\Retrieval\RetrievalResult;
use Ikram\Rag\Retrieval\Vector;
use Ikram\Rag\Retrieval\VectorRetriever;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $result = $fn();

        if ($result === true) {
            echo "  ✓ {$name}\n";
            $passed++;
        } else {
            echo "  ✗ {$name} — returned ".var_export($result, true)."\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "  ✗ {$name} — ".get_class($e).': '.$e->getMessage()."\n";
        $failed++;
    }
}

function throws(string $name, string $expected, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        echo "  ✗ {$name} — expected {$expected}, nothing thrown\n";
        $failed++;
    } catch (Throwable $e) {
        if ($e instanceof $expected) {
            echo "  ✓ {$name}\n";
            $passed++;
        } else {
            echo "  ✗ {$name} — expected {$expected}, got ".get_class($e)."\n";
            $failed++;
        }
    }
}

/** In-memory retriever so eval logic can be exercised without a database. */
final class ArrayRetriever implements Retriever
{
    /** @param array<string, string> $docs source => text */
    public function __construct(private array $docs, private FakeEmbedder $embedder) {}

    public function retrieve(string $query, int $limit = 5, array $filters = []): RetrievalResult
    {
        [$q] = $this->embedder->embed([$query]);
        $scored = [];
        $id = 0;

        foreach ($this->docs as $source => $text) {
            [$v] = $this->embedder->embed([$text]);
            $scored[] = new Citation(++$id, $id, $source, $text, 0, VectorRetriever::cosine($q, $v));
        }

        usort($scored, fn ($a, $b) => $b->score <=> $a->score);

        return new RetrievalResult($query, array_slice($scored, 0, $limit), $this->embedder->modelId());
    }
}

echo "\nRecursiveChunker\n";

check('empty input yields no chunks', fn () => (new RecursiveChunker)->chunk('   ') === []);

check('short text stays one chunk', function () {
    $c = (new RecursiveChunker(1000))->chunk('A short document.');

    return count($c) === 1 && $c[0]->text === 'A short document.';
});

check('long text splits into several', function () {
    return count((new RecursiveChunker(200, 40))->chunk(str_repeat('word ', 400))) > 1;
});

check('prefers paragraph boundary over hard cut', function () {
    $text = str_repeat('alpha ', 20)."\n\n".str_repeat('beta ', 20);
    $c = (new RecursiveChunker(130, 20))->chunk($text);

    return count($c) > 1 && ! str_contains($c[0]->text, 'beta');
});

check('indices are sequential from zero', function () {
    $c = (new RecursiveChunker(100, 10))->chunk(str_repeat('word ', 200));

    return array_map(fn ($x) => $x->index, $c) === range(0, count($c) - 1);
});

check('terminates on pathological overlap', function () {
    $c = (new RecursiveChunker(50, 49))->chunk(str_repeat('x', 5000));

    return count($c) > 0 && count($c) < 5000;
});

check('overlap actually overlaps', function () {
    $c = (new RecursiveChunker(100, 50))->chunk(str_repeat('alpha beta gamma delta ', 30));

    return count($c) > 2 && $c[1]->startOffset < $c[0]->endOffset;
});

throws('rejects overlap >= size', InvalidArgumentException::class,
    fn () => new RecursiveChunker(100, 100));

throws('rejects zero size', InvalidArgumentException::class,
    fn () => new RecursiveChunker(0, 0));

echo "\nFakeEmbedder\n";

check('respects configured dimensions', fn () => count((new FakeEmbedder(32))->embed(['hello'])[0]) === 32);

check('is deterministic', function () {
    $e = new FakeEmbedder;

    return $e->embed(['same text'])[0] === $e->embed(['same text'])[0];
});

check('vectors are unit length', function () {
    $v = (new FakeEmbedder)->embed(['some reasonable amount of text here'])[0];
    $mag = sqrt(array_sum(array_map(fn ($x) => $x ** 2, $v)));

    return abs($mag - 1.0) < 0.0001;
});

check('ranks shared vocabulary above unrelated text', function () {
    $e = new FakeEmbedder;
    [$q, $rel, $unrel] = $e->embed([
        'refund policy for damaged goods',
        'our refund policy covers damaged goods returned within 30 days',
        'the quarterly engineering hiring plan for the platform team',
    ]);

    return VectorRetriever::cosine($q, $rel) > VectorRetriever::cosine($q, $unrel);
});

check('batch order is preserved', function () {
    $e = new FakeEmbedder;
    $batch = $e->embed(['first', 'second', 'third']);

    return $batch[0] === $e->embed(['first'])[0] && $batch[2] === $e->embed(['third'])[0];
});

check('declares itself fake', fn () => str_starts_with((new FakeEmbedder)->modelId(), 'fake:'));

check('handles tokenless text', fn () => (new FakeEmbedder(8))->embed(['!!! ???'])[0] === array_fill(0, 8, 0.0));

check('empty batch returns empty', fn () => (new FakeEmbedder)->embed([]) === []);

echo "\nCosine similarity\n";

check('identical vectors score 1', fn () => abs(VectorRetriever::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]) - 1.0) < 0.0001);
check('orthogonal vectors score 0', fn () => abs(VectorRetriever::cosine([1.0, 0.0], [0.0, 1.0])) < 0.0001);
check('opposite vectors score -1', fn () => abs(VectorRetriever::cosine([1.0, 0.0], [-1.0, 0.0]) + 1.0) < 0.0001);
check('zero vector does not divide by zero', fn () => VectorRetriever::cosine([0.0, 0.0], [1.0, 1.0]) === 0.0);
check('magnitude does not affect score', fn () => abs(VectorRetriever::cosine([1.0, 1.0], [5.0, 5.0]) - 1.0) < 0.0001);

echo "\npgvector literal formatting\n";

check('formats a vector literal', fn () => Vector::toLiteral([0.5, -0.25]) === '[0.5,-0.25]');
check('renders zero without trailing dot', fn () => Vector::toLiteral([0.0]) === '[0]');
check('avoids scientific notation', fn () => ! str_contains(Vector::toLiteral([0.00000001234]), 'E'));
check('round-trips through parse', function () {
    $v = [0.5, -0.25, 0.125];

    return Vector::fromLiteral(Vector::toLiteral($v)) === $v;
});
check('parses an empty literal', fn () => Vector::fromLiteral('[]') === []);

echo "\nRetrievalResult\n";

$result = new RetrievalResult('q', [
    new Citation(1, 10, 'a.md', 'text one', 0, 0.9),
    new Citation(2, 10, 'a.md', 'text two', 1, 0.8),
    new Citation(3, 11, 'b.md', 'text three', 0, 0.7),
], 'fake:deterministic-hash');

check('counts citations', fn () => count($result) === 3);
check('deduplicates document ids', fn () => $result->documentIds() === [10, 11]);
check('deduplicates sources', fn () => $result->sources() === ['a.md', 'b.md']);
check('reports the top score', fn () => $result->topScore() === 0.9);
check('is iterable', fn () => count(iterator_to_array($result)) === 3);
check('builds numbered prompt markers', fn () => str_contains($result->toPrompt(), '[1] source: a.md')
    && str_contains($result->toPrompt(), '[3] source: b.md'));
check('empty result reports empty', fn () => (new RetrievalResult('q', [], 'm'))->isEmpty());
check('empty result has zero top score', fn () => (new RetrievalResult('q', [], 'm'))->topScore() === 0.0);
check('serialises to json with citations', function () use ($result) {
    $j = json_decode((string) json_encode($result), true);

    return $j['count'] === 3 && $j['citations'][0]['source'] === 'a.md';
});

echo "\nEvalSuite scoring\n";

$retriever = new ArrayRetriever([
    'policies/refunds.md' => 'Customers may request a refund within 30 days of delivery for damaged goods.',
    'policies/shipping.md' => 'Standard shipping takes 3 to 5 working days across the mainland.',
    'handbook/leave.md' => 'Annual leave accrues monthly and carries over to the following year.',
], new FakeEmbedder);

check('passes when expected source is retrieved', function () use ($retriever) {
    $r = (new EvalSuite('t', [new EvalCase('a', 'refund damaged goods', ['policies/refunds.md'])]))->run($retriever);

    return $r->passed() && $r->metric('recall_at_k') === 1.0;
});

check('fails with an explanation when source is missing', function () use ($retriever) {
    $r = (new EvalSuite('t', [new EvalCase('a', 'refund', ['policies/nope.md'])]))->run($retriever);

    return ! $r->passed() && str_contains($r->failures()[0]->failures[0], 'not in top-');
});

check('mrr is 1.0 when correct source ranks first', function () use ($retriever) {
    $r = (new EvalSuite('t', [new EvalCase('a', 'refund damaged goods', ['policies/refunds.md'])]))->run($retriever);

    return $r->metric('mrr') === 1.0;
});

check('mrr is 0 when correct source is absent', function () use ($retriever) {
    $r = (new EvalSuite('t', [new EvalCase('a', 'refund', ['policies/nope.md'])], k: 3))->run($retriever);

    return $r->metric('mrr') === 0.0;
});

check('partial recall on multiple expected sources', function () use ($retriever) {
    $r = (new EvalSuite('t', [
        new EvalCase('a', 'refund damaged goods', ['policies/refunds.md', 'policies/nope.md']),
    ], k: 1))->run($retriever);

    return abs($r->metric('recall_at_k') - 0.5) < 0.0001;
});

check('enforces must_contain', function () use ($retriever) {
    $r = (new EvalSuite('t', [
        new EvalCase('a', 'refund', ['policies/refunds.md'], mustContain: ['30 days']),
    ]))->run($retriever);

    return $r->passed();
});

check('catches must_not_contain violations', function () use ($retriever) {
    $r = (new EvalSuite('t', [
        new EvalCase('a', 'refund', ['policies/refunds.md'], mustNotContain: ['refund']),
    ]))->run($retriever);

    return ! $r->passed() && str_contains($r->failures()[0]->failures[0], 'forbidden text');
});

check('pass_rate reflects mixed results', function () use ($retriever) {
    $r = (new EvalSuite('t', [
        new EvalCase('good', 'refund damaged goods', ['policies/refunds.md']),
        new EvalCase('bad', 'refund', ['policies/nope.md']),
    ]))->run($retriever);

    return abs($r->metric('pass_rate') - 0.5) < 0.0001;
});

throws('threshold breach throws', EvalThresholdNotMet::class, function () use ($retriever) {
    (new EvalSuite('t', [new EvalCase('a', 'q', ['policies/nope.md'])]))
        ->run($retriever)
        ->assertThresholds(['recall_at_k' => 0.8]);
});

check('threshold met does not throw', function () use ($retriever) {
    (new EvalSuite('t', [new EvalCase('a', 'refund damaged goods', ['policies/refunds.md'])]))
        ->run($retriever)
        ->assertThresholds(['recall_at_k' => 0.8]);

    return true;
});

check('detects regression beyond tolerance', function () use ($retriever) {
    $good = (new EvalSuite('t', [new EvalCase('a', 'refund damaged goods', ['policies/refunds.md'])]))->run($retriever);
    $bad = (new EvalSuite('t', [new EvalCase('a', 'refund', ['policies/nope.md'])]))->run($retriever);

    return $bad->regressionsAgainst($good, ['recall_at_k' => 0.05]) !== [];
});

check('no regression when scores are stable', function () use ($retriever) {
    $s = new EvalSuite('t', [new EvalCase('a', 'refund damaged goods', ['policies/refunds.md'])]);

    return $s->run($retriever)->regressionsAgainst($s->run($retriever)) === [];
});

check('diff reports before, after and delta', function () use ($retriever) {
    $a = (new EvalSuite('t', [new EvalCase('x', 'refund damaged goods', ['policies/refunds.md'])]))->run($retriever);
    $b = (new EvalSuite('t', [new EvalCase('x', 'refund', ['policies/nope.md'])]))->run($retriever);
    $d = $b->diff($a);

    return $d['recall_at_k']['before'] === 1.0 && $d['recall_at_k']['delta'] < 0;
});

check('report serialises failures with reasons', function () use ($retriever) {
    $r = (new EvalSuite('t', [new EvalCase('missing', 'q', ['policies/nope.md'])]))->run($retriever);
    $j = json_decode((string) json_encode($r), true);

    return $j['suite'] === 't' && $j['failures'][0]['id'] === 'missing' && $j['failures'][0]['reasons'] !== [];
});

check('loads a suite from json', function () use ($retriever) {
    $p = sys_get_temp_dir().'/suite-'.uniqid().'.json';
    file_put_contents($p, json_encode([
        'refund' => ['query' => 'refund damaged goods', 'expected_sources' => ['policies/refunds.md']],
    ]));

    $ok = EvalSuite::fromJsonFile($p)->run($retriever)->passed();
    unlink($p);

    return $ok;
});

throws('rejects malformed suite file', InvalidArgumentException::class, function () {
    $p = sys_get_temp_dir().'/bad-'.uniqid().'.json';
    file_put_contents($p, 'not json');

    try {
        EvalSuite::fromJsonFile($p);
    } finally {
        unlink($p);
    }
});

throws('rejects missing suite file', InvalidArgumentException::class,
    fn () => EvalSuite::fromJsonFile('/no/such/file.json'));

throws('rejects empty suite', InvalidArgumentException::class,
    fn () => new EvalSuite('empty', []));

throws('rejects contradictory case', InvalidArgumentException::class,
    fn () => new EvalCase('bad', 'q', ['a.md'], expectNoAnswer: true));

throws('rejects empty query', InvalidArgumentException::class,
    fn () => new EvalCase('bad', ''));

echo "\n".str_repeat('-', 46)."\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
