<?php

namespace SmartImport\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SmartImport\Tests\TestCase;

class AssetBundleTest extends TestCase
{
    #[Test]
    public function compiled_hub_stylesheet_covers_package_utilities_without_preflight(): void
    {
        $path = dirname(__DIR__, 2).'/resources/dist/smart-import.css';

        $this->assertFileExists($path);

        $css = file_get_contents($path);
        $this->assertIsString($css);

        foreach (['.smart-import{', 'xl\\:grid-cols-6', 'max-w-sm', 'shadow-sm', 'dark\\:bg-white\\/5'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }

        $this->assertStringNotContainsString('@layer base', $css);
        $this->assertStringNotContainsString('prefers-color-scheme', $css);
    }
}
