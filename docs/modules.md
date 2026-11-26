# Module Development Guide

FusterAI includes a lightweight module/plugin system that lets you extend the platform without modifying core code. Modules live in the `Modules/` directory and are auto-discovered by `ModuleServiceProvider`.

---

## Module Structure

```
Modules/
└── MyModule/
    ├── module.json                    # Module metadata
    ├── Providers/
    │   └── MyModuleServiceProvider.php  # Bootstraps the module
    ├── Http/
    │   └── Controllers/
    │       └── MyController.php
    ├── Database/
    │   └── Migrations/
    │       └── 2025_01_01_create_my_table.php
    ├── Resources/
    │   └── js/
    │       └── MyComponent.tsx       # Optional React components
    ├── Config/
    │   └── my-module.php
    └── Routes/
        └── web.php
```

---

## Creating a Module

### 1. Create the directory structure

```bash
mkdir -p Modules/MyModule/{Providers,Http/Controllers,Database/Migrations,Resources/js,Config,Routes}
```

### 2. Create `module.json`

```json
{
    "alias": "my-module",
    "name": "My Module",
    "description": "A brief description of what this module does.",
    "version": "1.0.0",
    "active": true
}
```

### 3. Create the Service Provider

`Modules/MyModule/Providers/MyModuleServiceProvider.php`:

```php
<?php

namespace Modules\MyModule\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\Hooks;
use App\Domains\Conversation\Models\Conversation;

class MyModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register routes
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');

        // Register migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Register config
        $this->mergeConfigFrom(__DIR__ . '/../Config/my-module.php', 'my-module');

        // Register hooks (see Hook System section below)
        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        Hooks::addAction('conversation.created', function (Conversation $conv) {
            // React to new conversations
        });
    }
}
```

### 4. Register the module

Add your module to `config/modules.php`:

```php
return [
    'modules' => [
        'MyModule' => [
            'provider' => \Modules\MyModule\Providers\MyModuleServiceProvider::class,
        ],
    ],
];
```

---

## Hook System

`App\Support\Hooks` is a small static action/filter bus for modules
(`Hooks::addAction($hook, $callback, $priority)`, `Hooks::addFilter(...)`).
Register hooks in your provider's `boot()`; only active modules' providers boot.

```php
use App\Support\Hooks;

// Action: react to an event (return value ignored)
Hooks::addAction('thread.created', function (Thread $thread) {
    // Index in external search, trigger a webhook, etc.
});

// Filter: add data to the conversation page payload (must return the value)
Hooks::addFilter('conversation.show.extra', function (array $extra, Conversation $conversation) {
    $extra['myModule'] = ['score' => 42];

    return $extra;
});
```

### Hooks the core fires

| Hook | Type | Payload |
|---|---|---|
| `conversation.created` | Action | `Conversation` |
| `conversation.updated` | Action | `Conversation` |
| `conversation.closed` | Action | `Conversation` |
| `thread.created` | Action | `Thread` (customer messages, staff replies from the app or ingested from Gmail) |
| `conversation.show.extra` | Filter | `array $extra, Conversation` (merged into the conversation page props) |
| `survey.responded` | Action | fired by the SatisfactionSurvey module |

There is no hook into the AI agent's prompts or drafts. The agent's
instructions are versioned, human-approved content (voice, playbooks,
knowledge, examples) that simulations and the regression gate can replay
exactly; code that changed the prompt behind their back would break that.
Change what the agent does through Agent → Voice / Playbooks / Knowledge
(docs/agent-proposals.md). The old `ai.system_prompt`, `ai.context` and
`ai.generate_reply` filters were removed with the legacy drafter.

---

## Adding Routes

`Modules/MyModule/Routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\MyModule\Http\Controllers\MyController;

Route::middleware(['web', 'auth'])->prefix('my-module')->group(function () {
    Route::get('/', [MyController::class, 'index'])->name('my-module.index');
    Route::post('/action', [MyController::class, 'action'])->name('my-module.action');
});
```

