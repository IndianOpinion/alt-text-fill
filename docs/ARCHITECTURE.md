# alt-text-fill: architecture

This document describes how the 0.1.0 build is put together and records
every decision taken while building it from [PRD.md](PRD.md), including
the owner's rulings on the PRD's open questions.

## Modules

All code is in `src/`, namespace `IndianOpinion\AltTextFill`, loaded by
`bootstrap.php` (no Composer at runtime).

| Module | Role |
|---|---|
| `Plugin` | Hooks, activation, cron schedules, maintenance, attachment delete and update hooks, the plugins-screen note |
| `Container` | Builds every service once per request; tests pass a clock, repository, library and logger |
| `Settings` | The settings option: defaults, validation, constants, corrupted detection, API key resolution |
| `State` | Scan cursor and generation, today's counters, circuit breaker, rate-limit wait, kill switch option, run lock |
| `Switches` | The kill switch: constant, unreadable settings, screen or CLI pause, provider-account pause |
| `AltField` | The only reader and writer of the alt meta, the decorative marker and the candidate state meta |
| `EmptyAlt` | Cases 4 and 5: empty and weak alt |
| `Scanner` | Case 1 to 13: candidate decisions, ID-range pages, cursor, scan generation |
| `Context\UsageFinder`, `Context\MediaLibrary`, `Context\WpMediaLibrary` | Case 14 and 15: which posts use an image |
| `Context\ContextBuilder` | Case 16 to 21: the cleaned context, instruction detection, language |
| `Image\ImagePreparer`, `MimeSniffer`, `RemoteFetcher`, `GdScaler`, `ImagickScaler` | Case 22 to 27 |
| `Adapter\VisionAdapter`, `AdapterRegistry`, `OpenAiCompatibleAdapter`, `FakeAdapter` | Section 5.3 |
| `PromptBuilder` | Case 29: the generic prompt and the delimited context block |
| `AnswerParser`, `Validator` | Case 30 and 34 to 40 |
| `Runner` | Case 28, 31 to 33, 59 to 68, 71, 74, 76, 77 |
| `Review` | Case 41 to 50 |
| `Writer` | Case 51, 58, 72, 73: every alt change, with an entry or a revert |
| `Undo` | Case 52 to 57 |
| `Storage\Repository`, `WpdbRepository`, `MemoryRepository`, `Schema` | Section 6 |
| `Fingerprint` | File hash for case 65 and 69 |
| `RenderFill` | Case 60 |
| `Status`, `Logger`, `Redactor`, `Admin\SiteHealth` | Section 11 |
| `Admin\Screens`, `ReviewScreen`, `SettingsScreen`, `AttachmentBox` | Section 5.7 |
| `Cli\Command` | Section 5.6 |
| `Uninstaller` | Section 6, uninstall |

## Data flow of a real run

```text
Runner::run
  assertCanSpend: settings readable, switch off, not dry-run-only,
                  breaker closed, no Retry-After wait, adapter set and valid, caps
  State::acquireLock (atomic add_option; a stale lock is broken and logged)
  Scanner::collect -> Candidate[] (post and meta rows only, from the cursor)
  for each candidate:
      haltReason: settings, switch, breaker, time budget
      [gen-queue opted in and present] -> enqueue job, continue
      ContextBuilder::build -> Context
      ImagePreparer::prepare -> ImageInput or a skip reason
      preCallReason: daily cap, money cap, spend-meter pre-spend
      State::countCall; adapter->describe (one retry on timeout or 5xx)
          account error -> pause "provider account", stop
          429 -> wait for Retry-After, stop "rate limited"
          other -> failure; five in a row -> breaker, stop
      record cost (tokens x operator prices) and spend-meter usage
      AnswerParser + Validator -> pending suggestion, or an invalid row
  save the run record, release the lock (finally)
```

Approval: `Review::approve` checks `edit_post`, pending status, the file
fingerprint (stale), and re-reads the alt (superseded). `Writer::apply`
then writes, reads back, stores the entry, and reverts if the entry
cannot be stored. Undo goes through the same `Writer`.

