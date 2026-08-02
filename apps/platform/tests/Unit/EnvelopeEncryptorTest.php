<?php

namespace Tests\Unit;

use App\Support\Encryption\EnvelopeEncryptor;
use Tests\TestCase;

class EnvelopeEncryptorTest extends TestCase
{
    public function test_encrypt_and_decrypt_round_trip(): void
    {
        $dek = EnvelopeEncryptor::generateDEK();
        $keyId = 'test-key-id';
        $plaintext = 'super-secret-credential-data';

        $encrypted = EnvelopeEncryptor::encrypt($plaintext, $dek, $keyId);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertTrue(EnvelopeEncryptor::isEnvelopeEncrypted($encrypted));
        $this->assertSame($keyId, EnvelopeEncryptor::extractKeyId($encrypted));

        $decrypted = EnvelopeEncryptor::decrypt($encrypted, $dek);
        $this->assertSame($plaintext, $decrypted);
    }

    public function test_encrypt_produces_different_ciphertext_each_time(): void
    {
        $dek = EnvelopeEncryptor::generateDEK();
        $plaintext = 'same-input';

        $a = EnvelopeEncryptor::encrypt($plaintext, $dek, 'k1');
        $b = EnvelopeEncryptor::encrypt($plaintext, $dek, 'k1');

        $this->assertNotSame($a, $b);
        $this->assertSame($plaintext, EnvelopeEncryptor::decrypt($a, $dek));
        $this->assertSame($plaintext, EnvelopeEncryptor::decrypt($b, $dek));
    }

    public function test_decrypt_fails_with_wrong_key(): void
    {
        $dek1 = EnvelopeEncryptor::generateDEK();
        $dek2 = EnvelopeEncryptor::generateDEK();

        $encrypted = EnvelopeEncryptor::encrypt('secret', $dek1, 'k1');

        $this->expectException(\RuntimeException::class);
        EnvelopeEncryptor::decrypt($encrypted, $dek2);
    }

    public function test_is_envelope_encrypted_returns_false_for_legacy_format(): void
    {
        $this->assertFalse(EnvelopeEncryptor::isEnvelopeEncrypted('random-string'));
        $this->assertFalse(EnvelopeEncryptor::isEnvelopeEncrypted('{"v":1,"data":"x"}'));
        $this->assertFalse(EnvelopeEncryptor::isEnvelopeEncrypted(''));
    }

    public function test_extract_key_id_returns_null_for_non_envelope(): void
    {
        $this->assertNull(EnvelopeEncryptor::extractKeyId('not-json'));
        $this->assertNull(EnvelopeEncryptor::extractKeyId('{"v":1}'));
    }

    public function test_generate_dek_produces_32_bytes(): void
    {
        $dek = EnvelopeEncryptor::generateDEK();
        $this->assertSame(32, strlen($dek));
    }

    public function test_encrypt_handles_binary_data(): void
    {
        $dek = EnvelopeEncryptor::generateDEK();
        $binary = random_bytes(256);

        $encrypted = EnvelopeEncryptor::encrypt($binary, $dek, 'k1');
        $decrypted = EnvelopeEncryptor::decrypt($encrypted, $dek);

        $this->assertSame($binary, $decrypted);
    }

    public function test_encrypt_handles_unicode(): void
    {
        $dek = EnvelopeEncryptor::generateDEK();
        $unicode = '{"emoji":"🔐","cyrillic":"привет"}';

        $encrypted = EnvelopeEncryptor::encrypt($unicode, $dek, 'k1');
        $decrypted = EnvelopeEncryptor::decrypt($encrypted, $dek);

        $this->assertSame($unicode, $decrypted);
    }
}
