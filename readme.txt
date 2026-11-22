=== Bug Spotter ===
Contributors: dlicha
Tags: bug report, feedback, screenshot, support, issue tracker
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let visitors and editors report bugs from any page, with an optional screenshot, and track them in a list or Kanban board.

== Description ==

Bug Spotter adds a floating button to your site. A visitor (logged in or not) clicks it, points at the element that has a problem or picks "Whole page", describes what went wrong, optionally attaches a screenshot, and submits. The report lands in a dedicated **Bugs** menu of your dashboard, and you get an e-mail. Right after sending, the reporter can download a copy of the report.

Everything is built natively for WordPress: PHP and hooks, a dedicated database table, the WordPress REST API, the Settings API, native admin screens, and plain JavaScript. There is no build step, no CDN and no external service apart from the anti-bot provider you choose. Everything is free: there is no premium version.

= Features =

* Floating button with live preview in the settings: icon (Dashicon or SVG from your media library), shape, size, colors, shadow, corner radius, position (desktop and mobile), z-index.
* Element picker for mouse, touch and keyboard, announced to screen readers; "Whole page" option.
* Accessible modal dialog: description, optional contact e-mail for guests, optional screenshot with preview, privacy notice, and a "Technical details sent with your report" panel that shows exactly what is sent.
* Two screenshot methods: a real capture of the browser tab (exact image; the browser asks the visitor for permission), or a reconstruction of the page (no permission prompt, may differ slightly from the screen). Phones and browsers without screen sharing always use the reconstruction.
* Optional masking of typed fields and sensitive areas in the screenshot.
* Screenshot annotation before sending: rectangles, arrows, freehand strokes, text and hidden areas, drawn with a mouse, a finger or a pen, in six colors; select, move, resize, recolor or delete each one; undo, clear all, cancel or apply.
* Download of the report as Markdown, JSON or plain text, plus the screenshot, built in the browser.
* Bugs menu with a filterable and sortable list (status, priority, period, search), bulk actions, CSV export, a Kanban board with drag and drop, and a detail screen. The menu name, the list sub-menu name and the menu position can be changed.
* Configurable statuses: name, color, order (also the order of the Kanban columns) and a "Closed" flag; add your own or delete one, moving its reports to another status. Defaults: New, Seen, Confirmed, In progress, Fixed, Won't fix, Duplicate. Priorities: Low, Normal, High, Critical.
* E-mail notification for each new report, with a link to the report and an optional screenshot attachment.
* Anti-bot protection: Cloudflare Turnstile, Google reCAPTCHA v2 or v3, hCaptcha, or none; a hidden honeypot field; per-IP rate limits (hourly and daily). Includes a "Test the keys" button.
* Privacy by design: values typed in form fields and passwords are never written into the report; e-mails and tokens are redacted in the browser and again on the server; the IP address is never stored (only a keyed hash); the contact e-mail is stored encrypted; screenshots are stored in a private folder.
* WordPress personal data exporter and eraser, suggested privacy policy text, automatic deletion of closed reports after a delay you choose.
* Loads nothing on pages where the button is not shown. The reporter code is loaded step by step: the small loader first, the rest on the first click.
* Translated into French; translation-ready for any other language.

= External services =

Bug Spotter connects to an external service only when you choose an anti-bot provider in **Bugs › Settings › Anti-bot** (the default is "None"). The provider's script is loaded in the report dialog, and when a report is sent your server sends the visitor's token and IP address to the provider for verification. "Test the keys" in the settings does the same with your own browser.

