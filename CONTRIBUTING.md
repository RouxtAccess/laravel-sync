# Contributing

Thanks for considering a contribution.

## Before you open a pull request

- Open an issue first for anything beyond a small fix, so we can agree on the approach.
- Keep the change focused. One concern per pull request.
- Add or update tests. The suite must stay green.
- Run the checks locally before pushing:

```bash
composer test      # Pest
vendor/bin/pint    # code style
vendor/bin/phpstan analyse
```

## AI-assisted contributions

You may use AI tools while working, but a human must own the result. A person needs to read every line, run it, confirm it does what the description claims, and write the pull request description themselves in their own words.

Pull requests that are clearly unreviewed AI output (a generated description, code the author cannot explain, or changes that do not run) will be closed without review. We would rather have one carefully validated change than a pile of plausible-looking ones.

## Style and conventions

- Follow the existing code style. Spatie's PHP and Laravel guidelines apply (prefer early returns, `protected` over `private`, no compound conditionals).
- Documentation must not use dashes as punctuation. Rephrase with commas, periods, or parentheses.
- Never write a code path that pushes data to a production or upstream source. A sync only reads upstream and writes locally.

## Reporting bugs

Open an issue with the package version, PHP and Laravel versions, the sync type involved, and the exact command and output (with any secrets removed).
