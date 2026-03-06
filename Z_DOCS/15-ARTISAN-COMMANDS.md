# 15 - Artisan Commands

The package provides 4 Artisan commands for development, deployment, and code generation.

**Source files:** `src/Console/Commands/`

---

## 1. `shopify:app:dev` — Development Environment

**File:** `src/Console/Commands/AppDevCommand.php`

Starts a complete development environment in a single command — mirroring what `shopify app dev` does in the Node CLI.

### Usage

```bash
php artisan shopify:app:dev

# Options
php artisan shopify:app:dev --tunnel=cloudflare
php artisan shopify:app:dev --port=8000 --vite-port=5173
php artisan shopify:app:dev --no-tunnel
php artisan shopify:app:dev --no-update
```

### Options

| Option | Default | Description |
|---|---|---|
| `--tunnel` | `ngrok` | Tunnel driver: `ngrok` or `cloudflare` |
| `--port` | `8000` | Laravel dev server port |
| `--vite-port` | `5173` | Vite dev server port |
| `--no-tunnel` | false | Skip tunnel creation |
| `--no-update` | false | Skip updating Partners Dashboard URLs |

### What It Does (5 Steps)

**Step 1: Start Tunnel**

Starts either ngrok or Cloudflare tunnel:

- **ngrok:** Runs `ngrok http {port} --log stdout --log-format json`. Parses the HTTPS URL from JSON log output. Falls back to the ngrok local API (`http://localhost:4040/api/tunnels`) if log parsing fails.
- **cloudflare:** Runs `cloudflared tunnel --url http://localhost:{port}`. Parses the `.trycloudflare.com` URL from stderr output.

Both run as background processes with no timeout.

**Step 2: Update App URLs in Partners Dashboard**

If `config('shopify-app.partners.auto_update')` is `true`:
- Uses the Partners GraphQL API to update the app's URL and redirect whitelist
- Requires `SHOPIFY_CLI_TOKEN` and `SHOPIFY_APP_ID` in config
- Sends an `appUpdate` mutation to `partners.shopify.com/api/2024-01/graphql.json`

If auto-update is disabled, prints the URLs for you to set manually.

**Step 3: Update `.env` File**

Updates `APP_URL` and `SHOPIFY_APP_URL` in your `.env` file with the tunnel URL. Creates `SHOPIFY_APP_URL` if it doesn't exist.

**Step 4: Start Laravel Dev Server**

Runs `php artisan serve --port={port} --host=0.0.0.0` as a background process.

**Step 5: Start Vite Dev Server**

Detects `yarn.lock` vs `npm`:
- Yarn: `yarn dev --port {vitePort}`
- npm: `npx vite --port {vitePort}`

### Signal Handling

The command handles `SIGINT` (Ctrl+C) and `SIGTERM` gracefully:
1. Stops all child processes (tunnel, Laravel server, Vite)
2. Gives each process 5 seconds to stop before force-killing
3. Prints "All services stopped."

The `__destruct()` method also calls cleanup as a safety net.

### Output

```
🚀 Starting Shopify App Development Environment...

Starting ngrok tunnel on port 8000...
✅ Tunnel URL: https://abc123.ngrok-free.app
✅ .env updated with tunnel URL.
Starting Laravel dev server on port 8000...
Starting Vite dev server on port 5173...

═══════════════════════════════════════════════
  Development environment is running!
  App URL:   https://abc123.ngrok-free.app
  Local:     http://localhost:8000
  Vite:      http://localhost:5173
═══════════════════════════════════════════════
  Press Ctrl+C to stop all services.
```

---

## 2. `shopify:app:deploy` — Production Deployment

**File:** `src/Console/Commands/AppDeployCommand.php`

Prepares the app for production deployment.

### Usage

```bash
php artisan shopify:app:deploy

# Options
php artisan shopify:app:deploy --skip-build
php artisan shopify:app:deploy --skip-optimize
```

### Options

| Option | Description |
|---|---|
| `--skip-build` | Skip the frontend build step (npm/yarn build) |
| `--skip-optimize` | Skip Laravel optimization (config:cache, route:cache, view:cache) |

### What It Does (5 Steps)

**Step 1: Validate Environment**

Checks that these config values are set (non-empty):
- `SHOPIFY_API_KEY`
- `SHOPIFY_API_SECRET`
- `SHOPIFY_APP_URL`

Fails immediately if any are missing.

**Step 2: Build Frontend**

Detects package manager and runs:
- Yarn: `yarn build`
- npm: `npm run build`

Timeout: 300 seconds (5 minutes). Shows build output in real-time.

**Step 3: Optimize Laravel**

Runs:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Step 4: Report Webhooks**

Lists all configured webhook topics and their Job classes. Reminds you that webhooks are registered per-shop during token exchange (not globally during deploy).

**Step 5: Run Migrations**

Asks for confirmation: `Run database migrations?` (default: yes)

Runs `php artisan migrate --force` if confirmed.

---

## 3. `shopify:generate:webhook` — Webhook Job Generator

**File:** `src/Console/Commands/GenerateWebhookCommand.php`

