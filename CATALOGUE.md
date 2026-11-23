# Catalogue of reusable code

This file lists what the plugin already provides and what other code can reuse: services, helpers, constants,
options, hooks, REST routes, JavaScript modules, CSS tokens and test helpers. It is not a full API reference.
Private methods and one-off screen code are left out.

**Rule:** before writing code, look here **and** grep the code (`grep -rn "name" includes assets tests`). If
something here already does the job, reuse it. When you add something reusable (a public helper, constant, hook,
option, route, module export, CSS token or shared test helper), add its row here **in the same commit**.

Paths are relative to the plugin root. `path:line` points to the declaration. PHP classes live in the
`FixThisPlz\` namespace and are loaded by `includes/autoload.php` (`FixThisPlz\Admin\Menu` →
`includes/Admin/Menu.php`).

## Contents

1. [Constants](#1-constants)
2. [Storage: options, tables, transients, meta, cron, capabilities](#2-storage-options-tables-transients-meta-cron-capabilities)
3. [WordPress hooks](#3-wordpress-hooks)
4. [REST routes and admin-post actions](#4-rest-routes-and-admin-post-actions)
5. [PHP services and helpers](#5-php-services-and-helpers)
6. [Admin UI helpers (PHP)](#6-admin-ui-helpers-php)
7. [JavaScript](#7-javascript)
8. [CSS](#8-css)
9. [Test helpers](#9-test-helpers)
10. [Maintenance](#10-maintenance)

---

## 1. Constants

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `FIXTHISPLZ_VERSION`, `FIXTHISPLZ_FILE`, `FIXTHISPLZ_DIR`, `FIXTHISPLZ_URL` | `fixthisplz.php:18` | Plugin version, main file, directory path, base URL. | Asset versions (`?ver=`) and paths. Do not hardcode the version. |
| `FIXTHISPLZ_SECRET` (optional, wp-config) | `includes/Secret.php:17` | When defined, the site owner's key replaces the generated `fixthisplz_secret` option. | Read only through `Secret::key()`. |
| `Installer::CAPABILITY` | `includes/Installer.php:11` | `fixthisplz_manage`: the capability to manage bugs. | Every bug-management check. Settings use `manage_options`. |
| `Installer::CRON_HOOK` | `includes/Installer.php:12` | `fixthisplz_daily_purge`: daily retention event. | Scheduling or unscheduling the purge. |
| `Installer::DB_VERSION`, `DB_VERSION_OPTION` | `includes/Installer.php:9` | Schema version (`3`) and the option that stores it. | Bump `DB_VERSION` when the schema in `create_table()` changes. |
| `Reports_Controller::NAMESPACE` | `includes/Rest/Reports_Controller.php:18` | REST namespace `fixthisplz/v1`. | Every route and every `rest_url()` or `apiFetch` path. |
| `Settings::OPTION` | `includes/Settings.php:10` | `fixthisplz_settings`: the single settings option. | Never call `get_option()` on it directly. Use `Settings::get()`. |
| `Settings::MENU_POSITION`, `MENU_LABEL_MAX` | `includes/Settings.php:12` | Default menu position (26) and the maximum label length (40). | Menu settings. |
| `Reports_Repository::MAX_PER_PAGE` | `includes/Reports_Repository.php:12` | Upper bound of `per_page` (200). | Paging over reports, for example exports. |
| `Screenshot_Store::MAX_BYTES`, `MAX_PIXELS`, `JPEG_QUALITY`, `METHODS` | `includes/Screenshot_Store.php:12` | 5 MB, 40 Mpx, quality 85, capture methods `tab` and `dom`. | Upload limits. The JS side mirrors the 5 MB limit in `jpeg.js`. |
| `Activity::NOTE`, `CHANGE`, `NOTE_MAX` | `includes/Activity.php:16` | Activity entry types and the note length limit (5000). | Notes and history. |
| `Choice_List::MAX`, `KEY_MAX`, `FALLBACK_COLOR` | `includes/Choice_List.php:23` | List size (20; 10 for priorities), key length (20), grey for unknown keys. | Statuses and priorities. |
| `Statuses::REQUIRED`, `Priorities::REQUIRED` | `includes/Statuses.php:15`, `includes/Priorities.php:15` | Keys that can never be removed (`new` and `seen`; `normal`). | Do not assume any other status or priority key exists. |
| `Webhooks::MAX`, `FORMATS`, `EVENTS` | `includes/Webhooks.php:14` | 5 webhooks; formats `generic`, `slack`, `discord`, `teams`; events `created`, `status_changed`, `assigned`. | Webhook code and settings. |
| `Captcha\Factory::PROVIDERS` | `includes/Captcha/Factory.php:10` | Anti-bot provider ids. | Validating the provider setting. |
| `Button_Style::MOBILE_QUERY`, `SIZES` | `includes/Frontend/Button_Style.php:16` | Mobile media query `(max-width: 782px)` and the button sizes in px. | Must stay the same in PHP, CSS and the loader (sent as `mobileQuery`). |
| `Report_Changes::FIELDS` | `includes/Report_Changes.php:11` | Editable report fields: `status`, `priority`, `assigned_to`. | Report edits. |
| `Report_Format::PILL_HTML` | `includes/Report_Format.php:39` | `wp_kses()` allow-list for `pill()` markup. | Printing a pill through `wp_kses`. |
| `Menu::SLUG`, `SETTINGS_SLUG` | `includes/Admin/Menu.php:14` | Admin page slugs `fixthisplz` and `fixthisplz-settings`. | Admin URLs. Prefer `Report_Format::link()`/`list_link()` and `Settings_Page::tab_url()`. |
| `Settings_Summary::ACTIVATION`, `WHO`, `SPAM`, `EMAIL`, `WEBHOOKS`, `RETENTION` | `includes/Admin/Settings_Summary.php:21` | Anchor ids of settings sections. | Linking to a settings section. |

## 2. Storage: options, tables, transients, meta, cron, capabilities

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| Option `fixthisplz_settings` | `includes/Settings.php:30` (`defaults()`) | All settings. Top-level keys: `enabled`, `visibility`, `button`, `dialog`, `captcha`, `notifications`, `protection`, `context`, `data`, `management`, `statuses`, `priorities`, `webhooks`. | Read with `Settings::get()`. To add a setting, add its default here and its validation in `Settings_Sanitizer`. |
| Option `fixthisplz_db_version` | `includes/Installer.php:10` | Installed schema version. | Managed by `Installer` only. |
| Option `fixthisplz_secret` | `includes/Secret.php:13` | Generated 32-byte key (unless `FIXTHISPLZ_SECRET` is defined). | Use only through `Secret`. |
| Table `{prefix}fixthisplz_reports` | `includes/Installer.php:14` (`table()`), schema `:81` | Reports. Dates are UTC; `context` holds JSON; `reporter_email` is encrypted; `ip_hash` and `reporter_email_hash` are HMACs. | Access only through `Reports_Repository`. Never display the hash columns. |
| Table `{prefix}fixthisplz_activity` | `includes/Activity.php:22` (`table()`) | Notes and the history of status, priority and assignee changes. | Access only through `Activity`. |
| Transients `fixthisplz_rl_h_{hash16}`, `fixthisplz_rl_d_{hash16}` | `includes/Rate_Limiter.php:41` | Hourly and daily rate-limit windows. | `Rate_Limiter` only. `Uninstaller` deletes them by prefix. |
| User meta `fixthisplz_per_page` | `includes/Admin/Bugs_Page.php:19` | Screen option: rows per page (default 20). | List screen. |
| Cron `fixthisplz_daily_purge` | `includes/Installer.php:25`, handler `includes/Retention.php:15` | Daily purge of closed reports past the retention period. | Scheduled on activation, cleared on deactivation. |
| Capability `fixthisplz_manage` | `includes/Installer.php:44` (`sync_capabilities()`) | Granted to `management.roles` and always to administrators. | Check it with `current_user_can( Installer::CAPABILITY )`. Do not grant it by hand. Saving settings re-syncs it. |
| Private directory `uploads/fixthisplz/` | `includes/Installer.php:55` | Screenshots, protected by `.htaccess` and `index.php`. Not served from the web. | Use `Installer::private_dir()`/`ensure_private_dir()` and `Screenshot_Store`. |

## 3. WordPress hooks

### Fired by the plugin (extension points)

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `fixthisplz_report_created` (action: `$id`) | `includes/Report_Submission.php:158` | Fires after a report is stored. | React to new reports. `Mailer` and `Webhook_Sender` already listen. |
| `fixthisplz_report_changed` (action: `$id, $changes, $user_id`) | `includes/Reports_Repository.php:93`, `:120` | Fires on each real change to status, priority or assignee, including `mark_seen`. `$changes` is `field => [old, new]`. | Change-driven side effects. Fire it only from `Reports_Repository`. |
| `fixthisplz_report_assigned` (action: `$id, $assignee_id, $user_id`) | `includes/Reports_Repository.php:95` | Fires when a report gets a new assignee. | Assignment notifications (`Mailer::on_assigned`). |
| `fixthisplz_allow_dev_webhooks` (filter: `bool`) | `includes/Webhooks.php:95` | Allows webhooks to localhost and `*.test` hosts. Default: `local` or `development` environment. | Local testing of webhooks. |

### Listened to (where to look before adding a callback)

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `plugins_loaded` → `Plugin::boot()` | `fixthisplz.php:28`, `includes/Plugin.php:9` | Runs the schema upgrade and every `register_hooks()`. | Register a new service's hooks from here. |
| Activation and deactivation hooks | `fixthisplz.php:25` | `Installer::activate()`/`deactivate()`. | Table, key, capability, directory and cron setup. |
| `add_option_` / `update_option_fixthisplz_settings` | `includes/Settings.php:20`, `includes/List_Editor.php:22` | Re-syncs capabilities. Moves the reports of deleted statuses or priorities. | Side effects of saving settings. |
| `rest_api_init` | `includes/Rest/*_Controller.php:21` | Registers routes. | New routes go in a controller. |
| `rest_authentication_errors` (priority 101) | `includes/Rest/Reports_Controller.php:23` | Turns a stale nonce on `POST /reports` into an anonymous request. | Only for the public submit route. |
| `wp_enqueue_scripts`, `admin_enqueue_scripts` | `includes/Frontend/Assets.php:16`, `includes/Admin/Menu.php:19` | Front-end loader (also in the admin when allowed) and admin assets. | |
| `deleted_user`, `remove_user_from_blog` | `includes/Team.php:11` | Unassigns the user's reports. | |
| `wp_privacy_personal_data_exporters`/`_erasers`, `admin_init` | `includes/Privacy.php:15` | Personal-data exporter, eraser and privacy-policy text. | Any new personal data must be covered here. |
| `phpmailer_init` (temporary) | `includes/Mailer.php:145` | Adds the plain-text alternative to HTML mail. | |
| `wp_image_editors` (temporary) | `includes/Screenshot_Store.php:87` | Swaps in `Stripping_Imagick_Editor` while a screenshot is re-encoded. | |

## 4. REST routes and admin-post actions

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `POST /fixthisplz/v1/reports` | `includes/Rest/Reports_Controller.php:27` | Public report submission (multipart, optional `screenshot`). The logic is in `Report_Submission::submit()`. | Permission is `__return_true`. Visibility, rate limit and captcha are enforced in `submit()`. |
| `PATCH /fixthisplz/v1/reports/{id}` | `includes/Rest/Reports_Controller.php:36` | Changes `status` and/or `priority` (used by the Kanban). | Requires `fixthisplz_manage`. |
| `GET /fixthisplz/v1/reports/{id}/screenshot` | `includes/Rest/Reports_Controller.php:45` | Streams the private screenshot. | Requires `fixthisplz_manage`. Never link to the file directly. |
| `POST /fixthisplz/v1/captcha-test` | `includes/Rest/Settings_Controller.php:26` | Tests the anti-bot keys as typed in the form. | Requires `manage_options`. Saves nothing. |
| `POST /fixthisplz/v1/button-preview` | `includes/Rest/Settings_Controller.php:35` | Returns the front-end CSS for an unsaved `button` block. | Requires `manage_options`. |
| `POST /fixthisplz/v1/webhook-test` | `includes/Rest/Settings_Controller.php:44` | Sends a test to the saved webhook at `index`. | Requires `manage_options`. |
| `admin_post_fixthisplz_update_report`, `_delete_report` | `includes/Admin/Detail_Page.php:21` | Edit and delete form actions of the detail screen. | Nonce plus `Request::require_manager()`. |
| `admin_post_fixthisplz_add_note`, `_delete_note` | `includes/Admin/Activity_Box.php:14` | Add or delete a note. | Same pattern. |
| `admin_post_fixthisplz_export` | `includes/Admin/Csv_Export.php:16` | CSV export of the filtered list. Build the URL with `Csv_Export::url()`. | |
| Query args `fixthisplz_notice`, `fixthisplz_count` | `includes/Admin/Bugs_Page.php:139` (`notice_args()`) | Admin notice after a redirect. | Reuse `notice_args()` instead of adding new notice arguments. |
| WP_Error codes | `fixthisplz_invalid`, `_not_allowed`, `_disabled`, `_rate_limited`, `_captcha_failed`, `_captcha_unavailable`, `_too_large`, `_bad_type`, `_upload_failed`, `_store_failed`, `_save_failed`, `_not_found`, `_invalid_context`, `_webhook_failed` | Plugin error codes. The JS maps status codes in `dialog-errors.js`. | Reuse an existing code. Use `Report_Submission::invalid()` for 422 and `Screenshot_Store::too_large()` for 413. |

## 5. PHP services and helpers

### Data access

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `Reports_Repository` (instance) | `includes/Reports_Repository.php:10` | All SQL on reports: `insert`, `find`, `find_many`, `query`, `update`, `mark_seen`, `delete`, `count_by_status`, `count_by`, `reassign`, `unassign_user`, `ids_by_email`, `ids_by_user`, `ids_closed_before`. | The only place that writes reports. `update()` validates (`Report_Changes`), records history and fires the hooks. |
| `Reports_Repository::timestamp()`, `now()` | `includes/Reports_Repository.php:278`, `:303` | Converts a stored UTC datetime to a Unix time; returns the current UTC datetime in storage format. | Every date read or write on the tables. |
| `Report_Query::where()`, `priority_order()` | `includes/Report_Query.php:25`, `:15` | Prepared WHERE clause from filters; ORDER BY that follows the configured priority order. | New repository queries. |
| `Report_Changes::from()` | `includes/Report_Changes.php:22` | Validates an edit and returns the real changes, or null when it is invalid. Writes nothing. | Before any status, priority or assignee change. `update()` already calls it. |
| `Activity` | `includes/Activity.php:14` | Notes and history: `add_note`, `find`, `for_report`, `can_delete`, `delete_note`, `record_changes`, `record_moves`, `delete_for_report`, `by_user`, `anonymise_user`. | Notes and history only. `record_*` is called by the repository. |
| `Screenshot_Store` | `includes/Screenshot_Store.php:10` | `store()` validates and re-encodes (metadata stripped); `path()`, `delete()`, `too_large()`, `capture_label()`. | Every screenshot file operation. |
| `Retention::purge_closed_older_than()` | `includes/Retention.php:20` | Deletes closed reports older than N months, with their screenshots and activity. | Retention. Do not write another purge loop. |
| `Installer::table()`, `private_dir()`, `ensure_private_dir()`, `sync_capabilities()` | `includes/Installer.php:14` | Table name, private directory, capability sync. | |

### Settings and choice lists

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `Settings::get()` | `includes/Settings.php:149` | Saved option merged on the defaults, memoised. | The only way to read settings. |
| `Settings::defaults()` | `includes/Settings.php:30` | Shape and defaults of every setting. | The reference for setting keys. |
| `Settings::text( 'section.key' )` | `includes/Settings.php:183` | A text setting, or its translated default when empty. | Labels such as `dialog.title` and `management.menu_label`. Do not add your own fallbacks. |
| `Settings::menu_positions()`, `menu_position()` | `includes/Settings.php:162`, `:178` | Allowed menu positions and validation. | |
| `Settings_Sanitizer::sanitize()`, `merge()` | `includes/Settings_Sanitizer.php:21`, `:44` | `register_setting` callback (posted tab merged on saved values); recursive merge of defaults. | Add the validation for each new setting here. |
| `Settings_Sanitizer::bool()`, `int()`, `enum()`, `text_field()`, `hex_color()` | `includes/Settings_Sanitizer.php:176`–`:204` | Typed scalar sanitizers: checkbox, clamped integer, whitelist, single line, strict `#rgb`/`#rrggbb`. | Any untrusted scalar. Prefer these to new ad hoc casts. |
| `Settings_Sanitizer::with_administrator()` | `includes/Settings_Sanitizer.php:63` | Adds `administrator` to a role list. | Management roles. |
| `Button_Sanitizer::validate()` | `includes/Button_Sanitizer.php:17` | Full validation of the `button` block, safe for inline CSS. | Saving, previewing and printing the button. |
| `Choice_List` → `Statuses`, `Priorities` | `includes/Choice_List.php:12` | Static `all()`, `label()`, `color()`, `is_valid()`, `default_label()`, `sanitize()`; `Statuses::closed()` (`includes/Statuses.php:18`). | All status and priority logic. Never hardcode a list of keys. |
| `List_Editor` | `includes/List_Editor.php:12` | Handles the posted status and priority rows (`apply_post`, `new_key`, `apply_moves`). | Settings list editors only. |
| `Webhooks` | `includes/Webhooks.php:12` | `all()`, `active()`, `sanitize()`, `secret()`, `format_labels()`, `event_labels()`, `dev_hosts_allowed()`, `is_dev_url()`. | Reading and validating webhook rows. |

### Security, privacy, submission

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `Secret::encrypt()`, `decrypt()`, `hmac()`, `key()` | `includes/Secret.php:39`, `:46`, `:57`, `:16` | Sodium secretbox encryption and HMAC-SHA256 with the plugin key. | Any data that must be encrypted or hashed. Do not use the WordPress salts. |
| `Redactor::text()`, `url()` | `includes/Redactor.php:32`, `:52` | Server-side redaction of tokens, e-mails and secrets, with a length bound. Mirrors `assets/js/lib/redact.js`. | Every stored client-supplied text or URL. Change both sides together. |
| `Context_Validator::validate()` | `includes/Context_Validator.php:17` | Closed-schema validation and redaction of the report context. | Accepting the `context` field. |
| `Client_Ip::get()` | `includes/Client_Ip.php:22` | Visitor IP. Uses the configured proxy header only (rightmost valid XFF entry). | Any IP lookup. Store only `Secret::hmac()` of the IP. |
| `Rate_Limiter` | `includes/Rate_Limiter.php:13` | Instance `attempt( $ip_hash )` with hour and day windows; static `bucket( $ip )` (IPv6 grouped by /64). | Throttling. |
| `Captcha\Factory::from_settings()` → `Provider` | `includes/Captcha/Factory.php:13`, `includes/Captcha/Provider.php:7` | Returns a provider: `id()`, `script_url()`, `verify( $token, $ip )`. Fails closed. Shared base: `Siteverify_Provider`. | Anti-bot checks. A new provider extends `Siteverify_Provider`. |
| `Report_Submission::submit()`, `invalid()` | `includes/Report_Submission.php:30`, `:171` | Full new-report flow independent of HTTP; standard 422 error. | Creating reports. The REST controller is a thin layer over it. |
| `Frontend\Visibility::should_load()`, `user_allowed()` | `includes/Frontend/Visibility.php:14`, `:47` | Whether the button loads on this request; whether this user may report. | Any "can this visitor report" question. |
| `Privacy` | `includes/Privacy.php:8` | Exporter and eraser for reports and notes, plus the policy text. | Extend it when new personal data is stored. |
| `Team::members()`, `is_member()`, `name()`, `initials()` | `includes/Team.php:17` | Users with the capability; display name ("Deleted user" when the account is gone); initials. | Assignee lists and author names. |
| `Plugin::debug_log()` | `includes/Plugin.php:32` | Writes `error_log` only when `WP_DEBUG` is on. | Failures the plugin absorbs. Do not call `error_log` directly. |

### Formatting and notifications

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `Report_Format` | `includes/Report_Format.php:8` | `reporter()`, `reporter_label()`, `excerpt()`, `pill()`, `title()`, `link()`, `list_link()`, `site_name()`, `date()`, `author()`, `change()`. | Presenting a report the same way on every screen, in mail and in exports. |
| `Mailer::notify()`, `recipients()`, `uses_admin_email()`, `batch_assignments()` | `includes/Mailer.php:20`–`:80` | New-report and assignment e-mails (HTML plus text). | Wrap bulk assignments in `batch_assignments()`. |
| `Webhook_Sender::send()`, `batch()` | `includes/Webhook_Sender.php:118`, `:53` | Posts an event (signed for generic JSON). `batch()` defers sending until a bulk action ends. | Wrap bulk changes in `batch()`. |
| `Webhook_Payload::data()`, `summary()`, `body()` | `includes/Webhook_Payload.php:25` | Event data with no personal data, and the body for each format. | Building webhook messages. |
| `Frontend\Button_Style::css()` | `includes/Frontend/Button_Style.php:40` | Inline CSS of the floating button. | Front end and the settings preview. |
| `Frontend\Assets::lib_module_ids()` | `includes/Frontend/Assets.php:72` | Registers `assets/js/lib/*.js` as script modules (versioned import map). | Any script that imports lib modules: pass the ids as `module_dependencies`. |

## 6. Admin UI helpers (PHP)

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `Admin\Request::require_manager()`, `deny()`, `posted_id()` | `includes/Admin/Request.php:13` | 403 unless the user is a manager; read a posted id. | Every admin-post handler. |
| `Admin\Settings_Fields` | `includes/Admin/Settings_Fields.php:10` | Escaped form markup: `section`, `heading`, `table_open`/`close`, `row_open`/`close`, `input`, `textarea`, `checkbox` (with hidden 0), `checkbox_item`, `list_marker`, `radio`, `select`, `select_row`, `labelled_number`, `description`, `details_open`/`close`, `advanced_open`/`close`, `show_if`, `name`, `id`, `role_names`, `option`. | Every settings field. Do not write raw form-table HTML. |
| `Admin\Settings_Page::tab_url()`, `current_tab()`, `current_view()`, `guests_unprotected()`, `no_spam_check()` | `includes/Admin/Settings_Page.php:113` | Settings navigation and the guest-protection checks shared with the summary. | |
| `Admin\Report_Query_Args::read_filters()`, `read_sort()`, `filter_query_args()` | `includes/Admin/Report_Query_Args.php:18` | Reads list filters from a request and writes them back as URL arguments. | Shared by the list, the Kanban and the CSV export. |
| `Admin\Report_Context_View::render_pairs()` and the other `render_*()` methods | `includes/Admin/Report_Context_View.php:22` | Escaped key/value table and context blocks of a report. | Showing report context. |
| `Admin\Csv_Export::url()`, `write()`, `safe_cell()` | `includes/Admin/Csv_Export.php:22`, `:62`, `:106` | Export URL, CSV streaming, protection against formula injection. | Any spreadsheet output must use `safe_cell()`. |
| `Admin\Bugs_Page::apply_bulk()`, `notice_args()` | `includes/Admin/Bugs_Page.php:48`, `:139` | Bulk status, priority, assign and delete; notice arguments for the redirect. | |
| `Admin\Kanban::column_limit()` | `includes/Admin/Kanban.php:35` | Cards shown per column (50–1000). | |

## 7. JavaScript

No build step. The front end uses ES modules under `assets/js/lib/`, loaded on demand by the classic
`loader.js`. Admin scripts are classic jQuery IIFEs.

### Globals and config objects

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `window.fixThisPlzConfig` | `includes/Frontend/Assets.php:58` (built in `config()`) | Front-end config: `restUrl`, `nonce`, `moduleUrl`, `vendorUrl`, `button`, `dialog`, `captcha`, `collect`, `sensitiveSelectors`, `i18n`… | Add new front-end settings here. Never put secrets in it. |
| `fixThisPlzConfig.i18n` | `includes/Frontend/Assets.php` (`i18n()`) | Every front-end UI string, translated in PHP. | Add each new UI string here and read it with `translator()`. Do not hardcode English in JS. |
| `window.fixThisPlzSettings` | `includes/Admin/Settings_Assets.php:56` | Settings screen config: REST paths, `option`, `captchaUrl`, `i18n`. | Settings-screen scripts. |
| `window.fixThisPlzKanban` | `includes/Admin/Kanban.php:23` | Kanban config and strings. | |
| `Symbol.for('fixthisplz.diagnostics')` | `assets/js/loader.js:12`, read in `lib/dialog.js:17` | Shared buffer `{console, network}` filled by the loader. | Reading collected diagnostics. |

### Shared utilities

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `make(tag, props, children)` | `assets/js/lib/dom.js:5` | Creates an element; `text`, `class`, boolean and attribute props. | All DOM building in lib modules. |
| `translator(i18n)` | `assets/js/lib/dom.js:24` | Returns `t(key)`, which is `''` when the key is missing. | Every UI string. |
| `UI_ATTR` | `assets/js/lib/dom.js:3` | `data-fixthisplz-ui`: marks plugin UI so it is excluded from capture and inspection. | Put it on every plugin root element. |
| `withTimeout(promise, ms, error)` | `assets/js/lib/async.js:4` | Rejects with `error()` after `ms` and always clears the timer. | Every timed wait. |
| `truncate`, `redactText`, `redactUrl` | `assets/js/lib/redact.js:25`–`:78` | Truncation that never splits a code point; redaction that fails closed. Mirrors `Redactor.php`. | Every text collected from the page. |
| `isEditable`, `sensitiveList`, `matchesAny`, `closestAny`, `FIELD_TAGS`, `KEEP_VALUE_TYPES`, `MASK_FILL` | `assets/js/lib/sensitive.js:6`–`:39` | Rules for typed fields and sensitive areas. | Masking and element description. |
| `trapFocus`, `focusables`, `wrapTarget`, `isProviderFocus` | `assets/js/lib/focus-trap.js:10`–`:48` | Modal focus trap that tolerates captcha iframes. | Any modal. |
| `LocalError`, `serverMessage(status, body, t)` | `assets/js/lib/dialog-errors.js:4`, `:19` | Dialog refusal; maps an HTTP error to a message. | Handling REST errors in the UI. |
| `cssPath`, `describeElement`, `summarizeElement` | `assets/js/lib/element.js:89`–`:250` | Stable CSS path, redacted element description, one-line summary. | Element context. |
| `buildDraft`, `readEnvironment`, `toMarkdown`, `toText`, `toJson`, `reportFileName`, `download`, `DESCRIPTION_MAX` | `assets/js/lib/report.js:6`–`:224` | Report draft and environment; Markdown, text and JSON exports; file download. | Building or exporting a report. `readEnvironment` is the only function that reads the browser. |

### Flow entry points

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `open(config)` | `assets/js/reporter.js:36` | Starts the inspect → dialog flow (one flow at a time). | Called by `loader.js`. |
| `inspect({labels, sensitiveSelectors})` | `assets/js/lib/inspector.js:29` | Element picker for mouse, touch and keyboard. | |
| `openDialog({config, element, onRetakeElement, onClose})` | `assets/js/lib/dialog.js:29` | Report dialog. Markup comes from `buildDialog()` (`lib/dialog-view.js:13`). | |
| `mountCaptcha(container, captchaConfig)`, `CaptchaUnavailableError` | `assets/js/lib/captcha.js:117`, `:10` | Mounts the provider widget; returns `{getToken, reset, destroy}`. | Front end and the settings "Test" button. |

### Screenshot capture

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `takeScreenshot({vendorUrl, method, mask, sensitiveSelectors, signal})` | `assets/js/lib/tab-capture.js:307` | Single entry point: real tab capture, or page reconstruction as fallback. | Call this, not the two methods below. |
| `captureTab`, `selectMethod`, `shouldMask`, `withStream`, `CaptureDeniedError` | `assets/js/lib/tab-capture.js:29`–`:244` | Real capture with `getDisplayMedia`. | |
| `captureVisible`, `createCutFilter`, `rootShift` | `assets/js/lib/capture.js:183`, `:88`, `:157` | Page reconstruction with modern-screenshot (vendor). | |
| `encodeJpeg(canvas)`, `CaptureTooLargeError` | `assets/js/lib/jpeg.js:20`, `:6` | JPEG of 5 MB or less. | Every image sent to the server. |
| `createShotController(...)` | `assets/js/lib/shot-controller.js:26` | Screenshot state in the dialog (take, retake, annotate, drop). | |
| `listMaskTargets`, `measureTarget`, `scaleRects` | `assets/js/lib/mask-targets.js:31`–`:100` | Areas to mask on a real capture. | |
| `cloneMasker`, `important` | `assets/js/lib/clone-mask.js:96`, `:10` | Masking on the reconstructed clone; inline `!important` style. | |
| `createPseudoRepair` | `assets/js/lib/pseudo-repair.js:21` | Fixes `::before`/`::after` in the clone. | |
| `addFamilies`, `crossOriginSheets`, `embedCrossOriginFonts` | `assets/js/lib/capture-fonts.js:23`–`:136` | Embeds cross-origin web fonts in the reconstruction. | |

### Annotation editor

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `editScreenshot({t, original, shapes})` | `assets/js/lib/annotate-editor.js:28` | Opens the editor; resolves to an annotated JPEG or `null`. | Loaded lazily by `shot-controller.js`. |
| `ANNOTATION_COLOR`, `colorOf`, `SIZES`, `DEFAULT_SIZE`, `sizeOf`, `withStrokeSize`, `within`, `normalizeRect`, `inBox`, `distanceToSegment`, `boxHandles` | `assets/js/lib/annotate-common.js:4`–`:45` | Shared geometry and defaults. `within(v, min, max)` is the clamp helper. | Before writing any geometry or clamp helper. |
| `SHAPES`, `handles`, `shapeBounds`, `isTiny`, `hitTest`, `handleAt`, `moveShape`, `moveHandle`, `recolor`, `setSize`, `replaceShape`, `deleteShape`, `startHistory`, `record`, `undo` | `assets/js/lib/annotate-shapes.js:23`–`:94` | Shape registry, immutable list operations, undo history. | A new shape type gets an entry in `SHAPES`. |
| `RECT`, `MASK`, `ARROW`, `arrowHead`; `PEN`, `smoothPath`; `TEXT`, `makeText`, `fontFor`, `fontSizeFor`, `textPadding`, `TEXT_MAX_LENGTH` | `assets/js/lib/annotate-boxes.js`, `annotate-pen.js`, `annotate-text.js` | Shape type implementations. | |
| `lineWidthFor`, `fitSize`, `toNative`, `drawShapes`, `drawSelection` | `assets/js/lib/annotate-draw.js:18`–`:49` | Canvas drawing and coordinate mapping. | |
| `beginDraw`, `beginSelect`, `moveGesture`, `gestureShapes`, `endGesture`, `commitText` | `assets/js/lib/annotate-gesture.js:11`–`:70` | Pointer gesture state machine. | |
| `buildEditorView`, `pressOnly`; `openTextField` | `assets/js/lib/annotate-view.js:36`, `:27`; `annotate-text-field.js:19` | Editor markup and the inline text input. | |

### Admin scripts (classic, jQuery)

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `admin-settings.js` | `assets/js/admin-settings.js` | `show_if` conditions, icon and SVG pickers, live button preview, warning about unsaved changes. | Fields marked with `Settings_Fields::show_if()` work automatically. |
| `admin-captcha-test.js`, `admin-webhooks.js` | `assets/js/` | "Test" buttons for the anti-bot service and for webhooks (inline WordPress notices). | |
| `admin-lists.js` | `assets/js/admin-lists.js` | Status and priority row editor (sortable, add, delete with move). | |
| `admin-kanban.js` | `assets/js/admin-kanban.js` | Drag and drop between columns, then `PATCH /reports/{id}`. | |

## 8. CSS

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `--fixthisplz-bg`, `-fg`, `-muted`, `-border`, `-accent`, `-accent-fg`, `-danger`, `-surface`, `-focus`, `-backdrop`, `-ring-dark`, `-ring-light`, `-radius`, `-font` | `assets/css/front.css:10` (dark mode `:11`) | Front-end design tokens, scoped to `.fixthisplz-ui`. | All front-end UI colors. Never use raw colors. |
| `.fixthisplz-ui` + `[data-fixthisplz-ui]` | `assets/css/front.css:10`–`:16` | Root that resets the theme's styles (`all:initial`). | Wrap every front-end overlay in it. |
| `.fixthisplz-btn`, `-btn-primary`, `-btn-link` | `assets/css/front.css:19` | Front-end buttons. | |
| `.fixthisplz-capture-hide` | `assets/css/front.css:95` | Hides plugin UI during a real capture. | |
| `--fixthisplz-color` + `.fixthisplz-pill` (`.fixthisplz-status`, `.fixthisplz-priority`) | `assets/css/admin.css:9`; markup from `Report_Format::pill()` | Pill tinted from a choice color. | Every status or priority badge in the admin. |
| `.fixthisplz-muted` (inside `.fixthisplz-admin`) | `assets/css/admin.css:8` | Secondary text. | |
| `.fixthisplz-wide`, `-short`, `-medium`, `-sub`, `-inline-fields`, `-axis`, `-warning` (inside `.fixthisplz-settings`) | `assets/css/admin.css:32`–`:40`, `:99` | Settings field widths, sub-blocks, inline fieldsets, warning line with icon. | Settings markup. |
| `.fixthisplz-advanced`, `.fixthisplz-disclosure`, `.fixthisplz-summary` | `assets/css/admin.css:83`–`:96` | Folded advanced block, `<details>`, and the summary grid. | Used through `Settings_Fields::advanced_open()`/`details_open()`. |
| `.fixthisplz-pairs`, `.fixthisplz-entry` (inside `.fixthisplz-detail`) | `assets/css/admin.css:11`, `:73` | Key/value table; timeline entry. | Detail screen. |

## 9. Test helpers

PHP tests run with `tests/php/run.php`, which loads every `test-*.php` file in the same process, so helpers
defined in one test file are available in the others. JS tests use `node --test`.

| Name | Location | What it does | When to use / not use |
|---|---|---|---|
| `fixthisplz_test( $name, $fn )` | `tests/php/lib.php:4` | Registers a test. Each test runs in a rolled-back transaction. | Every PHP test. |
| `assert_same`, `assert_true`, `assert_wp_error` | `tests/php/lib.php:9`–`:21` | Assertions. | |
| `with_settings_option( $fn )` | `tests/php/lib.php:34` | Isolates `fixthisplz_settings` and restores it afterwards. | Any test that writes settings. |
| Offline network guard | `tests/php/lib.php:49` | Any un-stubbed HTTP request fails. | Stub with `pre_http_request` (see `with_fake_http`). |
| `fixthisplz_admin_insert( $description, $extra )` | `tests/php/test-admin-list.php:13` | Inserts a report through the repository. | The default report factory (used in 7 files). |
| `repo_make( $repo, $marker, $row, $overrides )` | `tests/php/test-repository.php:8` | Inserts a report and can force raw column values (dates, status). | When columns must bypass validation. |
| `fixthisplz_capture( $fn )` | `tests/php/test-admin-detail.php:10` | Captures printed output. | Testing render methods. |
| `with_fake_http( $responder, $fn )`, `fake_json( $body, $code )` | `tests/php/test-protection.php:11`, `:25` | Stubs HTTP and records calls; fake JSON response. | Captcha and outbound HTTP. |
| `webhook_requests( $fn, $answer )`, `webhook_env`, `webhook_row` | `tests/php/test-webhooks.php:58`, `:28`, `:41` | Records webhook posts; webhook settings fixtures. | Webhook tests. |
| `activity_mails( $fn )`, `activity_user( $role, $manager, $name )` | `tests/php/test-activity.php:62`, `:21` | Captures `wp_mail` calls; creates a team member. | E-mail and assignment tests. |
| `submission_env( $fn, $over )`, `submission_input( $over )` | `tests/php/test-submission.php:12`, `:41` | Settings for submission tests; valid submission input. | `Report_Submission` tests. |
| `rest_enable`, `rest_post`, `rest_upload`, `rest_png`, `rest_user`, `rest_env` | `tests/php/test-rest.php:8`–`:85` | REST request helpers. | REST tests. |
| `settings_page_test`, `settings_page_tab_html`, `settings_page_form_post` | `tests/php/test-settings-page.php:73`, `:66`, `:14` | Renders a settings tab and posts its form as the browser would. | Settings screen tests. |
| `list_editor_rows`, `list_editor_save`, `status_editor_test` | `tests/php/test-list-editor.php:13`–`:33` | Status and priority editor fixtures. | |
| `shot_png( $w, $h )`, `shot_tmp( $bytes )` | `tests/php/test-screenshot.php:15`, `:8` | Test PNG image; temporary upload file. | Screenshot tests. |
| `doc`, `el(tag, attrs, children)`, `serialize(node)` | `tests/js/fake-dom.mjs:2`–`:63` | Minimal fake DOM (no jsdom) for clone and mask tests. | JS tests that need a cloned tree. |

## 10. Maintenance

- **Before writing code:** search this file, then grep the code. Reuse what exists. If something here is almost
  right, extend it rather than adding a near-copy.
- **When you add something reusable** (a public method of a shared class, a constant, option, table, hook, REST
  route, error code, JS export, CSS token or utility class, or a test helper used by more than one file), add or
  update its row here **in the same commit**.
- **When you rename or remove** something listed here, update or delete its row in the same commit.
- Keep each row to one line. Leave out private methods and code used by a single screen.