## Rulings on the open questions (owner, 14 November 2026)

1. **Empty alt string.** An alt row holding an empty string counts as
   never set. Only the plugin's own decorative marker means decorative.
   Reason: WordPress stores an empty string whenever the media form is
   saved with the field blank, so the two cannot be told apart. The
   marker is explicit and undoable; entries still record "absent" and
   "empty string" separately so undo restores exactly what was there.
2. **Animated GIF and WebP** are skipped in 0.1.0 with the logged reason
   "animated image"; first-frame extraction is a roadmap item. Reason:
   first-frame extraction behaves differently across GD and Imagick
   builds, and a wrong frame produces a confidently wrong description.
   Detection reads the bytes (GIF image descriptors, WebP `VP8X`
   animation flag or `ANIM` chunk). The skip holds until the next full
   scan, so a replaced file is looked at again.
3. **Title overlap** applies only to titles of three or more words. For
   one- and two-word titles only an exact match (after normalising)
   rejects. Reason: with a short title, any accurate description may
   share a word with it.
4. **Money cap** uses the token counts the provider reports multiplied by
   prices the operator enters (per million input and output tokens). The
   cap stays off until prices are entered, and no prices ship. Reason:
   a per-call guess drifts with image size and model; reported tokens do
   not. When spend-meter is present it remains the source of real cost.
5. **Base64 and size.** The reference adapter sends base64 only (it
   declares `accepts_url` false). Every image is first downscaled with
   GD or Imagick to 1,024 px on the longer side, then refused when it is
   over 4 MB. Reason: a data URI works for private and development sites,
   which a public URL does not, and image parts as data URIs are part of
   the request shape the reference adapter targets. 4 MB of image is about
   5.4 MB once base64-encoded; a 1,024 px JPEG is normally far below that.
   No live endpoint was called while building 0.1.0 (the tests use a mock
   HTTP layer), so operators should run "Test connection" against their
   own endpoint before the first real run.
6. **No second adapter** in 0.1.0. The reference adapter plus the
   interface is enough; the example in `examples/custom-adapter.php`
   shows a third-party adapter.
7. **Language.** The context post's language wins over the site locale.
   A translation plugin supplies it through the filter
   `alt_text_fill_post_language` (null means unknown); then the site's
   language setting; then the locale. Shared images (no post context)
   use the site setting or locale.

## Builder decisions

Where the PRD left room, these choices were made. Each has a test.

1. **llm-json-php is not used in 0.1.0.** Its 0.1.0 request takes
   message content as a string only, so it cannot carry an image part.
   The reference adapter therefore does one HTTP call through the
   WordPress HTTP API, as section 5.3 asks, and offers the filter
   `alt_text_fill_http_caller`: return a callable
   `fn(string $url, array $args): array|WP_Error` to use your own caller.
   When llm-json-php gains image parts, an adapter on top of it is a
   drop-in through `alt_text_fill_adapters`.
2. **Companion hook names.** spend-meter, gen-queue and prompt-library
   are reached through these names, all remappable with
   `alt_text_fill_integration_hooks`:
   - `spend_meter_pre_spend` filter `(true, 'alt_text_fill.describe', ['calls' => 1])`;
     `false` or a `WP_Error` denies.
   - `spend_meter_record` action `('alt_text_fill.describe', usage array)`.
   - `gen_queue_enqueue` filter `(null, 'alt_text_fill.describe', payload, ['idempotency_key' => ...])`;
     a job ID means queued, null means not handled (the plugin then runs
     the item itself).
   - `gen_queue_run_alt_text_fill.describe` action `(payload)` runs one job.
   - `prompt_library_render` filter `(null, slug, vars)`; a string replaces
     the system prompt.
   Presence is `has_filter()` on the check and enqueue names.
3. **Rejected model answers** are stored as suggestion rows with status
   `invalid` and the reason, so the history is complete; they are never
   approvable and are hidden from the default review view.
