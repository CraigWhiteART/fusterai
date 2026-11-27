<?php

namespace App\Domains\Mailbox\Sync\Imap;

use App\Domains\Mailbox\Sync\Exceptions\MailSourceException;
use Throwable;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * The raw IMAP plumbing shared by ImapMailSource (read-only) and
 * ImapInboxArchiver (writes): connect + LOGIN through webklex, then send one
 * command at a time and read the complete response back, literals included.
 *
 * The using class provides $config, $parser, $protocol and close().
 */
trait SpeaksImap
{
    /** Log out and forget the connection. Safe to call more than once. */
    abstract public function close(): void;

    /**
     * Send one command and return the complete raw response (untagged lines
     * plus the tagged completion), literals included verbatim.
     */
    private function command(string $command): string
    {
        $protocol = $this->protocol();

        try {
            $tag = null;
            $response = $protocol->sendRequest($command, [], $tag);
            $raw = $this->readUntilTagged($protocol, $response, (string) $tag);
        } catch (MailSourceException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Connection state is unknown after a failed read; force a reconnect.
            $this->close();
            throw new MailSourceException("IMAP command failed: {$this->redact($command)}: {$e->getMessage()}", 0, $e);
        }

        $status = $this->parser->taggedStatus($raw, (string) $tag);
        if ($status === null || $status['status'] !== 'OK') {
            throw new MailSourceException("IMAP {$this->redact($command)} failed: ".($status['status'] ?? '?').' '.($status['text'] ?? ''));
        }

        return $raw;
    }

    private function readUntilTagged(ImapProtocol $protocol, Response $response, string $tag): string
    {
        $raw = '';
        while (true) {
            $line = $protocol->nextLine($response);
            $raw .= $line;

            // A line ending in {n} announces n literal bytes, which may contain
            // newlines and may be followed by more of the same response line.
            while (preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $m)) {
                $need = (int) $m[1];
                $literal = '';
                while (strlen($literal) < $need) {
                    $literal .= $protocol->nextLine($response);
                }
                $raw .= $literal;
                $line = substr($literal, $need);
                if ($line === '') {
                    $line = $protocol->nextLine($response);
                    $raw .= $line;
                }
            }

            if (str_starts_with($line, $tag.' ')) {
                return $raw;
            }
        }
    }

    private function protocol(): ImapProtocol
    {
        if ($this->protocol !== null) {
            return $this->protocol;
        }

        $encryption = $this->config['encryption'] ?? 'ssl';
        $encryption = in_array($encryption, [null, false, '', 'none', 'notls'], true) ? false : (string) $encryption;

        try {
            $protocol = new ImapProtocol(Config::make(), (bool) ($this->config['validate_cert'] ?? true), $encryption);
            $protocol->setConnectionTimeout((int) ($this->config['timeout'] ?? 30));
            $protocol->connect((string) $this->config['host'], (int) ($this->config['port'] ?? 993));
            $protocol->login((string) $this->config['username'], (string) $this->config['password']);
        } catch (Throwable $e) {
            throw new MailSourceException('IMAP connect/login failed: '.$e->getMessage(), 0, $e);
        }

        return $this->protocol = $protocol;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function redact(string $command): string
    {
        return str_starts_with(strtoupper($command), 'LOGIN') ? 'LOGIN ***' : $command;
    }
}
