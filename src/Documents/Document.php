<?php

declare(strict_types=1);

namespace Ikram\Rag\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $source
 * @property string $collection
 * @property string $content_hash
 * @property array<string, mixed> $metadata
 */
final class Document extends Model
{
    protected $table = 'rag_documents';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
    ];

    /** @return HasMany<Chunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'document_id');
    }

    /**
     * Content hash drives re-ingestion. If the source text has not changed there is
     * no reason to pay for embeddings again, and re-embedding unchanged documents is
     * the most common way ingestion bills quietly climb.
     */
    public static function hashFor(string $content): string
    {
        return hash('sha256', $content);
    }
}