* Cloudflare Turnstile: [terms](https://www.cloudflare.com/website-terms/), [privacy policy](https://www.cloudflare.com/privacypolicy/).
* Google reCAPTCHA v2 and v3: [terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy).
* hCaptcha: [terms](https://www.hcaptcha.com/terms), [privacy policy](https://www.hcaptcha.com/privacy).

= Third-party libraries =

* [modern-screenshot](https://github.com/qq15725/modern-screenshot) 4.7.0, MIT licence, bundled unminified in `assets/vendor/` with its licence file. It builds the page reconstruction and is loaded only when that method is used.

== Installation ==

1. Upload the `bug-spotter` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin.
3. Go to **Bugs › Settings**. Bug reporting is disabled by default and, once enabled, is visible to administrators only: tick the roles (and "Guests") who should see the button.
4. In the **Dialog** tab, choose the screenshot method and whether screenshots are masked.
5. If guests can report, choose an anti-bot provider in the **Anti-bot** tab and use "Test the keys".
6. Check the e-mail recipients in the **Notifications** tab.

== Frequently Asked Questions ==

= The button does not show up. =

Check that "Show the bug report button" is ticked, that your role (or "Guests") is ticked in "Who sees the button", and that the page is not excluded. If a page cache serves your pages, purge it after changing these settings.

= I use nginx. How do I protect the screenshots? =

Screenshots are stored in `wp-content/uploads/bug-spotter/` under random file names, with an empty `index.php` and an `.htaccess` file that denies all access. The `.htaccess` file is ignored by nginx, so add a rule to your server block:

`location ^~ /wp-content/uploads/bug-spotter/ {
    deny all;
    return 403;
}`

Adjust the path if your uploads folder is elsewhere. Screenshots are served to authorised people only through the REST API, so denying direct access breaks nothing.

= What is the BUGSPOT_SECRET constant? =

The plugin uses a key of its own to encrypt contact e-mail addresses and to compute keyed hashes (of IP addresses and e-mails). It is generated at activation and stored in the `bugspot_secret` option. It does not depend on the WordPress salts, so rotating the salts does not make stored data unreadable.

To keep the key outside the database, define a constant in `wp-config.php`, above the line "That's all, stop editing":

`define( 'BUGSPOT_SECRET', 'a-long-random-string-of-at-least-32-characters' );`

When the constant is defined, it replaces the stored key. Changing it later makes previously encrypted contact e-mails unreadable and breaks the matching of the privacy exporter and eraser, so choose it once and keep a copy.

= How do I test the anti-bot provider with test keys? =

Each provider publishes keys meant for testing. Enter them in **Bugs › Settings › Anti-bot** and use "Test the keys". Look up the current values in the provider documentation; at the time of writing:

* Cloudflare Turnstile: site key `1x00000000000000000000AA` always passes, `2x00000000000000000000AB` always blocks; secret key `1x0000000000000000000000000000000AA` always passes, `2x0000000000000000000000000000000AA` always fails.
* Google reCAPTCHA v2 (checkbox): site key `6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI` with secret key `6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe` always passes.
* hCaptcha: site key `10000000-ffff-ffff-ffff-000000000001` with secret key `0x0000000000000000000000000000000000000000` always passes.
* Google reCAPTCHA v3 has no official test keys: create real keys for a test domain.

Never keep test keys in production: they protect nothing.

= Does it work with page caches? =

Yes. The button's HTML contains nothing that is specific to a user. The report is linked to the logged-in user through the REST nonce when it is valid; if the cached page is older than the nonce, the report is still saved, just without the user. The protection of the form relies on the anti-bot check, the honeypot and the rate limits, not on the nonce.

= What happens to my data if I deactivate or delete the plugin? =

Deactivating keeps everything. Deleting the plugin keeps your reports, screenshots and settings too, unless you ticked "Delete all reports, screenshots and settings when the plugin is deleted" in **Bugs › Settings › Data and privacy**. In that case the table, the options, the capability and the private folder are removed.

= Does it work on multisite? =

Activate it on each site where you want it. Network uninstall only cleans the main site: on the other sites, deactivate the plugin and tick the deletion option first, or remove the data by hand.

= Who can manage the reports? =

The roles ticked in **Bugs › Settings › Management** receive the `bugspot_manage` capability (administrators by default). It gives access to the list, the Kanban board, the detail screen, screenshots and the CSV export. The Settings screen itself requires `manage_options`, so a bug manager never sees your anti-bot secret keys.

= Does it answer the reporters? =

No. Bug Spotter is not a support system: there is no reply, no discussion thread and no e-mail to the reporter.

== Privacy ==

Bug Spotter stores reports in a dedicated table of your database. A report contains the description written by the reporter, the address of the page (query values are replaced), technical context (browser, language, time zone, window size, the element chosen, and optionally the last console errors and failed network requests), an optional screenshot, an optional contact e-mail address (encrypted), the WordPress user ID when the reporter is logged in, and a keyed hash of the IP address used against abuse. The IP address itself is never stored.

Values typed in form fields and passwords are never written into the report. Elements marked `data-bugspot-sensitive`, and elements matching the sensitive selectors you configure, are replaced by "[masked]" in the report. The screenshot is an image of the page as the reporter sees it, shown to the reporter before sending; it is not masked unless you enable "Hide typed fields and sensitive areas in the screenshot" in **Bugs › Settings › Dialog** (on a real capture, masking applies only when the visitor shares the current tab). The plugin makes no request to external services, except the one to the anti-bot provider you choose (Cloudflare, Google or hCaptcha), which receives the visitor's token and IP address as required for verification.

The plugin adds suggested text to your privacy policy (Settings › Privacy), and registers a personal data exporter and eraser. Closed reports (by default Fixed, Won't fix and Duplicate: every status marked "Closed") can be deleted automatically after a number of months of your choice.

== Changelog ==

= 1.1.0 =
* Screenshot annotation: before sending, the visitor can draw rectangles, arrows and freehand strokes on the screenshot, add text, pick one of six colors, or hide an area, then select, move, resize, recolor or delete each annotation, and undo any change.
* Fix: after an update, browsers no longer mix old and new script files (WordPress 7.0+).

= 1.0.0 =
* First public release: floating button with live preview, element picker, accessible report dialog, optional screenshot (real tab capture or page reconstruction, optional masking), report downloads, list and Kanban board, detail screen, CSV export, configurable statuses, menu name and position, e-mail notification, anti-bot providers and rate limits, privacy exporter and eraser, automatic purge of closed reports, French translation.

== Upgrade Notice ==

= 1.1.0 =
Adds screenshot annotation and makes updates safe for browsers that cached the previous script files.

= 1.0.0 =
First public release.
