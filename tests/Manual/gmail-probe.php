<?php

/**
 * Read-only spike against the real Gmail mailbox: confirms that X-GM-EXT-1 is
 * advertised, that All Mail is visible over IMAP (found by \All, whatever its
 * localised name), that X-GM-MSGID / X-GM-THRID / X-GM-LABELS come back for
 * the newest messages, and that their FLAGS are unchanged afterwards (nothing
 * marked read). It only sends CAPABILITY, LIST, EXAMINE, UID FETCH with
 * BODY.PEEK / FLAGS, and LOGOUT. No Laravel boot, no database.
 *
 * Run from the repo root, inside the app container (PHP 8.4):
 *
 *   docker compose -f docker-compose.test.yml run --rm --no-deps --entrypoint php \
 *     -e IMAP_USERNAME=support@example.com -e IMAP_PASSWORD='abcd efgh ijkl mnop' \
 *     test tests/Manual/gmail-probe.php
 *
 * Optional: IMAP_HOST (imap.gmail.com), IMAP_PORT (993), IMAP_ENCRYPTION (ssl),
 * PROBE_COUNT (5). Output is JSON; it contains subjects and labels of the
 * newest messages, so do not paste it anywhere public.
 */

use App\Domains\Mailbox\Sync\Imap\ImapMailSource;

require __DIR__.'/../../vendor/autoload.php';

$username = getenv('IMAP_USERNAME') ?: '';
$password = getenv('IMAP_PASSWORD') ?: '';
if ($username === '' || $password === '') {
    fwrite(STDERR, "Set IMAP_USERNAME and IMAP_PASSWORD (Gmail app password).\n");
    exit(2);
}

$source = new ImapMailSource([
    'host' => getenv('IMAP_HOST') ?: 'imap.gmail.com',
    'port' => (int) (getenv('IMAP_PORT') ?: 993),
    'encryption' => getenv('IMAP_ENCRYPTION') ?: 'ssl',
    'validate_cert' => true,
    'username' => $username,
    'password' => ImapMailSource::normalizePassword($password),
]);

try {
    $report = $source->probe((int) (getenv('PROBE_COUNT') ?: 5));
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
} finally {
    $source->close();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

$ok = $report['x_gm_ext_1']
    && in_array('all', array_column($report['folders'], 'role'), true)
    && $report['envelopes'] !== []
    && $report['envelopes'][0]['gm_msgid'] !== null
    && $report['flags_unchanged'];

fwrite(STDERR, $ok ? "PASS: Gmail extensions, All Mail and read-only fetch confirmed.\n" : "CHECK: see report above.\n");
exit($ok ? 0 : 1);