4. **A malformed answer** is a rejected answer with reason "malformed": it
   was paid for, counts against the caps and towards parking, but does
   not trip the circuit breaker (the provider answered).
5. **Retries count.** A retried call counts as a second call against the
   daily cap; the retry is skipped when the cap is already reached.
6. **Time budget.** The first item of a run always starts. After that the
   next item does not start when the budget is spent or less than the
   request timeout remains. Without this, the defaults (25 s budget, 30 s
   timeout) would never start anything.
7. **Run lock** is its own option taken with `add_option()`, which is an
   atomic insert, rather than a field inside the state option. A lock past
   its expiry (10 minutes) is broken and logged.
8. **Kill switch storage.** Its own small autoloaded option
   (`alt_text_fill_switch`); the state option is not autoloaded. A truthy
   `ALT_TEXT_FILL_DISABLED` wins over the setting; a false constant does
   not override a pause. Dry runs are allowed with the switch on because
   they make no call.
9. **Money estimate for the next call** is today's average priced call;
   before the first call of the day it is the output token cap times the
   output price. A call whose provider reports no token counts is charged
   at today's average and a warning is logged.
10. **Edited text** is cleaned and must not be empty; it may be up to 250
    characters (the ceiling), not the configured maximum, because a
    person decided. It is not checked against the title.
11. **Remote read cap.** With a scaler available, offloaded files are read
    up to four times the byte limit so ruling 5 can scale them; without
    one, the byte limit itself. Both are enforced while reading.
12. **Skips that wait for the next full scan:** file missing, too large,
    animated image, unsupported type found from the bytes. The state meta
    records the scan generation, which increments when the cursor wraps.
13. **File fingerprint** is the SHA-1 of the local file, or of the URL and
    stored metadata (file, size, dimensions) for offloaded media. A
    mismatch at approval, or on `attachment_updated` and after
    `wp_update_attachment_metadata`, marks pending suggestions stale.
14. **Undo by run and by time skip undo entries.** Otherwise "undo since"
    would re-apply a write a person had already taken back. An undo entry
    can still be undone on its own (case 56).
15. **Weak-alt approval.** When weak-alt mode produced the suggestion, an
    approval is allowed if the alt still equals the weak value seen at
    suggestion time; anything else is "superseded".
16. **WP-CLI writes need `--user`.** Review, decorative and undo check
    `edit_post` per attachment like the screen does, so a command without
    a user exits 1 with a message. A run stopped mid-way by a cap or the
    switch exits 2, the same as one refused at the start.
17. **Review list** is a plain table rather than `WP_List_Table`, so it
    renders and is tested without WordPress. Bulk actions take at most the
    20 IDs of one page.
18. **Render fill for blocks.** The core image block normally saves
    `alt=""` in its markup, which is left alone (it may be deliberate).
    The fill therefore applies to image blocks whose `img` tag has no
    `alt` attribute at all, and to images rendered through
    `wp_get_attachment_image()` with an empty `alt`.
19. **Scaling output** is JPEG at quality 82 on a white background (PNG
    transparency is flattened). The copy is held in memory only.
20. **Opener and refusal lists** ship in English. Other languages are
    added through `alt_text_fill_openers` and
    `alt_text_fill_refusal_patterns`, keyed by language code; `*` applies
    to every language.
21. **The "parked" count** on the status block is a meta search over the
    plugin's own state key, good enough for a status line.
22. **Static analysis** runs at PHPStan level 6 with
    `treatPhpDocTypesAsCertain: false`, because WordPress docblocks
    promise types the runtime does not always keep, and the defensive
    checks stay.

## Testing

- `tests/stubs/` is an in-memory stand-in for the WordPress functions the
  plugin calls, plus a WP-CLI stand-in. `tests/Support/` holds a frozen
  clock, a recording logger and a media library over the stub post store.
- `tests/Unit/` maps each PRD acceptance test (T1 to T78) to methods
  named `testT<n>...`.
- `tests/Integration/` runs `WpdbRepository`, `WpMediaLibrary` and the
  schema check against a real MySQL server when one is configured.
