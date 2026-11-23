<?php

namespace SmartImport\Tests\Feature;

use DomainException;
use PHPUnit\Framework\Attributes\Test;
use SmartImport\Models\ImportSession;
use SmartImport\Contracts\ImportExecutionContext;
use SmartImport\Services\ImportIndexHasher;
use SmartImport\Services\ImportPayloadCodec;
use SmartImport\Services\NoTenantImportExecutionContext;
use SmartImport\Tests\TestCase;

class ImportSecurityBoundaryTest extends TestCase
{
    #[Test]
    public function sensitive_cells_are_encrypted_and_integer_source_positions_round_trip(): void
    {
        $codec = app(ImportPayloadCodec::class);
        $values = [1 => 'public cell', 2 => 'PRIVATE-SECRET-CELL', 3 => null];
        $stored = $codec->protect($values, [2]);

        $this->assertSame(1, $stored['__smart_import_payload_version']);
        $this->assertStringNotContainsString('PRIVATE-SECRET-CELL', json_encode($stored, JSON_THROW_ON_ERROR));
        $this->assertSame($values, $codec->reveal($stored));
    }

    #[Test]
    public function legacy_plaintext_cells_remain_readable_without_rewriting_them(): void
    {
        $legacy = ['name' => 'Ada', 'secret' => 'LEGACY-PLAINTEXT'];

        $this->assertSame($legacy, app(ImportPayloadCodec::class)->reveal($legacy));
        $this->assertSame(['name' => 'Ada'], app(ImportPayloadCodec::class)->reveal($legacy, ['name']));
        $this->assertSame([], app(ImportPayloadCodec::class)->reveal($legacy, []));
        $this->assertSame(['name', 'secret'], app(ImportPayloadCodec::class)->keys($legacy));
    }

    #[Test]
    public function payload_key_order_is_preserved_for_mixed_sensitive_and_plain_cells(): void
    {
        $codec = app(ImportPayloadCodec::class);
        $stored = $codec->protect([
            'token' => 'PRIVATE-TOKEN',
            'name' => 'Ada',
            'note' => 'visible note',
            'email' => 'ada@example.test',
        ], ['token']);

        $this->assertSame(['token', 'name', 'note', 'email'], $codec->keys($stored));
        $this->assertSame(['token', 'name', 'note', 'email'], array_keys($codec->reveal($stored)));
    }

    #[Test]
    public function payload_key_order_preserves_integer_source_positions(): void
    {
        $codec = app(ImportPayloadCodec::class);
        $stored = $codec->protect([1 => 'name', 2 => 'PRIVATE-TOKEN', 3 => 'note'], [2]);

        $this->assertSame([1, 2, 3], $codec->keys($stored));
        $this->assertSame([1, 2, 3], array_keys($codec->reveal($stored)));
    }

    #[Test]
    public function payload_key_order_fails_closed_for_unsupported_versions(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The encrypted staged values use an unsupported payload version.');
        app(ImportPayloadCodec::class)->keys([
            '__smart_import_payload_version' => 99,
            'order' => [],
            'plain' => [],
            'encrypted' => [],
        ]);
    }

    #[Test]
    public function payload_key_order_fails_closed_for_invalid_entries(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The encrypted staged values have an invalid field order.');
        app(ImportPayloadCodec::class)->keys([
            '__smart_import_payload_version' => 1,
            'order' => [['type' => 'unsupported', 'key' => 'name']],
            'plain' => ['name' => 'Ada'],
            'encrypted' => [],
        ]);
    }

    #[Test]
    public function selective_envelope_reads_return_only_requested_plaintext_or_encrypted_keys(): void
    {
        $codec = app(ImportPayloadCodec::class);
        $stored = $codec->protect([
            'name' => 'Ada',
            'internal_note' => 'must not escape',
            'token' => 'sensitive token',
        ], ['token']);

        $this->assertSame(['name' => 'Ada'], $codec->reveal($stored, ['name']));
        $this->assertSame(['token' => 'sensitive token'], $codec->reveal($stored, ['token']));
        $this->assertSame([], $codec->reveal($stored, []));
        $this->assertSame([], $codec->reveal($stored, ['missing']));
    }

