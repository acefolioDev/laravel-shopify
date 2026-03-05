<?php

namespace LaravelShopify\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateWebhookCommand extends Command
{
    protected $signature = 'shopify:generate:webhook
        {topic : The webhook topic (e.g. PRODUCTS_UPDATE, APP_UNINSTALLED)}
        {--force : Overwrite existing files}';

    protected $description = 'Scaffold a Webhook Job and register the topic in the config';

    public function handle(): int
    {
        $topic = strtoupper($this->argument('topic'));
        $className = $this->topicToClassName($topic);

        $this->info("Generating webhook handler for: {$topic}");

        // Step 1: Create the Job class
        $jobPath = app_path("Jobs/Shopify/{$className}.php");

        if (file_exists($jobPath) && ! $this->option('force')) {
            $this->warn("Job already exists: {$jobPath}");
            if (! $this->confirm('Overwrite?', false)) {
                return self::SUCCESS;
            }
        }

        $this->createJobFile($jobPath, $className, $topic);
        $this->info("✅ Created: {$jobPath}");

        // Step 2: Register in config
        $this->registerInConfig($topic, "App\\Jobs\\Shopify\\{$className}");

        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info("  Webhook handler generated for: {$topic}");
        $this->info("  Job class: App\\Jobs\\Shopify\\{$className}");
        $this->info('═══════════════════════════════════════════════');
        $this->newLine();
        $this->warn('Remember to add this to your shopify-app.php config if not auto-registered:');
        $this->line("  '{$topic}' => \\App\\Jobs\\Shopify\\{$className}::class,");

        return self::SUCCESS;
    }

    protected function topicToClassName(string $topic): string
    {
        // PRODUCTS_UPDATE -> ProductsUpdateJob
        $parts = explode('_', strtolower($topic));
        $name = implode('', array_map('ucfirst', $parts));

        return $name . 'Job';
    }

    protected function createJobFile(string $path, string $className, string $topic): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $stubPath = __DIR__ . '/../../../stubs/webhook-job.stub';

        if (file_exists($stubPath)) {
            $stub = file_get_contents($stubPath);
        } else {
            $stub = $this->getDefaultStub();
        }

        $content = str_replace(
            ['{{ className }}', '{{ topic }}'],
            [$className, $topic],
            $stub
        );

        file_put_contents($path, $content);
    }

    protected function registerInConfig(string $topic, string $jobClass): void
    {
        $configPath = config_path('shopify-app.php');

        if (! file_exists($configPath)) {
            $this->warn('Config file not found at: ' . $configPath);
            $this->warn('Please publish the config: php artisan vendor:publish --tag=shopify-config');
            return;
        }

        $contents = file_get_contents($configPath);

        // Check if topic already registered
        if (str_contains($contents, "'{$topic}'")) {
            $this->info("Topic '{$topic}' already exists in config.");
            return;
        }

        // Find the webhooks array and add the entry
        $search = "'webhooks' => [";
        $replacement = "'webhooks' => [\n        '{$topic}' => \\{$jobClass}::class,";

        if (str_contains($contents, $search)) {
            $contents = str_replace($search, $replacement, $contents);
            file_put_contents($configPath, $contents);
            $this->info("✅ Registered '{$topic}' in config/shopify-app.php");
        } else {
            $this->warn('Could not auto-register webhook in config. Please add manually.');
        }
    }

    protected function getDefaultStub(): string
    {
        return <<<'STUB'
<?php

namespace App\Jobs\Shopify;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class {{ className }} implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $shopDomain;
    public array $data;
    public string $topic;
    public string $apiVersion;

    public function __construct(string $shopDomain, array $data, string $topic, string $apiVersion)
    {
        $this->shopDomain = $shopDomain;
        $this->data = $data;
        $this->topic = $topic;
        $this->apiVersion = $apiVersion;
    }

    public function handle(): void
    {
        Log::info("Shopify webhook: {{ topic }}", [
            'shop' => $this->shopDomain,
            'data_id' => $this->data['id'] ?? null,
        ]);

        // TODO: Implement your webhook handling logic here
    }
}
STUB;
    }
}