Scaffolds a webhook Job class and registers it in the config.

### Usage

```bash
php artisan shopify:generate:webhook PRODUCTS_UPDATE
php artisan shopify:generate:webhook APP_UNINSTALLED --force
```

### Arguments & Options

| Argument/Option | Description |
|---|---|
| `topic` | The webhook topic (e.g., `PRODUCTS_UPDATE`, `APP_UNINSTALLED`) |
| `--force` | Overwrite existing Job file |

### What It Does

**Step 1: Convert topic to class name**

```
PRODUCTS_UPDATE → ProductsUpdateJob
APP_UNINSTALLED → AppUninstalledJob
ORDERS_CREATE → OrdersCreateJob
```

Logic: split by `_`, capitalize each word, append `Job`.

**Step 2: Create the Job file**

Creates `app/Jobs/Shopify/{ClassName}.php` using the stub template (`stubs/webhook-job.stub`). Creates the directory if it doesn't exist.

If the file already exists and `--force` is not set, asks for confirmation before overwriting.

**Step 3: Register in config**

Opens `config/shopify-app.php` and adds the topic to the `webhooks` array:

```php
'webhooks' => [
    'PRODUCTS_UPDATE' => \App\Jobs\Shopify\ProductsUpdateJob::class,
    // newly added ↑
],
```

If the topic already exists in config, skips this step.

### Output

```
Generating webhook handler for: PRODUCTS_UPDATE
✅ Created: /path/to/app/Jobs/Shopify/ProductsUpdateJob.php
✅ Registered 'PRODUCTS_UPDATE' in config/shopify-app.php

═══════════════════════════════════════════════
  Webhook handler generated for: PRODUCTS_UPDATE
  Job class: App\Jobs\Shopify\ProductsUpdateJob
═══════════════════════════════════════════════

Remember to add this to your shopify-app.php config if not auto-registered:
  'PRODUCTS_UPDATE' => \App\Jobs\Shopify\ProductsUpdateJob::class,
```

---

## 4. `shopify:generate:extension` — Extension Scaffolder

**File:** `src/Console/Commands/GenerateExtensionCommand.php`

Scaffolds files for Shopify Theme App Extensions or UI Extensions.

### Usage

```bash
# Theme App Extension
php artisan shopify:generate:extension my-theme-block --type=theme

# UI Extension
php artisan shopify:generate:extension my-admin-block --type=ui
```

### Arguments & Options

| Argument/Option | Description |
|---|---|
| `name` | Extension name (e.g., `my-theme-block`) |
| `--type` | `theme` or `ui` (default: `theme`) |
| `--force` | Overwrite existing files |

### Theme Extension — Generated Files

Created in `extensions/{name}/`:

```
extensions/my-theme-block/
├── shopify.extension.toml    ← Extension configuration
├── assets/
│   └── my-theme-block.css    ← Stylesheet
├── blocks/
│   └── app-block.liquid      ← Theme block with schema
├── snippets/
│   └── app-snippet.liquid    ← Reusable snippet
└── locales/
    └── en.default.json       ← Translations
```

**`shopify.extension.toml`** — Contains the extension config for Shopify CLI:
```toml
api_version = "2025-01"

[[extensions]]
name = "my-theme-block"
type = "theme"

  [[extensions.blocks]]
  name = "App Block"
  target = "section"
  template = "blocks/app-block.liquid"
```

**`app-block.liquid`** — A Liquid block with a JSON schema:
```liquid
{% schema %}
{
  "name": "App Block",
  "target": "section",
  "settings": [
    { "type": "text", "id": "heading", "label": "Heading", "default": "Hello from your app!" },
    { "type": "color", "id": "background_color", "label": "Background Color", "default": "#ffffff" }
  ]
}
{% endschema %}
```

### UI Extension — Generated Files

Created in `extensions/{name}/`:

```
extensions/my-admin-block/
├── shopify.extension.toml    ← Extension configuration
├── src/
│   └── index.jsx             ← React component
└── locales/
    └── en.default.json       ← Translations
```

**`index.jsx`** — A React component using Shopify's UI Extensions API:
```jsx
import { reactExtension, useApi, AdminBlock, BlockStack, Text, Button } from '@shopify/ui-extensions-react/admin';

const TARGET = 'admin.product-details.block.render';

export default reactExtension(TARGET, () => <MyAdminBlock />);

function MyAdminBlock() {
  const { extension } = useApi(TARGET);
  return (
    <AdminBlock title="my-admin-block">
      <BlockStack>
        <Text>Welcome to your UI Extension!</Text>
        <Button onPress={() => console.log('pressed')}>Get Started</Button>
      </BlockStack>
    </AdminBlock>
  );
}
```

---

## Command Summary

| Command | What It Does |
|---|---|
| `shopify:app:dev` | Start tunnel + Laravel server + Vite + update URLs |
| `shopify:app:deploy` | Validate env + build frontend + optimize + migrate |
| `shopify:generate:webhook` | Create Job class + register in config |
| `shopify:generate:extension` | Create extension directory structure |
