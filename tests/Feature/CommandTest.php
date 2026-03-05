<?php

namespace LaravelShopify\Tests\Feature;

use LaravelShopify\Tests\TestCase;

class CommandTest extends TestCase
{
    public function test_generate_webhook_command_creates_job(): void
    {
        $jobPath = app_path('Jobs/Shopify/ProductsUpdateJob.php');

        // Clean up if exists from prior run
        if (file_exists($jobPath)) {
            unlink($jobPath);
        }

        $this->artisan('shopify:generate:webhook', ['topic' => 'PRODUCTS_UPDATE', '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists($jobPath);

        $content = file_get_contents($jobPath);
        $this->assertStringContainsString('class ProductsUpdateJob', $content);
        $this->assertStringContainsString('ShouldQueue', $content);
        $this->assertStringContainsString('$shopDomain', $content);

        // Cleanup
        unlink($jobPath);
        @rmdir(app_path('Jobs/Shopify'));
        @rmdir(app_path('Jobs'));
    }

    public function test_generate_theme_extension_creates_files(): void
    {
        $extPath = base_path('extensions/test-block');

        // Clean up if exists
        if (is_dir($extPath)) {
            $this->deleteDirectory($extPath);
        }

        $this->artisan('shopify:generate:extension', [
            'name' => 'test-block',
            '--type' => 'theme',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDirectoryExists($extPath);
        $this->assertFileExists($extPath . '/shopify.extension.toml');
        $this->assertFileExists($extPath . '/blocks/app-block.liquid');
        $this->assertFileExists($extPath . '/snippets/app-snippet.liquid');
        $this->assertFileExists($extPath . '/assets/test-block.css');
        $this->assertFileExists($extPath . '/locales/en.default.json');

        $toml = file_get_contents($extPath . '/shopify.extension.toml');
        $this->assertStringContainsString('test-block', $toml);
        $this->assertStringContainsString('type = "theme"', $toml);

        // Cleanup
        $this->deleteDirectory($extPath);
        @rmdir(base_path('extensions'));
    }

    public function test_generate_ui_extension_creates_files(): void
    {
        $extPath = base_path('extensions/test-ui-block');

        if (is_dir($extPath)) {
            $this->deleteDirectory($extPath);
        }

        $this->artisan('shopify:generate:extension', [
            'name' => 'test-ui-block',
            '--type' => 'ui',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDirectoryExists($extPath);
        $this->assertFileExists($extPath . '/shopify.extension.toml');
        $this->assertFileExists($extPath . '/src/index.jsx');
        $this->assertFileExists($extPath . '/locales/en.default.json');

        $toml = file_get_contents($extPath . '/shopify.extension.toml');
        $this->assertStringContainsString('test-ui-block', $toml);
        $this->assertStringContainsString('type = "ui_extension"', $toml);

        $jsx = file_get_contents($extPath . '/src/index.jsx');
        $this->assertStringContainsString('reactExtension', $jsx);

        // Cleanup
        $this->deleteDirectory($extPath);
        @rmdir(base_path('extensions'));
    }

    public function test_deploy_command_validates_environment(): void
    {
        // With valid env, it should pass validation step
        $this->artisan('shopify:app:deploy', ['--skip-build' => true, '--skip-optimize' => true])
            ->expectsConfirmation('Run database migrations?', 'no')
            ->assertSuccessful();
    }

    public function test_deploy_command_fails_with_missing_env(): void
    {
        config()->set('shopify-app.api_key', '');
        config()->set('shopify-app.api_secret', '');

        $this->artisan('shopify:app:deploy', ['--skip-build' => true, '--skip-optimize' => true])
            ->assertFailed();
    }

    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }
}
