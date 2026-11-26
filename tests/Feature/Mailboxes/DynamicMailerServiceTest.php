<?php

use App\Services\DynamicMailerService;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/*
 * Symfony's EsmtpTransport `tls: true` means implicit TLS (port 465). STARTTLS
 * on 587 needs `tls: false` + requireTls. These assert the mapping without
 * opening a connection.
 */

function smtpTransport(array $smtp)
{
    return app(DynamicMailerService::class)->transportFromSmtpConfig([
        'host' => 'smtp.gmail.com',
        'username' => 'support@example.com',
        'password' => 'app-password',
        ...$smtp,
    ]);
}

test('"tls" on 587 connects in plain text and requires STARTTLS', function () {
    $transport = smtpTransport(['port' => '587', 'encryption' => 'tls']);

    /** @var SocketStream $stream */
    $stream = $transport->getStream();

    expect($stream->isTLS())->toBeFalse()
        ->and($stream->getPort())->toBe(587)
        ->and($transport->isAutoTls())->toBeTrue()
        ->and($transport->isTlsRequired())->toBeTrue();
});

test('"ssl" uses implicit TLS', function () {
    $transport = smtpTransport(['port' => '465', 'encryption' => 'ssl']);

    expect($transport->getStream()->isTLS())->toBeTrue()
        ->and($transport->getStream()->getPort())->toBe(465);
});

test('port 465 uses implicit TLS even when "tls" is selected', function () {
    $transport = smtpTransport(['port' => 465, 'encryption' => 'tls']);

    expect($transport->getStream()->isTLS())->toBeTrue();
});

test('"none" does not force TLS', function () {
    $transport = smtpTransport(['port' => '25', 'encryption' => 'none']);

    expect($transport->getStream()->isTLS())->toBeFalse()
        ->and($transport->isTlsRequired())->toBeFalse();
});

test('missing encryption defaults to STARTTLS on 587', function () {
    $transport = smtpTransport(['port' => null, 'encryption' => null]);

    expect($transport->getStream()->isTLS())->toBeFalse()
        ->and($transport->getStream()->getPort())->toBe(587)
        ->and($transport->isTlsRequired())->toBeTrue();
});

test('credentials are applied to the transport', function () {
    $transport = smtpTransport(['port' => '587', 'encryption' => 'tls']);

    expect($transport->getUsername())->toBe('support@example.com')
        ->and($transport->getPassword())->toBe('app-password');
});
