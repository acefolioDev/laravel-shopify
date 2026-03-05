<?php

namespace LaravelShopify\Console\Commands;

use Illuminate\Console\Command;

class GenerateExtensionCommand extends Command
{
    protected $signature = 'shopify:generate:extension
        {name : The extension name (e.g. my-theme-block)}
        {--type=theme : Extension type (theme or ui)}
        {--force : Overwrite existing files}';

    protected $description = 'Scaffold files for Theme App Extensions or UI Extensions';

    public function handle(): int
    {
        $name = $this->argument('name');
        $type = $this->option('type');

        $this->info("Generating {$type} extension: {$name}");

        if ($type === 'theme') {
            return $this->generateThemeExtension($name);
        }

        if ($type === 'ui') {
            return $this->generateUiExtension($name);
        }

        $this->error("Unknown extension type: {$type}. Use 'theme' or 'ui'.");
        return self::FAILURE;
    }

    protected function generateThemeExtension(string $name): int
    {
        $basePath = base_path("extensions/{$name}");

        if (is_dir($basePath) && ! $this->option('force')) {
            $this->warn("Extension directory already exists: {$basePath}");
            if (! $this->confirm('Overwrite?', false)) {
                return self::SUCCESS;
            }
        }

        // Create directory structure
        $directories = [
            "{$basePath}/assets",
            "{$basePath}/blocks",
            "{$basePath}/snippets",
            "{$basePath}/locales",
        ];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Create shopify.extension.toml
        $this->createFile("{$basePath}/shopify.extension.toml", $this->getThemeExtensionToml($name));

        // Create a sample block
        $this->createFile("{$basePath}/blocks/app-block.liquid", $this->getThemeBlockLiquid($name));

        // Create a sample snippet
        $this->createFile("{$basePath}/snippets/app-snippet.liquid", $this->getThemeSnippetLiquid($name));

        // Create empty CSS asset
        $this->createFile("{$basePath}/assets/{$name}.css", $this->getThemeAssetCss($name));

        // Create locales
        $this->createFile("{$basePath}/locales/en.default.json", $this->getThemeLocalesJson($name));

        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info("  ✅ Theme App Extension created: {$name}");
        $this->info("  Path: extensions/{$name}/");
        $this->info('═══════════════════════════════════════════════');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function generateUiExtension(string $name): int
    {
        $basePath = base_path("extensions/{$name}");

        if (is_dir($basePath) && ! $this->option('force')) {
            $this->warn("Extension directory already exists: {$basePath}");
            if (! $this->confirm('Overwrite?', false)) {
                return self::SUCCESS;
            }
        }

        $directories = [
            "{$basePath}/src",
            "{$basePath}/locales",
        ];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Create shopify.extension.toml
        $this->createFile("{$basePath}/shopify.extension.toml", $this->getUiExtensionToml($name));

        // Create the main UI extension component
        $this->createFile("{$basePath}/src/index.jsx", $this->getUiExtensionJsx($name));

        // Create locales
        $this->createFile("{$basePath}/locales/en.default.json", $this->getUiLocalesJson($name));

        $this->newLine();
        $this->info('═══════════════════════════════════════════════');
        $this->info("  ✅ UI Extension created: {$name}");
        $this->info("  Path: extensions/{$name}/");
        $this->info('═══════════════════════════════════════════════');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function createFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $content);
        $this->line("  Created: " . str_replace(base_path() . '/', '', $path));
    }

    protected function getThemeExtensionToml(string $name): string
    {
        return <<<TOML
api_version = "2025-01"

[[extensions]]
name = "{$name}"
type = "theme"

  [[extensions.blocks]]
  name = "App Block"
  target = "section"
  template = "blocks/app-block.liquid"
TOML;
    }

    protected function getThemeBlockLiquid(string $name): string
    {
        return <<<'LIQUID'
{% comment %}
  App Block for theme app extension.
  This block can be added by merchants in the theme editor.
{% endcomment %}

{% schema %}
{
  "name": "App Block",
  "target": "section",
  "settings": [
    {
      "type": "text",
      "id": "heading",
      "label": "Heading",
      "default": "Hello from your app!"
    },
    {
      "type": "color",
      "id": "background_color",
      "label": "Background Color",
      "default": "#ffffff"
    }
  ]
}
{% endschema %}

<div class="app-block" style="background-color: {{ block.settings.background_color }};">
  <h2>{{ block.settings.heading }}</h2>
</div>
LIQUID;
    }

    protected function getThemeSnippetLiquid(string $name): string
    {
        return <<<LIQUID
{% comment %}
  Snippet for {$name} theme app extension.
  Include this snippet in your theme with: {% render '{$name}/app-snippet' %}
{% endcomment %}

<div class="{$name}-snippet">
  <!-- Your snippet content here -->
</div>
LIQUID;
    }

    protected function getThemeAssetCss(string $name): string
    {
        return <<<CSS
/* Styles for {$name} theme app extension */

.app-block {
  padding: 1rem;
  margin: 1rem 0;
}
CSS;
    }

    protected function getThemeLocalesJson(string $name): string
    {
        return json_encode([
            'name' => ucwords(str_replace('-', ' ', $name)),
            'description' => "Theme App Extension: {$name}",
            'blocks' => [
                'app_block' => [
                    'name' => 'App Block',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function getUiExtensionToml(string $name): string
    {
        return <<<TOML
api_version = "2025-01"

[[extensions]]
name = "{$name}"
type = "ui_extension"
handle = "{$name}"

  [extensions.capabilities]
  network_access = true
  block_progress = false

  [[extensions.targeting]]
  module = "./src/index.jsx"
  target = "admin.product-details.block.render"
TOML;
    }

    protected function getUiExtensionJsx(string $name): string
    {
        $componentName = str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));

        return <<<JSX
import {
  reactExtension,
  useApi,
  AdminBlock,
  BlockStack,
  Text,
  Button,
} from '@shopify/ui-extensions-react/admin';

const TARGET = 'admin.product-details.block.render';

export default reactExtension(TARGET, () => <{$componentName} />);

function {$componentName}() {
  const { extension } = useApi(TARGET);

  return (
    <AdminBlock title="{$name}">
      <BlockStack>
        <Text>Welcome to your UI Extension!</Text>
        <Button
          onPress={() => {
            console.log('{$name} button pressed');
          }}
        >
          Get Started
        </Button>
      </BlockStack>
    </AdminBlock>
  );
}
JSX;
    }

    protected function getUiLocalesJson(string $name): string
    {
        return json_encode([
            'name' => ucwords(str_replace('-', ' ', $name)),
            'description' => "UI Extension: {$name}",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
