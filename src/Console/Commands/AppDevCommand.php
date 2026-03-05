<?php

namespace LaravelShopify\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

class AppDevCommand extends Command
{
    protected $signature = 'shopify:app:dev
        {--tunnel=ngrok : Tunnel driver (ngrok or cloudflare)}
        {--port=8000 : Port for the Laravel dev server}
        {--vite-port=5173 : Port for the Vite dev server}
        {--no-tunnel : Skip tunnel creation}
        {--no-update : Skip updating app URLs in Partners Dashboard}';

    protected $description = 'Start a development environment with tunnel, app URL update, and Vite dev server';

    protected array $processes = [];

    public function handle(): int
    {
        $this->info('🚀 Starting Shopify App Development Environment...');
        $this->newLine();

        $port = (int) $this->option('port');
        $vitePort = (int) $this->option('vite-port');
        $tunnelDriver = $this->option('tunnel') ?: config('shopify-app.tunnel.driver', 'ngrok');

        // Step 1: Start tunnel
        $tunnelUrl = null;
        if (! $this->option('no-tunnel')) {
            $tunnelUrl = $this->startTunnel($tunnelDriver, $port);
            if (! $tunnelUrl) {
                $this->error('Failed to start tunnel. Aborting.');
                return self::FAILURE;
            }
            $this->info("✅ Tunnel URL: {$tunnelUrl}");
        }

        // Step 2: Update app URLs in Partners Dashboard
        if ($tunnelUrl && ! $this->option('no-update')) {
            $this->updateAppUrls($tunnelUrl);
        }

        // Step 3: Update .env with tunnel URL
        if ($tunnelUrl) {
            $this->updateEnvFile($tunnelUrl);
        }

        // Step 4: Start Laravel dev server
        $this->startLaravelServer($port);

        // Step 5: Start Vite dev server
        $this->startViteServer($vitePort);

        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info('  Development environment is running!');
        if ($tunnelUrl) {
            $this->info("  App URL:   {$tunnelUrl}");
        }
        $this->info("  Local:     http://localhost:{$port}");
        $this->info("  Vite:      http://localhost:{$vitePort}");
        $this->info('═══════════════════════════════════════════════');
        $this->info('  Press Ctrl+C to stop all services.');
        $this->newLine();

        // Wait for interrupt
        $this->waitForInterrupt();

        return self::SUCCESS;
    }

    protected function startTunnel(string $driver, int $port): ?string
    {
        $this->info("Starting {$driver} tunnel on port {$port}...");

        if ($driver === 'cloudflare') {
            return $this->startCloudflareTunnel($port);
        }

        return $this->startNgrokTunnel($port);
    }

