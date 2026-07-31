<?php

namespace Tests\Unit;

use App\Http\Concerns\NormalizesFileMetadata;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NormalizesFileMetadataTest extends TestCase
{
    use NormalizesFileMetadata;

    public function test_blank_string_returns_null(): void
    {
        $this->assertNull($this->fileMetadata(''));
    }

    public function test_null_returns_null(): void
    {
        $this->assertNull($this->fileMetadata(null));
    }

    public function test_empty_array_returns_null(): void
    {
        $this->assertNull($this->fileMetadata([]));
    }

    public function test_associative_array_passthrough(): void
    {
        $input = ['channel' => 'beta'];
        $this->assertSame($input, $this->fileMetadata($input));
    }

    public function test_empty_json_object_string_is_accepted(): void
    {
        $this->assertSame([], $this->fileMetadata('{}'));
    }

    public function test_json_object_string_is_decoded(): void
    {
        $this->assertSame(
            ['channel' => 'beta'],
            $this->fileMetadata('{"channel":"beta"}'),
        );
    }

    public function test_json_list_string_is_rejected(): void
    {
        try {
            $this->fileMetadata('[1,2]');
            $this->fail('Expected ValidationException for JSON list string.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['metadata' => ['Metadata must be a JSON object.']],
                $e->errors(),
            );
        }
    }

    public function test_decoded_php_list_is_rejected(): void
    {
        try {
            $this->fileMetadata([1, 2]);
            $this->fail('Expected ValidationException for decoded list.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['metadata' => ['Metadata must be a JSON object.']],
                $e->errors(),
            );
        }
    }

    public function test_non_object_scalar_string_is_rejected(): void
    {
        try {
            $this->fileMetadata('"just-a-string"');
            $this->fail('Expected ValidationException for scalar string JSON.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['metadata' => ['Metadata must be a JSON object.']],
                $e->errors(),
            );
        }
    }

    public function test_non_object_scalar_number_is_rejected(): void
    {
        try {
            $this->fileMetadata(42);
            $this->fail('Expected ValidationException for scalar number.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['metadata' => ['Metadata must be a JSON object.']],
                $e->errors(),
            );
        }
    }

    public function test_malformed_json_is_rejected(): void
    {
        try {
            $this->fileMetadata('{not json');
            $this->fail('Expected ValidationException for malformed JSON.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['metadata' => ['Metadata must be a JSON object.']],
                $e->errors(),
            );
        }
    }
}
