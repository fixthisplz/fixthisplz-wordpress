=== Bug Spotter ===
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

Bug Spotter adds a floating button to your site. A visitor (logged in or not) clicks it, points at the element that has a problem or picks "Whole page", describes what went wrong, optionally attaches a screenshot, and submits. The report lands in a dedicated **Bugs** menu of your dashboard, and you get an e-mail. Right after sending, the reporter can download a copy of the report.

Everything is built natively for WordPress: PHP and hooks, a dedicated database table, the WordPress REST API, the Settings API, native admin screens, and plain JavaScript. There is no build step, no CDN and no external service apart from the anti-bot provider and the webhooks you choose. Everything is free: there is no premium version.

= Features =

* Floating button with live preview in the settings: icon (Dashicon or SVG from your media library), shape, size, colors, shadow, corner radius, position (desktop and mobile), z-index.
* Element picker for mouse, touch and keyboard, announced to screen readers; "Whole page" option.
* Accessible modal dialog: description, optional contact e-mail for guests, optional screenshot with preview, privacy notice, and a "Technical details sent with your report" panel that shows exactly what is sent.
* Two screenshot methods: a real capture of the browser tab (exact image; the browser asks the visitor for permission), or a reconstruction of the page (no permission prompt, may differ slightly from the screen). Phones and browsers without screen sharing always use the reconstruction.
* Optional masking of typed fields and sensitive areas in the screenshot.
* Screenshot annotation before sending: rectangles, arrows, freehand strokes, text and hidden areas, drawn with a mouse, a finger or a pen, in six colors and three sizes; select, move, resize, recolor or delete each one; undo, clear all, cancel or apply.
* Download of the report as Markdown, JSON or plain text, plus the screenshot, built in the browser.
* Bugs menu with a filterable and sortable list (status, priority, period, search), bulk actions, CSV export, a Kanban board with drag and drop, and a detail screen. The menu name, the list sub-menu name, the name of one report and the menu position can be changed.
* Assignment, internal notes and a change history on each report (team only): assign a report to a bug manager, who gets an e-mail; filter by person or "My reports"; the CSV export includes the assignee.
* Configurable statuses: name, color, order (also the order of the Kanban columns) and a "Done" flag; add your own or delete one, moving its reports to another status. Defaults: New, Seen, Confirmed, In progress, Fixed, Won't fix, Duplicate.
* Configurable priorities, the same way (name, color, order). Defaults: Low, Normal, High, Critical.
* A dashboard widget shows the bug managers the open reports per status and the latest reports.
* E-mail notification for each new report, with a link to the report and an optional screenshot attachment.
* Webhooks (up to five): post new reports, status changes and assignments to Slack, Discord, Microsoft Teams or your own service (signed JSON), with a "Send a test" button.
* Spam protection: Cloudflare Turnstile, Google reCAPTCHA v2 or v3, hCaptcha, or none; a hidden honeypot field; per-IP rate limits (hourly and daily). Includes a "Test the keys" button.
* Privacy by design: values typed in form fields and passwords are never written into the report; e-mails and tokens are redacted in the browser and again on the server; the IP address is never stored (only a keyed hash); the contact e-mail is stored encrypted; screenshots are stored in a private folder.
* WordPress personal data exporter and eraser, suggested privacy policy text, automatic deletion of closed reports after a delay you choose.
* Loads nothing on pages where the button is not shown. The reporter code is loaded step by step: the small loader first, the rest on the first click.
* Translated into French; translation-ready for any other language.

= External services =

Bug Spotter connects to an external service only when you choose a spam check provider in **Bugs › Settings › Spam protection** (the default is "None"). The provider's script is loaded in the report dialog, and when a report is sent your server sends the visitor's token and IP address to the provider for verification. "Test the keys" in the settings does the same with your own browser.

