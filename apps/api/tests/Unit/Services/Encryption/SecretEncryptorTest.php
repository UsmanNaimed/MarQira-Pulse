<?php

use App\Services\Encryption\SecretEncryptor;

function testEncryptor(): SecretEncryptor
{
    // 32-byte key, base64-encoded.
    return new SecretEncryptor(base64_encode(str_repeat('marqiratestkey!!', 2)));
}

it('encrypts and decrypts back to the original plaintext', function () {
    $encryptor = testEncryptor();
    $plaintext = 'super-secret-site-value-123';

    $ciphertext = $encryptor->encrypt($plaintext);

    expect($ciphertext)->not->toBe($plaintext);
    expect($encryptor->decrypt($ciphertext))->toBe($plaintext);
});

it('produces different ciphertext for the same plaintext (random IV)', function () {
    $encryptor = testEncryptor();

    $a = $encryptor->encrypt('same');
    $b = $encryptor->encrypt('same');

    expect($a)->not->toBe($b);
    expect($encryptor->decrypt($a))->toBe('same');
    expect($encryptor->decrypt($b))->toBe('same');
});

it('throws when the ciphertext has been tampered with', function () {
    $encryptor = testEncryptor();
    $ciphertext = $encryptor->encrypt('tamper-me');

    $raw = base64_decode($ciphertext);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00";
    $tampered = base64_encode($raw);

    expect(fn () => $encryptor->decrypt($tampered))->toThrow(RuntimeException::class);
});

it('returns an 8-char hex key id', function () {
    $encryptor = testEncryptor();

    $keyId = $encryptor->keyId();

    expect($keyId)->toHaveLength(8);
    expect($keyId)->toMatch('/^[0-9a-f]{8}$/');
});

it('throws when the key is not 32 bytes', function () {
    expect(fn () => new SecretEncryptor(base64_encode('too-short')))
        ->toThrow(RuntimeException::class);
});
