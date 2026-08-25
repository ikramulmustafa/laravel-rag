<?php

declare(strict_types=1);

namespace Ikram\Rag\Documents;

use Ikram\Rag\Retrieval\Vector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property string $text
 * @property int $position
 * @property string $embedding_model
 * @property array<string, mixed> $metadata
 */
final class Chunk extends Model
{
    protected $table = 'rag_chunks';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
        'position' => 'integer',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * @deprecated Use {@see Vector::toLiteral()}. Kept as a
     * thin forward so existing call sites keep working.
     *
     * @param  list<float>  $vector
     */
    public static function toVectorLiteral(array $vector): string
    {
        return Vector::toLiteral($vector);
    }
}
