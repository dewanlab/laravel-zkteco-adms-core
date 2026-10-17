# GEMINI.md

Guidelines for Google Gemini / Antigravity models working on **dewanlab/laravel-zkteco-adms-core**.

Refer to [AGENTS.md](AGENTS.md) for full architectural guidelines, SemVer policies, and release checklists.

## Essential Directives

- **Issue-First & Branch Isolation**: Always work from an issue on a dedicated branch (`feat/...`, `fix/...`). Update the issue with milestones and the coding journey.
- **Zero Static Analysis Errors**: PHPStan Level 8 must stay clean (`composer run types:check`).
- **Pest Extreme Testing**: Add comprehensive unit & feature tests for all changes covering hardware failure modes.
- **Protocol Safety**: Protect against ZKTeco authorization stacking bugs and clock drifts.
- **Documentation**: Provide full PHPDoc blocks and keep `CHANGELOG.md` updated.
- **Quality Commands**:
  - `composer test`
  - `composer run types:check`
  - `composer run format`
