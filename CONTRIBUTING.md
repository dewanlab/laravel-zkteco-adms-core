# Contributing to Laravel ZKTeco ADMS Core

Thank you for considering contributing to `dewanlab/laravel-zkteco-adms-core`! Please read our contributing guide before submitting contributions.

---

## Code of Conduct

Please be respectful and constructive in all community interactions and code reviews.

---

## Development Workflow

We follow an **Issue-Driven Workflow**:

1. **Find or Create an Issue**: Before writing code, ensure a corresponding issue exists describing the bug or feature.
2. **Branching**:
   - Create a branch off `main`:

     ```bash
     git checkout -b feat/your-feature-name
     # or
     git checkout -b fix/your-bugfix-name
     ```

3. **Coding Standards**:
   - All PHP files must include `declare(strict_types=1);`.
   - Maintain full PHPDoc documentation on classes, methods, and DTO properties.
   - Run Laravel Pint formatter:

     ```bash
     composer run format
     ```

   - Ensure zero PHPStan Level 8 errors:

     ```bash
     composer run types:check
     ```

4. **Testing**:
   - Every change must include dedicated Pest unit or feature tests covering edge cases.
   - Run tests locally:

     ```bash
     composer test
     ```

5. **Changelog**:
   - Add your change description to the `[Unreleased]` section in `CHANGELOG.md`.
6. **Pull Request**:
   - Submit your PR against the `main` branch referencing the issue (`fixes #12`).
