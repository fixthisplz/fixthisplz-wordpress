=== FixThisPlz ===
Contributors: dlicha
Tags: bug report, feedback, screenshot, support, issue tracker
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let visitors and editors report bugs from any page, with an optional screenshot, and track them in a list or Kanban board.

== Description ==

FixThisPlz adds a floating button to your site. A visitor (logged in or not) clicks it, points at the element that has a problem or picks "Whole page", describes the problem, optionally attaches an annotated screenshot, and sends. The report lands in a **Bugs** menu of your dashboard and you get an e-mail. Everything is native WordPress (custom table, REST API, Settings API, plain JavaScript), with no build step, no CDN and no premium version.

= Features =

* Customizable floating button with live preview; element picker for mouse, touch and keyboard.
* Accessible report dialog showing exactly which technical details are sent.
* Screenshots: real capture of the browser tab or page reconstruction, optional masking of typed fields and sensitive areas, annotation (shapes, arrows, pen, text, hidden areas).
* Download of the report as Markdown, JSON or plain text.
* List with filters, search, bulk actions and CSV export; Kanban board with drag and drop; detail screen.
* Assignment, internal notes and change history; configurable statuses and priorities; dashboard widget.
* E-mail notifications and webhooks (Slack, Discord, Microsoft Teams or signed JSON).
* Spam protection: Cloudflare Turnstile, Google reCAPTCHA v2/v3 or hCaptcha, honeypot, per-IP rate limits.
* Privacy by design: typed values and passwords never recorded, IP address never stored (keyed hash only), contact e-mail encrypted, private screenshot folder, personal data exporter and eraser, automatic deletion of handled reports.
* Loads nothing where the button is hidden. Translated into French.

= External services =

Spam check: FixThisPlz contacts a provider only when you choose one in **Bugs › Settings › Spam protection** (the default is "None"). The provider's script is loaded in the report dialog, and when a report is sent your server sends the visitor's token and IP address to the provider for verification. "Test the keys" in the settings does the same with your own browser.

