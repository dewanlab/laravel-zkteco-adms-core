# CLAUDE.md

Guidelines for Claude Code / Anthropic models working on **dewanlab/laravel-zkteco-adms-core**.

Refer to [AGENTS.md](AGENTS.md) for full architectural guidelines, SemVer policies, and release checklists.

## Core Directives

1. **Issue-Driven Workflow**:

   - Create or assign a GitHub issue before coding.
   - Work on dedicated feature branches (`feat/...`, `fix/...`, `docs/...`). Never commit new code directly to `main`.
   - Post progress updates / work journey comments to the issue as milestones are completed.
2. **Quality Gates**:
   - `declare(strict_types=1);` in all PHP files.
   - PHPStan Level 8 must stay clean (`composer run types:check`).
   - Laravel Pint formatted (`composer run format`).
   - 100% test pass rate on Pest (`composer test`).
   - Full PHPDoc blocks explaining hardware context and wire format.
3. **Changelog & Releases**:
   - Append changes to `[Unreleased]` in `CHANGELOG.md`.
   - Adhere strictly to SemVer 2.0.0 (Patch, Minor, Major).