* Cloudflare Turnstile: [terms](https://www.cloudflare.com/website-terms/), [privacy policy](https://www.cloudflare.com/privacypolicy/).
* Google reCAPTCHA v2 and v3: [terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy).
* hCaptcha: [terms](https://www.hcaptcha.com/terms), [privacy policy](https://www.hcaptcha.com/privacy).

Webhooks (**Bugs › Settings › Alerts › Webhooks**, none by default): your server calls only the addresses an administrator saves there, for the events ticked for each one (new report, status changed, report assigned), right after the change, and when you click "Send a test". Opening a new report (New → Seen) sends nothing. After a bulk action, once all the changes are saved, Slack, Discord and Teams get one summary message (for example "12 reports: status → Fixed", with a link to the list), while the generic JSON gets one signed event per report. Each call sends the report number and title, an excerpt of the description (300 characters), the page address, the status, the priority, the display name of the assignee, a link to the report in your dashboard, the event, the site name and address, and the time. It never sends the reporter's e-mail address, IP address, screenshot or technical context. What the receiving service does with it is covered by its own terms, for example [Slack](https://slack.com/terms-of-service) ([privacy](https://slack.com/trust/privacy/privacy-policy)), [Discord](https://discord.com/terms) ([privacy](https://discord.com/privacy)) or [Microsoft Teams](https://www.microsoft.com/servicesagreement) ([privacy](https://privacy.microsoft.com/privacystatement)).

= Third-party libraries =

* [modern-screenshot](https://github.com/qq15725/modern-screenshot) 4.7.0, MIT licence, bundled unminified in `assets/vendor/` with its licence file. It builds the page reconstruction and is loaded only when that method is used.

== Installation ==

1. Upload the `bug-spotter` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin.
3. Go to **Bugs › Settings**. Bug reporting is disabled by default and, once enabled, is visible to administrators only: tick the roles (and "Guests") who should see the button.
4. In the **Form** tab, choose the screenshot method and whether screenshots are masked.
5. If guests can report, choose a spam check provider in the **Spam protection** tab and use "Test the keys".
6. Check the e-mail recipients in the **Alerts** tab.

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

= How do I test the spam check provider with test keys? =

Each provider publishes keys meant for testing. Enter them in **Bugs › Settings › Spam protection** and use "Test the keys". Look up the current values in the provider documentation; at the time of writing:

* Cloudflare Turnstile: site key `1x00000000000000000000AA` always passes, `2x00000000000000000000AB` always blocks; secret key `1x0000000000000000000000000000000AA` always passes, `2x0000000000000000000000000000000AA` always fails.
* Google reCAPTCHA v2 (checkbox): site key `6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI` with secret key `6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe` always passes.
* hCaptcha: site key `10000000-ffff-ffff-ffff-000000000001` with secret key `0x0000000000000000000000000000000000000000` always passes.
* Google reCAPTCHA v3 has no official test keys: create real keys for a test domain.

Never keep test keys in production: they protect nothing.

= Does it work with page caches? =

Yes. The button's HTML contains nothing that is specific to a user. The report is linked to the logged-in user through the REST nonce when it is valid; if the cached page is older than the nonce, the report is still saved, just without the user. The protection of the form relies on the anti-bot check, the honeypot and the rate limits, not on the nonce.

= What happens to my data if I deactivate or delete the plugin? =

Deactivating keeps everything. Deleting the plugin keeps your reports, screenshots and settings too, unless you ticked "Delete all reports, screenshots and settings when the plugin is deleted" in **Bugs › Settings › Privacy › Uninstall**. In that case the tables (reports, notes and history), the options, the capability and the private folder are removed.

= Does it work on multisite? =

Activate it on each site where you want it. Network uninstall only cleans the main site: on the other sites, deactivate the plugin and tick the deletion option first, or remove the data by hand.

= How does my service check a webhook? =

Choose the "Generic JSON (signed)" format. Each request is a POST with a JSON body and two headers: `X-Bug-Spotter-Event` (`report.created`, `report.status_changed`, `report.assigned` or `test`) and `X-Bug-Spotter-Signature`: `sha256=` followed by the HMAC-SHA256, in hexadecimal, of the raw body with the webhook's signing secret (shown under the webhook in **Bugs › Settings › Alerts › Webhooks**). Compute the same value from the body you received, before decoding it, and compare. In PHP:

`$expected = 'sha256=' . hash_hmac( 'sha256', file_get_contents( 'php://input' ), $secret );`
`$valid    = hash_equals( $expected, $_SERVER['HTTP_X_BUG_SPOTTER_SIGNATURE'] ?? '' );`

Use the `timestamp` field of the body to refuse old requests. Requests are sent once, without waiting for the answer (2-second limit) and without retry. A bulk action sends its requests only after all its changes are saved, one per changed report for the generic JSON: a slow or unreachable service can slow the page down, but never leaves the action half done. Addresses must use https and point to a public server; addresses on a private network are refused. On a site whose environment type (`WP_ENVIRONMENT_TYPE`) is `local` or `development`, `localhost`, `127.0.0.1`, `*.localhost` and `*.test` are also accepted, over http; the `bugspot_allow_dev_webhooks` filter (true or false) overrides that choice.

= Who can manage the reports? =

The roles ticked in **Bugs › Settings › Tracking › Team and menu** receive the `bugspot_manage` capability (administrators by default). It gives access to the list, the Kanban board, the detail screen, screenshots and the CSV export. The Settings screen itself requires `manage_options`, so a bug manager never sees your anti-bot secret keys.

= Does it answer the reporters? =

No. Bug Spotter is not a support system: there is no reply, no discussion thread and no e-mail to the reporter.

== Privacy ==

Bug Spotter stores reports in a dedicated table of your database. A report contains the description written by the reporter, the address of the page (query values are replaced), technical context (browser, language, time zone, window size, the element chosen, and optionally the last console errors and failed network requests), an optional screenshot, an optional contact e-mail address (encrypted), the WordPress user ID when the reporter is logged in, and a keyed hash of the IP address used against abuse. The IP address itself is never stored.

Values typed in form fields and passwords are never written into the report. Elements marked `data-bugspot-sensitive`, and elements matching the sensitive selectors you configure, are replaced by "[masked]" in the report. The screenshot is an image of the page as the reporter sees it, shown to the reporter before sending; it is not masked unless you enable "Hide typed fields and sensitive areas in the screenshot" in **Bugs › Settings › Form** (on a real capture, masking applies only when the visitor shares the current tab). The plugin makes no request to external services, except the one to the anti-bot provider you choose (Cloudflare, Google or hCaptcha), which receives the visitor's token and IP address as required for verification, and the webhooks an administrator sets up. A webhook receives an excerpt of the description written by the reporter, the page address, the status, the priority and the display name of the team member the report is assigned to; never the reporter's e-mail address, IP address, screenshot or technical context.

The plugin adds suggested text to your privacy policy (Settings › Privacy), and registers a personal data exporter and eraser. Handled reports (by default Fixed, Won't fix and Duplicate: every status marked "Done" in **Bugs › Settings › Tracking › Statuses**) can be deleted automatically after a number of months of your choice.

== Changelog ==

= 1.2.0 =
* Configurable priorities (**Tracking › Priorities** settings): name, color, order (sorting by priority follows it); add up to 10 or delete one, moving its reports to another priority. "Normal" cannot be deleted. Priority pills take the chosen color in the list, the Kanban board and the detail screen.
* Name of one report (**Tracking › Team and menu** settings), used in titles such as "Bug #12" on the detail screen and in the notification e-mail.
* Dashboard widget for bug managers: open reports per status and the five latest reports.
* Settings reorganized in seven tabs (General, Button, Form, Tracking, Alerts, Spam protection, Privacy), each split into titled sections. Old links to the former tabs still work.
* Assignment: assign a report to a bug manager from its screen or with a bulk action; "Assigned to" column, initials on the Kanban cards, filter by person and "My reports". The new assignee gets an e-mail, in their own language (not when assigning yourself). The CSV export has an "Assigned to" column.
* Internal notes and history on the report screen: notes visible only to the team (deleted by their author or an administrator), and every change of status, priority or assignment with its author and date. Deleting a user unassigns their reports; the personal data exporter and eraser cover the notes.
* Webhooks (**Alerts › Webhooks** settings): up to five addresses, each with a format (generic signed JSON, Slack, Discord or Microsoft Teams), its events (new report, status changed, report assigned), an on/off switch and a "Send a test" button. Bulk actions send one summary message to chat services, after all their changes are saved.
* Richer screenshot annotation: freehand pen, text, six colors and three sizes (stroke width or text size); select, move, resize, recolor, change the size of or delete each annotation.

= 1.1.0 =
* Screenshot annotation: before sending, the visitor can draw rectangles and arrows on the screenshot, or hide an area, and undo them at any time.
* Fix: after an update, browsers no longer mix old and new script files (WordPress 7.0+).

= 1.0.0 =
* First public release: floating button with live preview, element picker, accessible report dialog, optional screenshot (real tab capture or page reconstruction, optional masking), report downloads, list and Kanban board, detail screen, CSV export, configurable statuses, menu name and position, e-mail notification, anti-bot providers and rate limits, privacy exporter and eraser, automatic purge of closed reports, French translation.

== Upgrade Notice ==

= 1.2.0 =
Adds configurable priorities, assignment, internal notes and history, a dashboard widget, webhooks and richer screenshot annotation. The database is upgraded automatically.

= 1.1.0 =
Adds screenshot annotation and makes updates safe for browsers that cached the previous script files.

= 1.0.0 =
First public release.
