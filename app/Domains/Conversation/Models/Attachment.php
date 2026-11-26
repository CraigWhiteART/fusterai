<?php

namespace App\Domains\Conversation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = ['thread_id', 'filename', 'path', 'mime_type', 'size', 'inline'];

    protected $casts = [
        'inline' => 'boolean',
        'size' => 'integer',
    ];

    protected $appends = ['url'];

    /** Raster images a browser may show inline from the attachment route (never SVG). */
    public const INLINE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * Same-origin, authenticated URL (AttachmentController). The default
     * "local" disk is private, so Storage::url() pointed at a route that
     * needs a signature and never worked for attachments.
     */
    public function getUrlAttribute(): string
    {
        return route('attachments.show', $this->id, absolute: false);
    }

    public function isInlineImage(): bool
    {
        return in_array(strtolower((string) $this->mime_type), self::INLINE_IMAGE_MIMES, true);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function formattedSize(): string
    {
        $bytes = $this->size;
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
