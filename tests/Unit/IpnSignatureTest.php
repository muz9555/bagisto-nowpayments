<?php

use Webkul\NowPayments\Services\IpnSignature;

// ============================================================================
// Notification Signatures
// ============================================================================

it('verifies recursively sorted objects while preserving arrays unicode and slashes', function () {
    $payload = '{"z":{"z":"https://example.com/ä","a":{}},"items":[{"z":2,"a":1}],"a":5}';
    $canonical = '{"a":5,"items":[{"a":1,"z":2}],"z":{"a":{},"z":"https://example.com/ä"}}';
    $signature = hash_hmac('sha512', $canonical, 'secret');

    expect((new IpnSignature)->verify($payload, $signature, 'secret'))->toBeTrue();
});

it('rejects invalid missing or tampered signatures and malformed payloads', function ($payload, $signature, $secret) {
    expect((new IpnSignature)->verify($payload, $signature, $secret))->toBeFalse();
})->with([
    ['{}', null, 'secret'],
    ['{}', str_repeat('a', 128), ''],
    ['{}', str_repeat('a', 128), 'secret'],
    ['invalid', str_repeat('a', 128), 'secret'],
    ['[]', hash_hmac('sha512', '[]', 'secret'), 'secret'],
]);
