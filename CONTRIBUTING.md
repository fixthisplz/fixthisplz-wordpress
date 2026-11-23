# Contributing

Thanks for your interest in FixThisPlz!

The project, its roadmap and the full contribution guide (code of conduct, how to propose a change, conventions) live in the hub repository: **[github.com/fixthisplz/fixthisplz](https://github.com/fixthisplz/fixthisplz)**. Please read it first.

Issues and pull requests about the WordPress plugin itself are welcome here. For anything security related, follow [SECURITY.md](SECURITY.md) instead of opening an issue.

## Local development

- Clone this repository into `wp-content/plugins/fixthisplz/` of a disposable WordPress test site (WordPress 6.5+, PHP 8.1+). There is no build step.
- Run the JavaScript tests: `node --test tests/js/*.test.mjs` (Node.js 22+).
- Run the PHP tests with WP-CLI: `wp eval-file wp-content/plugins/fixthisplz/tests/php/run.php`.
- Follow the [WordPress coding standards](https://developer.wordpress.org/coding-standards/); source strings are in English and go through the `fixthisplz` text domain.
- Keep the shipped plugin free of development files: anything that must not be in the release zip goes in `.gitattributes` with `export-ignore`.
- Use [Conventional Commits](https://www.conventionalcommits.org/) messages (`feat:`, `fix:`, `docs:`…).