    protected function startNgrokTunnel(int $port): ?string
    {
        $authToken = config('shopify-app.tunnel.ngrok_auth_token');

        $command = ['ngrok', 'http', (string) $port, '--log', 'stdout', '--log-format', 'json'];

        if ($authToken) {
            $command = array_merge($command, ['--authtoken', $authToken]);
        }

        $process = new SymfonyProcess($command);
        $process->setTimeout(null);
        $process->start();

        $this->processes[] = $process;

        // Wait for ngrok to report its URL
        $startTime = time();
        $url = null;

        while (time() - $startTime < 15) {
            $output = $process->getIncrementalOutput();
            if (preg_match('/"url"\s*:\s*"(https:\/\/[^"]+)"/', $output, $matches)) {
                $url = $matches[1];
                break;
            }

            if (! $process->isRunning()) {
                $this->error('ngrok process died: ' . $process->getErrorOutput());
                return null;
            }

            usleep(500_000);
        }

        if (! $url) {
            // Try the ngrok API as fallback
            usleep(2_000_000);
            try {
                $apiResponse = file_get_contents('http://localhost:4040/api/tunnels');
                $tunnels = json_decode($apiResponse, true);
                foreach ($tunnels['tunnels'] ?? [] as $tunnel) {
                    if (str_starts_with($tunnel['public_url'] ?? '', 'https://')) {
                        $url = $tunnel['public_url'];
                        break;
                    }
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        return $url;
    }

    protected function startCloudflareTunnel(int $port): ?string
    {
        $bin = config('shopify-app.tunnel.cloudflare_bin', 'cloudflared');

        $process = new SymfonyProcess([
            $bin, 'tunnel', '--url', "http://localhost:{$port}",
        ]);
        $process->setTimeout(null);
        $process->start();

        $this->processes[] = $process;

        // Wait for cloudflared to report its URL (it prints to stderr)
        $startTime = time();
        $url = null;

        while (time() - $startTime < 20) {
            $output = $process->getIncrementalErrorOutput() . $process->getIncrementalOutput();
            if (preg_match('/(https:\/\/[a-z0-9\-]+\.trycloudflare\.com)/', $output, $matches)) {
                $url = $matches[1];
                break;
            }

            if (! $process->isRunning()) {
                $this->error('cloudflared process died: ' . $process->getErrorOutput());
                return null;
            }

            usleep(500_000);
        }

        return $url;
    }

    protected function updateAppUrls(string $tunnelUrl): void
    {
        $autoUpdate = config('shopify-app.partners.auto_update', false);

        if (! $autoUpdate) {
            $this->warn('Auto-update of Partners Dashboard is disabled.');
            $this->info("Manually set your App URL to: {$tunnelUrl}");
            $this->info("Set Allowed redirection URL(s) to: {$tunnelUrl}/shopify/auth/callback");
            return;
        }

        $cliToken = config('shopify-app.partners.cli_token');
        $appId = config('shopify-app.partners.app_id');

        if (! $cliToken || ! $appId) {
            $this->warn('Missing SHOPIFY_CLI_TOKEN or SHOPIFY_APP_ID for auto-update.');
            return;
        }

        $this->info('Updating app URLs in Partners Dashboard...');

        // Use the Shopify Partners API to update the app
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            $response = $client->post('https://partners.shopify.com/api/2024-01/graphql.json', [
                'headers' => [
                    'Authorization' => "Bearer {$cliToken}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'query' => 'mutation appUpdate($apiKey: String!, $appUrl: Url!, $redirectUrlWhitelist: [Url!]!) {
                        appUpdate(input: { apiKey: $apiKey, applicationUrl: $appUrl, redirectUrlWhitelist: $redirectUrlWhitelist }) {
                            app { id }
                            userErrors { field message }
                        }
                    }',
                    'variables' => [
                        'apiKey' => config('shopify-app.api_key'),
                        'appUrl' => $tunnelUrl,
                        'redirectUrlWhitelist' => [
                            $tunnelUrl . '/shopify/auth/callback',
                        ],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $errors = $body['data']['appUpdate']['userErrors'] ?? [];

            if (! empty($errors)) {
                $this->warn('Partners API errors: ' . json_encode($errors));
            } else {
                $this->info('✅ App URLs updated in Partners Dashboard.');
            }
        } catch (\Exception $e) {
            $this->warn('Failed to update Partners Dashboard: ' . $e->getMessage());
        }
    }

    protected function updateEnvFile(string $tunnelUrl): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $contents = file_get_contents($envPath);

        // Update APP_URL
        if (preg_match('/^APP_URL=.*/m', $contents)) {
            $contents = preg_replace('/^APP_URL=.*/m', "APP_URL={$tunnelUrl}", $contents);
        }

        // Update SHOPIFY_APP_URL
        if (preg_match('/^SHOPIFY_APP_URL=.*/m', $contents)) {
            $contents = preg_replace('/^SHOPIFY_APP_URL=.*/m', "SHOPIFY_APP_URL={$tunnelUrl}", $contents);
        } else {
            $contents .= "\nSHOPIFY_APP_URL={$tunnelUrl}\n";
        }

        file_put_contents($envPath, $contents);
        $this->info('✅ .env updated with tunnel URL.');
    }

    protected function startLaravelServer(int $port): void
    {
        $this->info("Starting Laravel dev server on port {$port}...");

        $process = new SymfonyProcess([
            'php', 'artisan', 'serve', '--port=' . $port, '--host=0.0.0.0',
        ], base_path());
        $process->setTimeout(null);
        $process->start();

        $this->processes[] = $process;
    }

    protected function startViteServer(int $port): void
    {
        $this->info("Starting Vite dev server on port {$port}...");

        $packageManager = file_exists(base_path('yarn.lock')) ? 'yarn' : 'npm';
        $command = $packageManager === 'yarn'
            ? ['yarn', 'dev', '--port', (string) $port]
            : ['npx', 'vite', '--port', (string) $port];

        $process = new SymfonyProcess($command, base_path());
        $process->setTimeout(null);
        $process->start();

        $this->processes[] = $process;
    }

    protected function waitForInterrupt(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->cleanup();
                exit(0);
            });

            pcntl_signal(SIGTERM, function () {
                $this->cleanup();
                exit(0);
            });

            while (true) {
                pcntl_signal_dispatch();
                $allStopped = true;
                foreach ($this->processes as $process) {
                    if ($process->isRunning()) {
                        $allStopped = false;
                        break;
                    }
                }
                if ($allStopped) {
                    break;
                }
                usleep(500_000);
            }
        } else {
            // Fallback: just wait for all processes
            foreach ($this->processes as $process) {
                $process->wait();
            }
        }
    }

    protected function cleanup(): void
    {
        if ($this->output) {
            $this->newLine();
            $this->info('Shutting down...');
        }

        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(5);
            }
        }

        if ($this->output) {
            $this->info('All services stopped.');
        }
    }

    public function __destruct()
    {
        if (! empty($this->processes)) {
            $this->cleanup();
        }
    }
}