* Cloudflare Turnstile: [terms](https://www.cloudflare.com/website-terms/), [privacy policy](https://www.cloudflare.com/privacypolicy/).
* Google reCAPTCHA v2 and v3: [terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy).
* hCaptcha: [terms](https://www.hcaptcha.com/terms), [privacy policy](https://www.hcaptcha.com/privacy).

With the "Page reconstruction" screenshot method (**Bugs › Settings › Form**), the visitor's browser re-downloads the fonts, images and stylesheets the page already uses, which may come from third-party hosts your theme relies on (for example a font CDN). Nothing is sent to the plugin author.

Webhooks (**Bugs › Settings › Alerts › Webhooks**, none by default): your server calls only the addresses an administrator saves there, for the events ticked for each one (new report, status changed, report assigned), right after the change, and when you click "Send a test". Opening a new report (New → Seen) sends nothing. After a bulk action, once all the changes are saved, Slack, Discord and Teams get one summary message (for example "12 reports: status → Fixed", with a link to the list), while the generic JSON gets one signed event per report. Each call sends the report number and title, an excerpt of the description (300 characters), the page address, the status, the priority, the display name of the assignee, a link to the report in your dashboard, the event, the site name and address, and the time. It never sends the reporter's e-mail address, IP address, screenshot or technical context. What the receiving service does with it is covered by its own terms, for example [Slack](https://slack.com/terms-of-service) ([privacy](https://slack.com/trust/privacy/privacy-policy)), [Discord](https://discord.com/terms) ([privacy](https://discord.com/privacy)) or [Microsoft Teams](https://www.microsoft.com/servicesagreement) ([privacy](https://privacy.microsoft.com/privacystatement)).

= Third-party libraries =

* [modern-screenshot](https://github.com/qq15725/modern-screenshot) 4.7.0, MIT licence, bundled unminified in `assets/vendor/` with its licence file. It builds the page reconstruction and is loaded only when that method is used.

== Installation ==

1. Upload the `fixthisplz` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen, and activate it.
2. Go to **Bugs › Settings**. Reporting is off by default and, once enabled, shown to administrators only: tick the roles (and "Guests") who should see the button.
3. In the **Form** tab, choose the screenshot method and masking. If guests can report, choose a spam check provider in **Spam protection** and use "Test the keys". Check the recipients in **Alerts**.

== Frequently Asked Questions ==

= The button does not show up. =

Check that reporting is enabled, that your role (or "Guests") is ticked in "Who sees the button" and that the page is not excluded. Purge your page cache after changing these settings.

= I use nginx. How do I protect the screenshots? =

They are stored under random names in `wp-content/uploads/fixthisplz/`, protected by an `.htaccess` file that nginx ignores. Add `location ^~ /wp-content/uploads/fixthisplz/ { deny all; }` to your server block: screenshots are served to authorised people through the REST API only.

= What is the FIXTHISPLZ_SECRET constant? =

The key that encrypts contact e-mails and computes the keyed hashes is generated at activation and stored in the `fixthisplz_secret` option. To keep it out of the database, define `FIXTHISPLZ_SECRET` (32 characters or more) in `wp-config.php`. Changing it later makes encrypted e-mails unreadable, so set it once.

= How do I test the spam check keys? =

Enter the test keys published by Cloudflare, Google (reCAPTCHA v2) or hCaptcha in **Spam protection** and use "Test the keys". reCAPTCHA v3 has no test keys. Never keep test keys in production.

= How does my service check a webhook? =

Choose "Generic JSON (signed)". The `X-FixThisPlz-Signature` header is `sha256=` followed by the HMAC-SHA256 (hexadecimal) of the raw body with the webhook's signing secret; `X-FixThisPlz-Event` names the event. Use the `timestamp` field to refuse old requests. Requests are sent once, without retry, to public https addresses only (plus local addresses on development sites, see the `fixthisplz_allow_dev_webhooks` filter).

= What happens to my data if I delete the plugin? =

Everything is kept unless you ticked the deletion option in **Bugs › Settings › Privacy**. On multisite, network uninstall only cleans the main site.

== Privacy ==

FixThisPlz stores reports in a dedicated table of your database. A report contains the description written by the reporter, the address of the page (query values are replaced), technical context (browser, language, time zone, window size, the element chosen, and optionally the last console errors and failed network requests), an optional screenshot, an optional contact e-mail address (encrypted), the WordPress user ID when the reporter is logged in, and a keyed hash of the IP address used against abuse. The IP address itself is never stored.

Values typed in form fields and passwords are never written into the report. Elements marked `data-ftp-mask`, and elements matching the sensitive selectors you configure, are replaced by "[masked]" in the report. The screenshot is an image of the page as the reporter sees it, shown to the reporter before sending; it is not masked unless you enable "Hide typed fields and sensitive areas in the screenshot" in **Bugs › Settings › Form** (on a real capture, masking applies only when the visitor shares the current tab). Requests to other services (spam check provider, page reconstruction, webhooks) are described under "External services" above.

The plugin adds suggested text to your privacy policy (Settings › Privacy), and registers a personal data exporter and eraser. Handled reports (by default Fixed, Won't fix and Duplicate: every status marked "Done" in **Bugs › Settings › Tracking › Statuses**) can be deleted automatically after a number of months of your choice.

== Changelog ==

= 1.2.0 =
* Configurable priorities (name, color, order).
* Assignment to bug managers, with e-mail, filters and a CSV column.
* Internal notes and change history on each report.
* Webhooks to Slack, Discord, Microsoft Teams or signed JSON.
* Dashboard widget; configurable name of one report ("Bug #12").
* Richer screenshot annotation: pen, text, colors and sizes.
* Settings reorganized in seven tabs, with a summary and an unsaved-changes warning.

Full changelog: see `changelog.txt`.

== Upgrade Notice ==

= 1.2.0 =
Adds configurable priorities, assignment, internal notes and history, a dashboard widget, webhooks, richer screenshot annotation and reorganized settings. The database is upgraded automatically.