---

## Adding Database Tables

Create a migration in `Modules/MyModule/Database/Migrations/`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('my_module_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('my_module_records');
    }
};
```

---

## React Frontend Components

### Register a frontend slot

In your Service Provider, push a component into a named slot:

```php
use App\Support\Hooks;

Hook::add_action('conversation.sidebar', [MyModule::class, 'renderSidebarPanel']);
```

### Create the React component

`Modules/MyModule/Resources/js/SidebarPanel.tsx`:

```tsx
import React from 'react';

interface Props {
    conversation: {
        id: number;
        subject: string;
        customer: { name: string; email: string };
    };
}

export default function SidebarPanel({ conversation }: Props) {
    return (
        <div className="p-4 border rounded-lg">
            <h3 className="font-medium text-sm">My Module</h3>
            <p className="text-xs text-muted-foreground mt-1">
                Custom data for conversation #{conversation.id}
            </p>
        </div>
    );
}
```

### Register with the slot renderer

In the main app, the `<SlotRenderer>` component renders all registered module components:

```tsx
// This is already in the ConversationShow layout
<SlotRenderer name="conversation.sidebar.bottom" props={{ conversation }} />
```

---

## Example Module: Satisfaction Survey

A minimal module that sends a survey link when a conversation is closed:

```php
<?php

namespace Modules\SatisfactionSurvey\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\Hooks;
use App\Domains\Conversation\Models\Conversation;

class SatisfactionSurveyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Hook::listen('conversation.closed', function (Conversation $conv) {
            // Don't send if no customer email
            if (!$conv->customer?->email) {
                return;
            }

            \Mail::to($conv->customer->email)->send(
                new \Modules\SatisfactionSurvey\Mail\SurveyInvitation($conv)
            );
        });
    }
}
```

---

## Example Module: SLA Manager

Track response time SLAs:

```php
Hook::listen('conversation.created', function (Conversation $conv) {
    $slaHours = match ($conv->priority) {
        'urgent' => 1,
        'high'   => 4,
        'normal' => 24,
        default  => 72,
    };

    \DB::table('sla_records')->insert([
        'conversation_id' => $conv->id,
        'deadline_at'     => now()->addHours($slaHours),
        'created_at'      => now(),
    ]);
});

Hook::listen('thread.created', function ($thread) {
    if ($thread->type === 'message' && $thread->user_id) {
        // Agent replied — mark SLA as met
        \DB::table('sla_records')
            ->where('conversation_id', $thread->conversation_id)
            ->update(['first_reply_at' => now()]);
    }
});
```

---

## Module Configuration

Add typed config in `Modules/MyModule/Config/my-module.php`:

```php
<?php

return [
    'enabled'      => env('MY_MODULE_ENABLED', true),
    'webhook_url'  => env('MY_MODULE_WEBHOOK_URL', ''),
    'email_footer' => env('MY_MODULE_EMAIL_FOOTER', ''),
];
```

Access it anywhere:

```php
config('my-module.webhook_url');
```

---

## Testing Modules

Place module tests in `tests/Feature/Modules/`:

```php
<?php

use App\Domains\Conversation\Models\Conversation;

it('sends a survey when a conversation is closed', function () {
    \Mail::fake();

    $conv = Conversation::factory()->withCustomer()->create();
    $conv->update(['status' => 'closed']);

    // Fire the hook
    app(\App\Services\HookService::class)->fire('conversation.closed', $conv);

    \Mail::assertSent(\Modules\SatisfactionSurvey\Mail\SurveyInvitation::class);
});
```

---

## Publishing a Module

To share a module with the community:

1. Create a standalone Composer package
2. In `composer.json`, add the service provider to `extra.laravel.providers`
3. Users install with `composer require vendor/fusterai-my-module`
4. The module auto-registers via Laravel's package discovery