    #[Test]
    public function selective_source_cell_reads_preserve_integer_positions(): void
    {
        $codec = app(ImportPayloadCodec::class);
        $stored = $codec->protect([1 => 'name', 2 => 'secret', 3 => 'note'], [2]);

        $this->assertSame([2 => 'secret'], $codec->reveal($stored, [2]));
        $this->assertSame([1 => 'name'], $codec->reveal($stored, [1]));
    }

    #[Test]
    public function selective_read_does_not_decrypt_an_excluded_corrupt_value(): void
    {
        $stored = [
            '__smart_import_payload_version' => 1,
            'order' => [
                ['type' => 'string', 'key' => 'name'],
                ['type' => 'string', 'key' => 'token'],
            ],
            'plain' => ['name' => 'Ada'],
            'encrypted' => [[
                'key_type' => 'string',
                'key' => 'token',
                'value' => 'corrupt-ciphertext',
            ]],
        ];

        $this->assertSame(['name' => 'Ada'], app(ImportPayloadCodec::class)->reveal($stored, ['name']));
    }

    #[Test]
    public function selective_read_still_fails_closed_for_an_included_corrupt_value(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A sensitive staged value could not be decrypted.');
        app(ImportPayloadCodec::class)->reveal([
            '__smart_import_payload_version' => 1,
            'order' => [['type' => 'string', 'key' => 'token']],
            'plain' => [],
            'encrypted' => [[
                'key_type' => 'string',
                'key' => 'token',
                'value' => 'corrupt-ciphertext',
            ]],
        ], ['token']);
    }

    #[Test]
    public function unsupported_or_undecryptable_sensitive_payloads_fail_closed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The encrypted staged values use an unsupported payload version.');
        app(ImportPayloadCodec::class)->reveal(['__smart_import_payload_version' => 99, 'order' => [], 'plain' => [], 'encrypted' => []]);
    }

    #[Test]
    public function invalid_ciphertext_is_not_returned_as_plaintext(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A sensitive staged value could not be decrypted.');
        app(ImportPayloadCodec::class)->reveal([
            '__smart_import_payload_version' => 1,
            'order' => [['type' => 'string', 'key' => 'token']],
            'plain' => [],
            'encrypted' => [['key_type' => 'string', 'key' => 'token', 'value' => 'corrupt-ciphertext']],
        ]);
    }

    #[Test]
    public function tenant_bound_work_fails_closed_without_a_host_execution_context(): void
    {
        $session = new ImportSession();
        $session->forceFill(['tenant_id' => 'tenant-a']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A tenant-aware Smart Import execution context must be configured');
        (new NoTenantImportExecutionContext())->run($session, static fn (): null => null);
    }

    #[Test]
    public function indexed_signatures_are_keyed_for_new_staging_and_legacy_signatures_remain_supported(): void
    {
        $hasher = app(ImportIndexHasher::class);

        $this->assertSame(hash('sha256', 'low-entropy-email@example.test'), $hasher->hash('low-entropy-email@example.test', 1));
        $this->assertNotSame(hash('sha256', 'low-entropy-email@example.test'), $hasher->hash('low-entropy-email@example.test', 2));
        $this->assertSame($hasher->hash('low-entropy-email@example.test', 2), $hasher->hash('low-entropy-email@example.test', 2));
    }

    #[Test]
    public function configured_context_restores_ambient_state_after_success_and_exception(): void
    {
        $session = new ImportSession();
        $session->forceFill(['tenant_id' => 'tenant-b']);
        RestoringImportExecutionContext::$ambient = 'outer-context';
        $context = new RestoringImportExecutionContext();

        $this->assertSame('tenant-b', $context->run($session, static fn (): ?string => RestoringImportExecutionContext::$ambient));
        $this->assertSame('outer-context', RestoringImportExecutionContext::$ambient);

        try {
            $context->run($session, static function (): void {
                throw new \RuntimeException('fixture failure');
            });
            $this->fail('The fixture exception should be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fixture failure', $exception->getMessage());
        }

        $this->assertSame('outer-context', RestoringImportExecutionContext::$ambient);
    }
}

final class RestoringImportExecutionContext implements ImportExecutionContext
{
    public static ?string $ambient = null;

    public function run(ImportSession $session, callable $callback): mixed
    {
        $previous = self::$ambient;
        self::$ambient = $session->tenant_id;

        try {
            return $callback();
        } finally {
            self::$ambient = $previous;
        }
    }
}
