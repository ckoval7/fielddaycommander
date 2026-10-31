---
name: laravel-stack
description: Use this skill when writing or changing any PHP, Blade, Livewire, migration, model, test, config or artisan work in Field Day Commander. It covers the stack versions (Laravel 13, Livewire 4, Mary UI, Pest 5, Octane, Reverb), PHP style, Laravel conventions, Livewire and Octane pitfalls, testing with Pest, and running Pint.
---

# Laravel Stack

## Versions

Check `composer.lock` and `package.json` when a version matters; these drift.

| Package | Version |
|---------|---------|
| PHP | 8.4 |
| laravel/framework | 13 |
| livewire/livewire | 4 |
| robsontenorio/mary (Mary UI, on daisyUI 5) | 2 |
| laravel/octane | 2 |
| laravel/reverb / laravel-echo | 1 / 2 |
| laravel/fortify | 1 |
| spatie/laravel-permission | roles and permissions |
| pestphp/pest / phpunit | 5 / 13 |
| tailwindcss / alpinejs / vite | 4 / 3 / 8 |
| laravel/pint, laravel/sail, laravel/boost | dev only |

Don't add, remove or upgrade dependencies without asking. Write code for the installed major versions; when unsure of an API, read the package source in `vendor/` rather than guessing from an older version. If the Laravel Boost MCP server is connected, its `search-docs` tool returns version-matched docs.

## PHP style

- Curly braces on every control structure, even one-liners.
- Constructor property promotion. No empty zero-argument constructors unless they're private (see `App\Support\DeveloperMode`).
- Explicit parameter and return types everywhere.
- PHPDoc blocks over inline comments, with array shapes (`@var array<string, class-string<RuleSet>>`). Inline comments only for genuinely tricky logic.
- Enum cases in TitleCase.
- Descriptive names: `isRegisteredForDiscounts`, not `discount()`.
- Every new source file starts with the SPDX header (`REUSE.toml` covers files that can't hold one):

  ```php
  <?php

  // SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
  // SPDX-License-Identifier: GPL-3.0-or-later
  ```

  Blade files use the same two lines in a `{{-- --}}` comment, JS files in `//` comments.

## Laravel conventions

- Laravel 13 keeps the streamlined 11+ layout: no `Http/Kernel.php` or `Console/Kernel.php`. Middleware, exceptions and routing are configured in `bootstrap/app.php`; providers in `bootstrap/providers.php`; schedules in `routes/console.php`. Commands in `app/Console/Commands/` register themselves.
- Create files with `php artisan make:* --no-interaction` (use `make:class` for plain classes), then match sibling files.
- Don't add new top-level directories under `app/` without asking. Business logic goes in `app/Services`; scoring in `app/Scoring`; small static helpers in `app/Support`.
- Model casts go in a `casts()` method, not `$casts`.
- When modifying a column in a migration, restate every attribute it already has, or they're dropped.
- Eager-load to avoid N+1s. Limiting eager loads (`->latest()->limit(10)`) works natively.
- Links use named routes and `route()`.
- New models get a factory (and a seeder if the data is reference data).
- Validate with Form Requests or Livewire rules; authorize through policies (`app/Policies`) and spatie permissions, never by checking role names inline.

## Octane

The app runs under Octane, so the application boots once and is reused across requests.

- Singletons persist between requests. Use `scoped()` for per-request state.
- Never inject the request, container or config repository into a singleton's constructor; resolve lazily with a closure: `fn () => new Service(fn () => request())`.
- Never append to static properties; they grow forever.
- A service that memoizes request data, like `EventContextService`, is bound with `scoped()` in `AppServiceProvider`. Follow that pattern rather than resetting state by hand.

## Livewire and the UI

- Components are class-based: `app/Livewire/<Area>/Name.php` with its view at `resources/views/livewire/<area>/name.blade.php`. Shared component logic lives in traits under `app/Livewire/Concerns` or an area's `Concerns` folder.
- Keep state server-side. Validate and authorize inside actions as you would in a controller, since any public method can be called from the browser.
- Use Mary UI components (`<x-button>`, `<x-card>`, `<x-input>`, `<x-modal>`, no prefix) and Phosphor icons (`icon="phosphor-trash"`) before writing custom markup. Check `resources/views/components` for an existing app component first.
- Alpine for client-only behavior; no other JS frameworks.
- Live updates go through Reverb and Echo. Broadcast event names live in `App\Support\BroadcastChannels`.
- Every screen is used on phones: activate the `mobile-ui` skill for any view change.
- If a frontend change doesn't show up, assets need rebuilding (`npm run build` in the container).

## Testing

- Every change gets a new or updated test. Most are feature tests: `php artisan make:test --pest NameTest` (`--unit` for unit tests; don't include the suite directory in the name).
- Use model factories and their states; use `fake()` (not `$this->faker`).
- Tests run on in-memory SQLite (`phpunit.xml`), so avoid MySQL-only SQL in code paths that tests touch, or guard it.
- While working, run the affected file, directory or `--filter`. Before merging a branch into `dev`, run the full suite in parallel (about two minutes); the command is in the `branch-workflow` skill. Don't delete tests without asking.
- Don't write throwaway verification scripts or tinker sessions when a test can prove it.

Commands, and the container to run them in, are in the `branch-workflow` skill (host PHP is too old). In short: `php artisan test --compact <path>`, then `vendor/bin/pint --dirty --format agent` after changing PHP. Don't run Pint with `--test`; just run it to fix.

## Artisan

- `php artisan route:list --name=... --path=...` to inspect routes; `php artisan config:show app.name` for config.
- In tinker, wrap code in single quotes and use double quotes inside: `php artisan tinker --execute 'User::where("active", true)->count();'`. Don't create records in tinker without asking.
