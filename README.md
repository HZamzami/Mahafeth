# Mahafeth

A Laravel 12 application built on the official [Livewire starter kit](https://github.com/laravel/livewire-starter-kit).

**Live site:** <https://mahafeth-production-ws31xa.laravel.cloud/>

## Stack

- PHP 8.4 / Laravel 12
- Livewire 4 with [Volt](https://livewire.laravel.com/docs/volt) single-file components
- [Flux UI](https://fluxui.dev) component library
- Tailwind CSS 4 (via the Vite plugin)
- PostgreSQL 17 (matching the [Laravel Cloud](https://cloud.laravel.com) production database)

## Local Development

The project runs in [DDEV](https://ddev.com), so the only host requirements are Docker and the ddev CLI — it works the same on macOS, Windows (WSL2), and Linux.

```bash
git clone <repo-url> mahafeth && cd mahafeth
ddev start
ddev composer install
cp .env.example .env
ddev artisan key:generate
ddev artisan migrate
ddev npm install
ddev npm run dev
```

In `.env`, replace the default `DB_CONNECTION=sqlite` with the ddev PostgreSQL container:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=db
DB_USERNAME=db
DB_PASSWORD=db
```

The app is served at <https://mahafeth.ddev.site>. The Vite dev server (HMR) is exposed through the ddev router on port 5173 — this is already wired up in `.ddev/config.yaml` and `vite.config.js`, but note that `npm run dev` must run **inside** the container (`ddev npm run dev`), not on the host.

## Testing & Formatting

```bash
ddev artisan test --compact   # PHPUnit test suite
ddev exec vendor/bin/pint     # code style (Laravel Pint)
```

Tests run against a dedicated `testing` PostgreSQL database (same engine as production, isolated from your dev data). It is created automatically by a ddev `post-start` hook.

## Claude Code

The repo ships with [Laravel Boost](https://github.com/laravel/boost) integration for Claude Code: project guidelines in `CLAUDE.md`, domain skills in `.claude/skills/`, and the Boost MCP server registered in `.mcp.json` (it runs through ddev, so start the project before starting a session).