# alt-text-fill

A WordPress plugin that finds every image in the media library with no alt text, asks a vision model you choose to describe each one from the image and the post around it, and puts every suggestion on a review list. Nothing is written until a person approves it, every write can be undone exactly, and spending is bounded by a dry run, a per-run cap, a daily cap and a kill switch.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured: on MySQL 8.0 with 200,000 attachments, one 500-item scan page took 2 to 9 ms warm and 47 ms cold.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Privacy](#privacy)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Development](#development)
- [Licence](#licence)

## Install

WordPress 6.4 or later, PHP 8.1 or later. GD or Imagick is optional: with one, large images are scaled in memory before sending; without one, images over the byte limit are skipped.

Without Composer (the usual way): copy this directory to `wp-content/plugins/alt-text-fill/` and activate **Alt Text Fill** on the Plugins screen. The plugin has its own autoloader and needs nothing else at runtime.

With Composer:

```sh
composer require indianopinion/alt-text-fill
```

The package type is `wordpress-plugin`, so a Composer installers setup puts it in the plugins directory.

As a must-use plugin: copy the directory to `wp-content/mu-plugins/alt-text-fill/` and copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/`. Must-use plugins are never activated, so create the tables once:

```sh
wp eval '\IndianOpinion\AltTextFill\Plugin::activate();'
```

## Quick start

1. Activate. A new install starts in **dry-run-only** mode: real runs are refused until you turn it off.
2. Settings, **Alt text fill**: choose the **OpenAI-compatible chat completions endpoint** adapter, set the base URL (everything before `/chat/completions`), the model and the API key. Or keep the key out of the database:

   ```php
   // wp-config.php
   define('ALT_TEXT_FILL_API_KEY', 'your key');
   ```

3. Press **Test connection**. It sends one small built-in image and shows the answer. It counts as one call.
4. Press **Dry run now**, or run `wp alt-text-fill run --dry-run`. You see, per image, whether it would be sent, why not, the context text, the size and bytes, and the calls left today. Nothing is called or written.
5. Turn **dry-run-only** off and run `wp alt-text-fill run --user=<editor>` (or **Run now**).
6. Media, **Alt text review**: approve, edit, reject, try again or mark decorative. Only approved text is written.
7. Changed your mind? Settings, **History** tab: undo one entry, a whole run, or everything since a time.

To run on a schedule, tick **Run on a schedule** (every 15 minutes through WP-Cron), or call the CLI from system cron: see [`examples/system-cron.txt`](examples/system-cron.txt).

An offline walk-through of the whole flow, with the fake adapter and no WordPress, runs from a clone:

```sh
composer install && php examples/offline-demo.php
```

## Configuration

Settings live in one option, `alt_text_fill_settings`, edited on the settings screen. Constants win over settings.

| Setting | Default | Notes |
|---|---|---|
| Dry-run-only mode | on for a new install | Real runs refused until off |
| Kill switch | off | Screen, `wp alt-text-fill pause`, or `ALT_TEXT_FILL_DISABLED` (wins) |
| Adapter | none | Real runs refused until one is set |
| Per-run cap | 20 images | `ALT_TEXT_FILL_PER_RUN_CAP` |
| Daily cap | 200 model calls | Site time zone; failed and rejected calls count. `ALT_TEXT_FILL_DAILY_CAP` |
| Prices per million input and output tokens | none | Your own estimate; nothing is shipped |
| Daily money cap | none | Off until prices are entered; uses reported tokens times your prices |
| Schedule | off | 15 minutes when on (`alt_text_fill_cron_interval`) |
| Time budget | 25 s under WP-Cron | None under WP-CLI unless `--time` |
| Target image side | 1,024 px | Sizes and scaling aim here |
| Byte limit | 4 MB | After scaling; larger images are skipped |
| Send image as | base64 | URL only for adapters that accept it, after a 200 HEAD |
| Context length | 1,500 characters | Trimmed at a word |
| Shared image threshold | 3 posts | More users, or listed as a placeholder: no post context |
| Placeholder image IDs | none | Always treated as shared |
| Include non-public | off | Drafts, private, pending and password-protected posts |
| Include unattached | on | No post context |
| Minimum image size | 32 x 32 px | Smaller is skipped "too small" |
| Exclude IDs, MIME types, upload dates | none | |
| Weak-alt mode | off | File names, camera names and filler words count as empty |
| Alt length | 5 to 125 characters | Maximum up to 250 |
| Low confidence threshold | 0.5 | Flag only |
| Confidence to suggest decorative | 0.8 | |
| Language | site locale | The context post's language wins when a filter supplies it |
| Render fill | off | Fills an empty rendered `alt` from the stored value |
| Use gen-queue | off | When gen-queue is installed |
| Find posts by URL | on, 20 posts | Bounded content search |
| Extra hosts for offloaded media | none | The uploads host is always allowed |
| Request timeout, output cap, temperature | 30 s, 200 tokens, 0.2 | |
| Store context text | off | For debugging; expires with the row |

Constants: `ALT_TEXT_FILL_DISABLED`, `ALT_TEXT_FILL_API_KEY` (also read from the environment), `ALT_TEXT_FILL_DAILY_CAP`, `ALT_TEXT_FILL_PER_RUN_CAP`.

## How it works

1. **Scan.** Image attachments are read by ID range, 500 at a time, with a stored cursor that wraps. The scan reads post and meta rows only and never opens a file. An image is a candidate when its alt is missing, empty or only whitespace (including non-breaking and zero-width characters), it is not marked decorative, it is a supported type (never SVG), it passes the exclusions, and it is not held back by an earlier rejection, undo or two unusable answers.
2. **Context.** The posts that use the image are found (parent, featured image, image block ID, `wp-image-<id>` class, file URL), and one is chosen: the public parent, else the newest public user. The context is the caption and description, the post title, two paragraphs either side of the image (or the excerpt) and the nearest heading, with HTML, shortcodes and URLs removed. An image used by more than three posts gets no post context. The file name is never sent.
3. **Image.** The smallest intermediate size at least 1,024 px on the longer side is picked, then scaled in memory to 1,024 px with GD or Imagick, and refused if still over 4 MB. The real type is read from the bytes. Animated GIF and WebP are skipped in 0.1.0 with a logged reason. Offloaded media is fetched from the uploads host (and hosts you list) only.
4. **Call.** Before every call: the switch, the circuit breaker, the daily and money caps, and spend-meter if installed. The request carries the context in a delimited block marked as data. A timeout or 5xx is retried once. A 429 ends the run and waits for `Retry-After`. 401, 402, 403 or a quota error pause the plugin until an administrator resumes it. Five failures in a row pause runs for the rest of the site day.
5. **Validate.** The answer must be one JSON object (a fenced or prose-wrapped object is accepted once). The text is cleaned (quotes, tags, control characters, "An image of" openers) and rejected when empty, too short, a refusal, holding a link or file name, a copy of the post title (titles of three or more words: 80 per cent word overlap) or of the file name. Long text is trimmed at a sentence or word. Flags: names a person, mostly text, may not match the post, low confidence, shared image, trimmed, weak alt replaced, context contained instructions.
6. **Review.** Every suggestion waits on the review list. Before a write the current alt is read again; if someone filled it, the suggestion is superseded and nothing is written. If the file changed since, the suggestion is stale and cannot be approved.
7. **Write and undo.** Each write stores an entry with the exact previous state (absent, or the exact string). If the entry cannot be stored, the write is reverted. Undo restores absent as absent and a string byte for byte, refuses when the value changed since (force is one entry at a time), and is itself an entry.

Data: three tables (`{prefix}alt_text_fill_suggestions`, `_entries`, `_runs`), options `alt_text_fill_settings`, `alt_text_fill_state`, `alt_text_fill_switch` (autoloaded, small), `alt_text_fill_lock` and `alt_text_fill_db`, and two underscored attachment meta keys of the plugin's own. Retention: pending suggestions 30 days (then expired), decided suggestions 90, runs 90, entries 365; filter `alt_text_fill_retention_days`, 0 keeps forever. Undo is possible only while the entry exists.

Uninstall drops the tables, options, own meta and scheduled events. **It never changes or deletes any alt text**, including text this plugin wrote. Undo by time first if those writes should go.

More detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and [`docs/PRD.md`](docs/PRD.md).

## Hooks

Filters: `alt_text_fill_adapters`, `alt_text_fill_mime_types`, `alt_text_fill_is_candidate` (can only exclude), `alt_text_fill_weak_alt_patterns`, `alt_text_fill_context_posts`, `alt_text_fill_context`, `alt_text_fill_post_language`, `alt_text_fill_prompt`, `alt_text_fill_openers`, `alt_text_fill_refusal_patterns`, `alt_text_fill_validate` (can only reject or flag), `alt_text_fill_review_capability` (`upload_files`), `alt_text_fill_admin_capability` (`manage_options`), `alt_text_fill_shared_threshold`, `alt_text_fill_cron_interval`, `alt_text_fill_retention_days`, `alt_text_fill_scaler`, `alt_text_fill_http_caller`, `alt_text_fill_max_pixels` (25 million; larger declared canvases are never decoded).

Actions: `alt_text_fill_run_started`, `alt_text_fill_run_finished`, `alt_text_fill_suggestion_created`, `alt_text_fill_answer_rejected`, `alt_text_fill_written`, `alt_text_fill_undone`, `alt_text_fill_paused`, `alt_text_fill_log`.

PHP functions: `alt_text_fill_is_empty()`, `alt_text_fill_scan()`, `alt_text_fill_run()`, `alt_text_fill_approve()`, `alt_text_fill_reject()`, `alt_text_fill_mark_decorative()`, `alt_text_fill_undo()`, `alt_text_fill_undo_run()`, `alt_text_fill_undo_since()`, `alt_text_fill_status()`, `alt_text_fill_pause()`, `alt_text_fill_resume()`.

A custom adapter implements `IndianOpinion\AltTextFill\Adapter\VisionAdapter` and registers through `alt_text_fill_adapters`; see [`examples/custom-adapter.php`](examples/custom-adapter.php). The reference adapter does its HTTP through `wp_remote_post()`, or through a callable you return from `alt_text_fill_http_caller` if your site has its own caller.

Optional companions, used only when present and never required, through their released 0.1.0 hooks:

- **spend-meter** (contract `SPEND_METER_API` = 1): before every call the `spend_meter_pre_spend` filter gets `null` and an intent array (purpose `alt_text_fill.describe`, caller `alt-text-fill`, provider = adapter ID, model, estimated tokens). `null` back means no meter; a call is made only when `allowed` is exactly `true`. Every attempt, retries and failures included, is recorded with `spend_meter_record` under the same `reservation_id`. A meter with another contract version, or an answer that is not a decision array, denies the call.
- **gen-queue** (opt in): each image is offered to `gen_queue_enqueue_job` as job type `alt_text_fill.describe`, keyed by attachment ID, file hash and the number of answers stored so far, with `on_conflict` `requeue`. The type is registered on `gen_queue_register_types`. A job that meets a cap, the switch or a running run asks the queue to retry later.
- **prompt-library**: with a template slug set, `promptlib_render` renders it with `max_length` and `language`; its system part replaces the built-in system prompt. The user part with the delimited context block stays this plugin's own.

This plugin's own caps and switch apply in every case. Only one spender runs at a time per site: runs, queue jobs and "Test connection" share one run lock.

## WP-CLI

```text
wp alt-text-fill scan [--limit=<n>] [--format=table|json|csv]
wp alt-text-fill run [--limit=<n>] [--dry-run] [--ids=<list>] [--time=<seconds>] [--include-rejected]
wp alt-text-fill review list [--status=<s>] [--flag=<f>] [--format=...]
wp alt-text-fill review approve <id>... [--text=<text>]
wp alt-text-fill review reject <id>... --reason=<wrong|vague|names the wrong person|other> [--note=<n>]
wp alt-text-fill decorative <attachment-id>...
wp alt-text-fill undo <entry-id> [--force] | --run=<run-id> | --since=<datetime> [--yes]
wp alt-text-fill status [--format=...]
wp alt-text-fill pause [--reason=<r>]
wp alt-text-fill resume
wp alt-text-fill history [--attachment=<id>] [--run=<id>]
```

Commands that write need `--user=<login>` of someone who can edit the attachments. There is no "approve everything" option. Exit codes: 0 success, 1 error, 2 refused by a cap or the kill switch, 3 partial (some items refused).

## Privacy

Images, and a slice of the text of the post that uses each one, leave your site for the provider you configure. The settings screen says so beside the adapter choice. Images used only by drafts, private, pending or password-protected posts are not sent unless you include them. API keys are shown as their last four characters only, never logged, and redacted from error text. Logs never hold image bytes or full context. The prompt forbids guessing identity, age, ethnicity, religion, health, gender or emotion, and a suggestion that names a person is flagged for the reviewer.

## Limitations

- The block editor copies alt text into post content when an image is inserted. Changing the attachment's alt does not change posts already written. The opt-in render fill covers images rendered from the attachment and image blocks whose `img` tag has no `alt` attribute at all; an `alt=""` already in a block is left alone because it may be deliberate.
- Only the attachment's alt field. Captions, titles and descriptions are never written.
- Animated GIF and WebP are skipped in 0.1.0.
- One language per call: the context post's language when a translation plugin supplies it through `alt_text_fill_post_language`, else the site setting or locale.
- One bundled real adapter (OpenAI-compatible chat completions with image parts). Others go through the adapter interface.
- The money cap is an estimate from reported tokens and your own prices, not a bill.

## FAQ

**Will it ever write without me?** No. There is no auto-approve and no "approve all" across pages, on screen or in WP-CLI.

**An editor filled the alt while a suggestion waited. What happens?** The approval writes nothing and the suggestion is marked superseded.

**Is an empty `alt` row a deliberate "decorative" choice?** No. WordPress stores an empty string when the media form is saved with the field blank, so an empty row counts as never set. Use **Mark decorative**: it sets the plugin's own marker and is undoable.

**Why did it skip my image?** The dry run and `wp alt-text-fill scan` give the reason per image: unsupported type, too small, not public, file missing, too large, animated image, pending review, rejected or undone, no usable answer, excluded or filtered.

**Does it work with offloaded media or a CDN?** Yes. When the file is not on disk it is fetched from its attachment URL, from the uploads host or hosts you list, with redirects capped and checked.

**What does the kill switch stop?** Every model call, including a run already going (checked before each call). Review writes still work, because they spend nothing.

**What happens on uninstall?** Tables, options, own meta and schedules go; alt text stays. Undo by time first if you want the plugin's writes gone.

## Development

```sh
composer install
composer test          # PHPUnit: unit suite with a WordPress stub layer
composer stan          # PHPStan level 8
composer cs            # PHPCS, PSR-12
```

The integration suite runs the wpdb storage against a real MySQL server when `ALT_TEXT_FILL_TEST_DB_SOCKET` or `ALT_TEXT_FILL_TEST_DB_HOST` is set (plus `_USER`, `_PASSWORD`, `_NAME`, `_PORT`); otherwise it is skipped. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
