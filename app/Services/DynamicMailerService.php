<?php

namespace App\Services;

use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Builds an Illuminate Mailer from a per-mailbox SMTP config array.
 * Extracted to avoid duplicating the same SMTP construction in every outbound job.
 */
class DynamicMailerService
{
    public function fromSmtpConfig(array $smtp): Mailer
    {
        return new Mailer('dynamic', app('view'), $this->transportFromSmtpConfig($smtp), app('events'));
    }

    /**
     * Map the mailbox "encryption" setting onto Symfony's EsmtpTransport.
     *
     * Symfony's `tls` constructor flag means implicit TLS (TLS from the first
     * byte, normally port 465). STARTTLS on 587 is a plain connection that is
     * upgraded after EHLO, which needs `tls: false` plus requireTls.
     *
     *  - "ssl", or port 465: implicit TLS.
     *  - "tls": plain connect, STARTTLS required (fails rather than sending in clear).
     *  - "none": plain connect; STARTTLS is still used if the server offers it, but not required.
     */
    public function transportFromSmtpConfig(array $smtp): EsmtpTransport
    {
        $port = (int) ($smtp['port'] ?? 587);
        $encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));
        $implicitTls = $encryption === 'ssl' || $port === 465;

        $transport = new EsmtpTransport(
            host: $smtp['host'] ?? 'localhost',
            port: $port,
            tls: $implicitTls,
        );

        if (! $implicitTls && $encryption === 'tls') {
            $transport->setRequireTls(true);
        }

        if (! empty($smtp['username'])) {
            $transport->setUsername($smtp['username']);
            $transport->setPassword($smtp['password'] ?? '');
        }

        return $transport;
    }
}
