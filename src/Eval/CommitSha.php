<?php

declare(strict_types=1);

namespace Ikram\Rag\Eval;

/**
 * Best-effort commit identifier for a recorded eval run.
 *
 * A stored score is only useful if you can tell what produced it. Without a commit
 * the history answers "recall was 0.71 on Tuesday", which is not actionable; with one
 * it answers "recall dropped at this commit", which is.
 *
 * Deliberately no subprocess. Shelling out to `git` from a package that runs inside
 * someone's CI container is a portability problem and a small attack surface for no
 * benefit — the two files this needs are plain text.
 */
final class CommitSha
{
    /**
     * Environment variables set by common CI providers, in preference order.
     *
     * @var list<string>
     */
    private const ENV_VARS = [
        'RAG_EVAL_GIT_SHA',
        'GITHUB_SHA',
        'CI_COMMIT_SHA',
        'BUILDKITE_COMMIT',
    ];

    public static function detect(string $basePath): ?string
    {
        foreach (self::ENV_VARS as $name) {
            $value = getenv($name);

            if (is_string($value) && self::looksLikeSha(trim($value))) {
                return trim($value);
            }
        }

        return self::fromGitDirectory(rtrim($basePath, '/\\').'/.git');
    }

    private static function fromGitDirectory(string $gitDir): ?string
    {
        $head = self::read($gitDir.'/HEAD');

        if ($head === null) {
            return null;
        }

        // Detached HEAD holds the sha directly.
        if (! str_starts_with($head, 'ref: ')) {
            return self::looksLikeSha($head) ? $head : null;
        }

        $ref = self::read($gitDir.'/'.substr($head, 5));

        // A packed ref (git gc has run) leaves no loose file. Reading packed-refs is
        // more parsing than a nullable audit column is worth.
        return $ref !== null && self::looksLikeSha($ref) ? $ref : null;
    }

    private static function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : trim($contents);
    }

    private static function looksLikeSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }
}
