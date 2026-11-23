# FixThisPlz for WordPress

Let visitors and editors report bugs from any page of a WordPress site, with an optional annotated screenshot, and track them in a list or a Kanban board.

FixThisPlz adds a floating button to your site. A visitor (logged in or not) clicks it, points at the element that has a problem or picks "Whole page", describes the problem, optionally attaches a screenshot, and sends. The report lands in a **Bugs** menu of the dashboard and you get an e-mail. Everything is native WordPress (custom table, REST API, Settings API, plain JavaScript), with no build step and no CDN.

This repository is the WordPress plugin. The FixThisPlz project (vision, roadmap, contribution guide) lives at [github.com/fixthisplz/fixthisplz](https://github.com/fixthisplz/fixthisplz).

## Features

- Customizable floating button with live preview; element picker for mouse, touch and keyboard.
- Accessible report dialog showing exactly which technical details are sent.
- Screenshots: real capture of the browser tab or page reconstruction, optional masking of typed fields and sensitive areas, annotation (rectangles, arrows, pen, text, hidden areas).
- Download of the report as Markdown, JSON or plain text.
- List with filters, search, bulk actions and CSV export; Kanban board with drag and drop; detail screen.
- Assignment, internal notes and change history; configurable statuses and priorities; dashboard widget.
- E-mail notifications and webhooks (Slack, Discord, Microsoft Teams or signed JSON).
- Spam protection: Cloudflare Turnstile, Google reCAPTCHA v2/v3 or hCaptcha, honeypot, per-IP rate limits.
- Privacy by design: typed values and passwords never recorded, IP address never stored (keyed hash only), contact e-mail encrypted, private screenshot folder, personal data exporter and eraser, automatic deletion of handled reports.
- Loads nothing where the button is hidden. Translated into French.

## Requirements

WordPress 6.5 or later, PHP 8.1 or later.

## Installation

- From the WordPress.org plugin directory, once the plugin is listed there: **Plugins › Add New**, search for "FixThisPlz", install and activate.
- From a zip: download a release archive (or build one with `git archive`, see below), then **Plugins › Add New › Upload Plugin**.

Then go to **Bugs › Settings**. Reporting is off by default and, once enabled, shown to administrators only. See [`readme.txt`](readme.txt) for the full documentation (settings, FAQ, privacy, external services).

## Development

The repository root is the plugin root: clone it into `wp-content/plugins/fixthisplz/` of a test site. There is no build step and no dependency to install.

- **JavaScript tests** (Node.js 22 or later, no dependency), from the repository root:

  ```sh
  node --test tests/js/*.test.mjs
  ```

- **PHP tests** run inside a WordPress test site through WP-CLI. Each test runs in a SQL transaction that is rolled back. Use a disposable site, never a production one:

  ```sh
  wp eval-file wp-content/plugins/fixthisplz/tests/php/run.php            # all tests
  wp eval-file wp-content/plugins/fixthisplz/tests/php/run.php redactor   # files whose name contains "redactor"
  ```

- **Release archive**: `git archive --format=zip --prefix=fixthisplz/ -o fixthisplz.zip HEAD`. Development files (tests, docs, this README…) are excluded by `.gitattributes`.

The design document is [`docs/specs/fixthisplz-design.md`](docs/specs/fixthisplz-design.md) (in French); the "spec x.y" comments in the code point to its sections.

This project is developed with the help of an AI assistant (Claude Code); [`CLAUDE.md`](CLAUDE.md) holds the project rules it follows.

## Contributing and security

- Contributions: see [CONTRIBUTING.md](CONTRIBUTING.md).
- Vulnerabilities: please report them privately, see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). The bundled [modern-screenshot](https://github.com/qq15725/modern-screenshot) library is MIT licensed.
