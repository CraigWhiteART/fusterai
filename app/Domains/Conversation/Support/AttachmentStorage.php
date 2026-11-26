<?php

namespace App\Domains\Conversation\Support;

use App\Domains\Conversation\Models\Attachment;
use App\Domains\Conversation\Models\Thread;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an attachment received by mail under attachments/{conversation}/,
 * on the default disk, with a slugged name and server-executable extensions
 * renamed to .bin (in case the disk is ever served directly).
 */
final class AttachmentStorage
{
    public const DANGEROUS_EXTENSIONS = ['php', 'phtml', 'phar', 'php5', 'php7', 'sh', 'bash', 'py', 'rb', 'pl', 'cgi', 'exe', 'bat', 'cmd'];

    public static function maxBytes(): int
    {
        return max(1, (int) config('mailsync.max_attachment_bytes', 20 * 1024 * 1024));
    }

    public static function store(Thread $thread, string $name, string $content, string $mime, bool $inline = false): Attachment
    {
        $path = 'attachments/'.$thread->conversation_id.'/'.Str::uuid().'_'.self::safeFilename($name);
        Storage::put($path, $content);

        /** @var Attachment */
        return $thread->attachments()->create([
            'filename' => $name,
            'path' => $path,
            'mime_type' => $mime,
            'size' => strlen($content),
            'inline' => $inline,
        ]);
    }

    public static function safeFilename(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, self::DANGEROUS_EXTENSIONS, true) || ! preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
            $ext = 'bin';
        }

        return (Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'attachment').'.'.$ext;
    }
}
