# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

FreeScout: self-hosted help desk / shared mailbox. PHP on **Laravel 5.5** (old: no Laravel 6+ APIs), PHP >= 7.1, CI tests on 7.4–8.5. Docs live in the upstream wiki: https://github.com/freescout-help-desk/freescout/wiki

### Repo / branch layout
- This checkout is a fork (`origin` = curruwilla/freescout) on the **`dist`** branch, which tracks `upstream/dist` (the distribution build). Upstream development happens on `upstream/master`; the upstream PR template says PRs go to `master`, preferably as one commit.
- **`vendor/` is committed** on purpose (so installs work on shared hosting). Don't run `composer update` casually, and don't delete vendor files.
- `Modules/` (installed add-on modules) and `tools/` are gitignored.

## Commands

```bash
# Tests (phpunit 9). Uses the `testing` DB connection from config/database.php:
# MySQL freescout-test@127.0.0.1, override with DB_TEST_HOST/DB_TEST_DATABASE/DB_TEST_USERNAME/DB_TEST_PASSWORD.
# Set DB_CONNECTION=testing_pgsql for PostgreSQL.
php artisan migrate --force -n --database=testing && php artisan db:seed --force -n --database=testing
./vendor/bin/phpunit
./vendor/bin/phpunit tests/Unit/MailTest.php
./vendor/bin/phpunit --filter testMethodName

# Lint (CI runs plain `phpcs` with phpcs.xml; excludes vendor, overrides, Modules, config, blade, migrations)
phpcs
phpcs app/Conversation.php

# Rebuild generated JS (js/builds/vars.js + laroute.js) after changing routes or JS-exposed vars
php artisan freescout:build

# Clear app caches (config/routes/views/minified assets)
php artisan freescout:clear-cache
```

Webpack/laravel-mix (`package.json`) is essentially unused: `devDependencies` is empty. Frontend assets are plain files in `public/js` and `public/css`, concatenated and minified at runtime by `Minify::javascript()`/`Minify::stylesheet()` in `resources/views/layouts/app.blade.php`. Edit `public/js/main.js` directly. Minified bundles go into `public/{js,css}/builds/`; clear the cache to regenerate them.

Test fixtures: raw `.eml` messages in `tests/Messages/`, used by the mail-parsing tests (`WebklexTest`, `ReplySeparationTest`, etc.).

## Architecture

### Vendor overrides (critical)
`overrides/` holds patched copies of vendor classes (Laravel framework pieces, Symfony, webklex/php-imap, nwidart modules, swiftmailer, carbon, etc.). `composer.json` `autoload.psr-4`/`classmap`/`psr-0` maps those namespaces to `overrides/...`, so these files **shadow the ones in `vendor/`**. Before changing or debugging a vendor class, check whether a copy exists in `overrides/`; that copy is the one that runs. A new override needs a matching autoload entry plus `composer dump-autoload`. Entries above the `fs-comment` key in `composer.json` are functional customizations; entries below it are PHP-compatibility patches.

### Domain models (flat in `app/`, not `app/Models`)
`Mailbox` → `Conversation` → `Thread` (each message/note/reply in a conversation) → `Attachment`. `Customer` (+ `Email`, `CustomerChannel`), `User` (agents), `Folder` / `ConversationFolder` (per-mailbox and per-user views with cached counters), `Follower`, `Subscription` (notification prefs), `SendLog`, `Option` (key/value settings store, `Option::get/set`). `Conversation.php` and `Thread.php` are large and hold much of the business logic. Model types and statuses are integer class constants (e.g. `Conversation::STATUS_ACTIVE`, `Thread::TYPE_CUSTOMER`).

### Helpers
- `app/Misc/Helper.php` (~3.4k lines): grab-bag of static utilities (HTML sanitizing, URL/SSRF checks, file storage, date formatting, and more). Look here before writing a new utility.
- `app/Misc/Mail.php`: mail config, IMAP client creation, reply-separator logic.
- `app/Misc/Functions.php`: global functions, autoloaded via `composer.json` `files`.

### Email flow
- **Inbound**: `freescout:fetch-emails` (`app/Console/Commands/FetchEmails.php`) pulls mail through webklex/php-imap (overridden copy), matches it to conversations by Message-ID/headers, and creates Conversations/Threads/Customers. The scheduler in `app/Console/Kernel.php` runs it with custom mutex handling.
- **Outbound**: user actions fire events in `app/Events` → listeners in `app/Listeners` → queued jobs in `app/Jobs` (`SendReplyToCustomer`, `SendNotificationToUsers`, `SendAutoReply`) → mailables in `app/Mail`. Results are logged to `SendLog`.
- `app/Console/Kernel.php` also runs `queue:work` from the scheduler (no separate supervisor needed), plus monitors and cleanup commands. In production everything depends on cron running `schedule:run`.

### Side effects via observers
`AppServiceProvider` registers model observers (`app/Observers`). Many side effects live there (counter updates, cleanup on delete, and similar) rather than in controllers.

### Hooks / module system
- Uses **Eventy** (WordPress-style hooks): `\Eventy::action('name', ...)` and `\Eventy::filter('name', $value, ...)`, called from 250+ places in `app/` and Blade views. These are the extension API for modules. Keep existing hook names and arguments stable, and add hooks where modules may need to extend behavior.
- Modules are nwidart/laravel-modules packages in `Modules/<Name>` (namespace `Modules\`), managed by `app/Module.php` and the `freescout:module-*` artisan commands. A module that throws during registration gets auto-deactivated (see `modules.register_error` in `AppServiceProvider`).

### HTTP
- `routes/web.php`: authenticated agent UI. `routes/open.php`: public, unauthenticated endpoints. Controllers are few and large (`ConversationsController` is the biggest; many actions go through a single `ajax()` method switched on an `action` param).
- Authorization uses Policies in `app/Policies`.
- Routes are exposed to JS through laroute (`public/js/laroute.js`, regenerated by `freescout:build`). Use `laroute.route('name')` in JS.
- Real-time updates use a custom polling broadcaster ("polycast": `PolycastServiceProvider`, `app/Broadcasting`, `app/Channels`, `Realtime*` events), not websockets.

### Translations
`resources/lang/*.json`, keyed by English source strings via `__('...')`. JS strings come through `public/js/lang.js`.
