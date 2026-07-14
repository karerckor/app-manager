<?php

namespace Tests\Unit;

use App\Enums\CredentialType;
use App\Rules\ValidCredentialFile;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ValidCredentialFileTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workdir = sys_get_temp_dir().'/p12test-'.uniqid();
        mkdir($this->workdir);
        $this->runCommand(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $this->path('key.pem'), '-out', $this->path('cert.pem'),
            '-days', '1', '-subj', '/CN=Test Signing']);
        // modern (OpenSSL 3 default) and legacy-encrypted p12, same password
        $this->runCommand(['openssl', 'pkcs12', '-export', '-inkey', $this->path('key.pem'),
            '-in', $this->path('cert.pem'), '-out', $this->path('modern.p12'),
            '-passout', 'pass:secret', '-name', 'test']);
        $this->runCommand(['openssl', 'pkcs12', '-export', '-legacy', '-inkey', $this->path('key.pem'),
            '-in', $this->path('cert.pem'), '-out', $this->path('legacy.p12'),
            '-passout', 'pass:secret', '-name', 'test']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->workdir.'/*'));
        rmdir($this->workdir);
        parent::tearDown();
    }

    public function test_accepts_a_modern_pkcs12_with_correct_password(): void
    {
        $this->assertSame([], $this->errorsFor('modern.p12', 'secret'));
    }

    public function test_accepts_a_legacy_encrypted_pkcs12_with_correct_password(): void
    {
        $this->assertSame([], $this->errorsFor('legacy.p12', 'secret'));
    }

    public function test_rejects_a_legacy_pkcs12_with_the_wrong_password(): void
    {
        $this->assertNotEmpty($this->errorsFor('legacy.p12', 'wrong'));
    }

    public function test_rejects_a_file_that_is_not_a_pkcs12(): void
    {
        file_put_contents($this->path('bogus.p12'), 'not a certificate');
        $this->assertNotEmpty($this->errorsFor('bogus.p12', 'secret'));
    }

    private function errorsFor(string $file, string $password): array
    {
        $rule = new ValidCredentialFile(CredentialType::IosCertificate, ['password' => $password]);
        $upload = new UploadedFile($this->path($file), $file, 'application/x-pkcs12', null, true);
        $errors = [];
        $rule->validate('file', $upload, function ($message) use (&$errors) {
            $errors[] = $message;
        });

        return $errors;
    }

    private function runCommand(array $command): void
    {
        $process = new Process($command);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->markTestSkipped('openssl CLI unavailable: '.$process->getErrorOutput());
        }
    }

    private function path(string $name): string
    {
        return $this->workdir.'/'.$name;
    }
}
