<?php

namespace App\Http\Controllers\Conversations;

use App\Domains\Conversation\Models\Attachment;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a thread attachment to staff who can view its conversation.
 * Raster images are served inline (so cid: images in an email body render);
 * everything else, SVG included, is a download. nosniff + a sandboxing CSP
 * stop a crafted file from running in the app's origin.
 */
class AttachmentController extends Controller
{
    public function show(Attachment $attachment): StreamedResponse
    {
        $conversation = $attachment->thread?->conversation;
        abort_if($conversation === null, 404);
        $this->authorize('view', $conversation);

        $disk = Storage::disk(config('filesystems.default'));
        abort_unless($disk->exists($attachment->path), 404);

        $inline = $attachment->isInlineImage();

        return $disk->response(
            $attachment->path,
            $attachment->filename,
            [
                'Content-Type' => $inline ? strtolower($attachment->mime_type) : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, max-age=3600',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }
}
