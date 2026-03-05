<?php

namespace LaravelShopify\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AppDeployCommand extends Command
{
    protected $signature = 'shopify:app:deploy
        {--skip-build : Skip the frontend build step}
        {--skip-optimize : Skip Laravel optimization}';

    protected $description = 'Bundle assets and prepare the Shopify app for production deployment';

    public function handle(): int
    {
        $this->info('🚀 Preparing Shopify App for Production...');
        $this->newLine();

        // Step 1: Validate environment
        if (! $this->validateEnvironment()) {
            return self::FAILURE;
        }

        // Step 2: Build frontend assets
        if (! $this->option('skip-build')) {
            if (! $this->buildFrontend()) {
                return self::FAILURE;
            }
        }

        // Step 3: Optimize Laravel
        if (! $this->option('skip-optimize')) {
            $this->optimizeLaravel();
        }

        // Step 4: Register webhooks
        $this->registerWebhooks();

        // Step 5: Run migrations
        if ($this->confirm('Run database migrations?', true)) {
            $this->call('migrate', ['--force' => true]);
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info('  ✅ Shopify App is ready for production!');
        $this->info('═══════════════════════════════════════════════');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function validateEnvironment(): bool
    {
        $this->info('Validating environment...');

        $required = [
            'SHOPIFY_API_KEY' => config('shopify-app.api_key'),
            'SHOPIFY_API_SECRET' => config('shopify-app.api_secret'),
            'SHOPIFY_APP_URL' => config('shopify-app.app_url'),
        ];

        $missing = [];
        foreach ($required as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }

        if (! empty($missing)) {
            $this->error('Missing required environment variables:');
            foreach ($missing as $key) {
                $this->line("  - {$key}");
            }
            return false;
        }

        $this->info('✅ Environment validated.');
        return true;
    }

    protected function buildFrontend(): bool
    {
        $this->info('Building frontend assets...');

        $packageManager = file_exists(base_path('yarn.lock')) ? 'yarn' : 'npm';
        $command = $packageManager === 'yarn'
            ? ['yarn', 'build']
            : ['npm', 'run', 'build'];

        $process = new Process($command, base_path());
        $process->setTimeout(300);

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('Frontend build failed!');
            $this->error($process->getErrorOutput());
            return false;
        }

        $this->info('✅ Frontend assets built.');
        return true;
    }

    protected function optimizeLaravel(): void
    {
        $this->info('Optimizing Laravel...');

        $this->call('config:cache');
        $this->call('route:cache');
        $this->call('view:cache');

        $this->info('✅ Laravel optimized.');
    }

    protected function registerWebhooks(): void
    {
        $webhooks = config('shopify-app.webhooks', []);

        if (empty($webhooks)) {
            $this->info('No webhooks configured to register.');
            return;
        }

        $this->info('Webhooks configured (' . count($webhooks) . ' topics):');
        foreach ($webhooks as $topic => $jobClass) {
            $this->line("  - {$topic} → {$jobClass}");
        }

        $this->info('Webhooks will be registered per-shop during token exchange.');
    }
}
