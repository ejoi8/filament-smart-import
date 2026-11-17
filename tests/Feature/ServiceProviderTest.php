<?php

namespace SmartImport\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SmartImport\Services\ActorOwnedImportPresetScope;
use SmartImport\Services\ColumnMapper;
use SmartImport\Services\FileIntakeService;
use SmartImport\Services\MappingPresetService;
use SmartImport\Services\SpreadsheetReader;
use SmartImport\Contracts\ImportPresetScope;
use SmartImport\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    #[Test]
    public function package_defaults_are_loaded(): void
    {
        $this->assertSame('local', config('smart-import.storage.disk'));
        $this->assertSame('smart-import', config('smart-import.storage.root'));
        $this->assertSame(10 * 1024 * 1024, config('smart-import.uploads.max_file_size_bytes'));
        $this->assertSame(20, config('smart-import.uploads.max_files_per_actor_per_hour'));
        $this->assertSame(250, config('smart-import.reading.chunk_size'));
        $this->assertSame(100000, config('smart-import.reading.max_rows'));
        $this->assertSame(256, config('smart-import.reading.max_columns'));
        $this->assertSame(20, config('smart-import.reading.max_sheets'));
        $this->assertSame(32 * 1024 * 1024, config('smart-import.reading.max_uncompressed_bytes'));
        $this->assertSame(20, config('smart-import.reading.preview_rows'));
        $this->assertSame(100, config('smart-import.reading.max_preview_rows'));
        $this->assertSame('imports', config('smart-import.queue.name'));
        $this->assertSame(100, config('smart-import.execution.chunk_size'));
        $this->assertSame(2, config('smart-import.progress.polling_interval_seconds'));
        $this->assertSame([], config('smart-import.filament.panels'));
        $this->assertSame(ActorOwnedImportPresetScope::class, config('smart-import.authorization.preset_scope'));
        $this->assertInstanceOf(FileIntakeService::class, $this->app->make(FileIntakeService::class));
        $this->assertInstanceOf(SpreadsheetReader::class, $this->app->make(SpreadsheetReader::class));
        $this->assertInstanceOf(ColumnMapper::class, $this->app->make(ColumnMapper::class));
        $this->assertInstanceOf(ImportPresetScope::class, $this->app->make(ImportPresetScope::class));
        $this->assertInstanceOf(MappingPresetService::class, $this->app->make(MappingPresetService::class));
    }
}
