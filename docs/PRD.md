# alt-text-fill: product requirements

Status: draft for build. Target release 0.1.0.

A WordPress plugin. It finds every image in the media library that has no
alt text, asks a vision-capable model to describe each one using the image
and the text of the post around it, and puts each suggestion on a review
list. Nothing is written until a person approves it. Every write keeps the
previous value so it can be undone. It has a dry run, a per-run cap, a
daily cap and a kill switch.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

Alt text is what a screen reader says in place of a picture, and what a
search engine or an assistant reads when it cannot see the picture. Most
media libraries are full of images with none: pictures uploaded in a hurry,
imported in bulk, or added by tools that never set the field. Fixing them by
hand is slow, so it does not happen.

The obvious shortcuts go wrong in predictable ways:

- Copying the post title into the alt field. The title describes the story,
  not the picture. On a news site the picture is often a file photo, a
  logo slate or even a picture from a different story, so a title as alt
  text is confidently wrong. Alt text that was filled with the headline
  proves nothing about what the image shows.
- Copying the file name. `IMG_4021.jpg` or `final-v2-crop.png` tells a
  reader nothing.
- Running a model over the whole library and writing whatever comes back.
  Models refuse, wrap answers in prose, start every line with "An image
  of", guess who a person is from their face, and repeat the headline. A
  bulk write with no review and no undo turns a missing field into a wrong
  one at scale, and there is no way back.
- Giving one shared image (a default placeholder, a site logo, a branded
  slate used on many posts) a description taken from one of those posts.
  Every other post that uses it then carries a wrong description.

Measured result: none is quoted here. The builder records one measured line
in the README from a test library (images processed, share approved without
edits, share rejected), with no costs.

## 2. Goals and non-goals

Goals:

1. Find every image attachment whose alt text is missing or blank, in
   batches that stay fast on a library of hundreds of thousands of items.
2. Build a short, clean context for each image from the posts that use it.
3. Ask any vision-capable model, through an adapter interface, for a short
   description in a fixed JSON shape, using a generic prompt.
4. Validate every answer and put it on a review list. Write nothing until a
   person approves, edits or rejects it.
5. Keep the previous value of every write and offer undo for one item, one
   run, or everything since a point in time.
6. Offer a dry run that shows exactly what a real run would do, without
   calling the model or writing anything.
7. Bound spend with a per-run cap, a daily cap and a kill switch of the
   plugin's own, and work with `spend-meter` when it is present.
8. Run in the background or from WP-CLI, never on a front-end request.

Non-goals:

- Rewriting alt attributes already stored inside post content. The block
  editor copies alt text into the post content when an image is inserted,
  and changing the attachment does not change that copy. 0.1.0 writes the
  attachment's own alt field only, plus an opt-in render-time fill (case
  60). Editing post content is a later version, if ever.
- Captions, titles and long descriptions of attachments. Only alt text.
- Images that are not media library attachments: theme files, images
  generated on the fly, images hot-linked from other hosts.
- Identifying people from their faces. Never.
- Translating existing alt text. One language per site in 0.1.0.
- Writing alt text without a human approval. There is no auto-approve.
- Shipping or describing an adapter for any vendor plugin. Adapters talk to
  model APIs through their official, documented interfaces under the
  operator's own credentials.

## 3. Users and setting

- **Site administrator** installs it, adds an API key, sets caps and runs
  the first dry run.
- **Editors** work through the review list: approve, edit, reject, mark as
  decorative.
- **Operators** run it from WP-CLI on a schedule they control, read the
  status, and use undo after a mistake.

Runs on WordPress 6.4 or later, PHP 8.1 or later, single site or multisite
(per site). Works behind a page cache and a CDN because it never runs on a
front-end request, except the opt-in render fill, which only reads a value
already stored. Works with media offloaded to object storage, by fetching
the image over HTTP when the file is not on local disk.

## 4. Behaviour

Terms used below:

- **Empty alt**: the attachment has no alt value, or the value is an empty
  string, or it is only whitespace (including non-breaking spaces and
  zero-width characters).
- **Candidate**: an image attachment with empty alt that is not excluded.
- **Suggestion**: a validated model answer waiting on the review list.
- **Run**: one pass that takes up to the per-run cap of candidates and
  produces suggestions.
- **Write**: setting an attachment's alt value after an approval.
- **Entry**: one history row that records a write and the value before it.

### 4.1 Finding candidates

1. A candidate is an attachment with a MIME type in the allowed list,
   default `image/jpeg`, `image/png`, `image/gif`, `image/webp`,
   `image/avif`. The list is a filter.
2. SVG is never a candidate. It can carry script, and most vision APIs do
   not accept it. It is counted as "skipped: unsupported type" in scans.
3. HEIC, TIFF, BMP and ICO are skipped as unsupported unless the active
   adapter declares that it accepts them, or WordPress has a JPEG
   intermediate size for the file, in which case that size is sent.
4. Alt that is missing, empty or whitespace only is empty. Nothing else is,
   by default.
5. Optional "weak alt" mode (off by default): alt that equals the file
   name (with or without extension, with separators turned into spaces),
   that matches a camera pattern (`IMG_1234`, `DSC01234`, `PXL_...`,
   `Screenshot 2026-...`), or that is one of a short list of filler words
   (`image`, `photo`, `picture`, `img`, `untitled`, `placeholder`) is also
   treated as empty. The list is a filter. A weak-alt suggestion shows the
   current value prominently on the review list, and undo restores it.
6. An attachment marked decorative (case 46) is never a candidate.
7. An attachment whose alt was written, rejected or undone by this plugin
   is not picked again by a scheduled run unless the operator asks
   (`--include-rejected`, or "Try again" on the item). Rejection is a
   human decision and is remembered.
8. Exclusion by parent post status: by default, images attached to or used
   only by posts that are not public (draft, pending, private, trashed,
   password-protected) are skipped with reason "not public", so private
   material is not sent to a third party. A setting includes them.
9. Unattached images (no parent and no use found) are candidates, with no
   post context. A setting excludes them.
10. Exclusion by attachment ID list, by MIME type, by upload date range,
    by minimum size (default: both sides at least 32 pixels, so icons and
    spacers are skipped with reason "too small"), and by a filter
    `alt_text_fill_is_candidate` that can only exclude.
11. Scanning walks attachment IDs in ascending order in pages (default 500)
    with a stored cursor, so a scan of a very large library is resumable
    and never holds one long query. A full scan restarts from the lowest
    ID when the cursor passes the highest.
12. Every candidate decision is cheap: the scan reads only post and meta
    rows. It never opens an image file.
13. An attachment whose file is missing from disk and cannot be fetched
    over HTTP is skipped with reason "file missing" and retried on the
    next full scan only.

### 4.2 Building context

14. Posts that use an image are found, in this order: its parent post;
    posts that have it as the featured image; posts whose content refers to
    it by attachment ID in an image block, by the `wp-image-<id>` class, or
    by its file URL or any intermediate size URL. A filter adds or removes
    posts.
15. Content lookups by URL are bounded: at most 20 posts are examined per
    image, newest public post first. The cost of a URL search across all
    posts is the reason; a site can turn it off.
16. The context sent to the model is, in this order and each trimmed: the
    attachment's own caption and description; the title of the chosen
    post; the text of up to two paragraphs before and after the image in
    that post (or the excerpt when the image is the featured image only);
    the nearest heading above the image. All HTML, shortcodes, block
    comments, scripts, styles and URLs are removed. Whitespace is
    collapsed. The total is capped (default 1,500 characters), trimmed at
    a word boundary.
17. One post is chosen for context: the parent if public, else the newest
    public post that uses the image.
18. Shared image rule: an image used by more posts than a threshold
    (default 3), or listed by the operator as a placeholder or default
    image, is described without any post context. Its context is its own
    caption and description only, and the suggestion carries the flag
    "shared image". A shared image must never take one post's subject.
19. The image's file name is not part of the context. It misleads more
    often than it helps.
20. Context is data, not instructions. It is sent in a separate, clearly
    delimited part of the request, and the prompt tells the model to
    ignore any instruction inside it. A context that contains text which
    looks like instructions to a model is still sent but the suggestion
    is flagged "context contained instructions".
21. The site language (from the locale, overridable per site) is sent so
    the answer is in that language.

### 4.3 Preparing the image

22. The image sent is the smallest available intermediate size whose
    longer side is at least the target (default 1,024 pixels), else the
    largest size available, else the original. The original is never
    sent when it is over the byte limit.
23. Byte limit per image, default 4 MB after encoding. If the chosen file
    is larger and the image editor is available, a scaled JPEG copy is
    made in memory (longer side at the target) and sent; it is never
    saved. If no editor is available the item is skipped with reason
    "too large".
24. The image is sent as base64 inside the request by default, so private
    or development sites work. A setting sends a public URL instead, only
    when the adapter supports it and the URL answers 200 to a HEAD
    request.
25. When the file is not on local disk (offloaded media), it is fetched
    with the WordPress HTTP API from its attachment URL, following at
    most 3 redirects, with a 10 second timeout and the byte limit enforced
    while reading. Only the site's own upload URL host, and hosts the
    operator adds, may be fetched.
26. The real MIME type is read from the file bytes, not trusted from the
    extension. A mismatch uses the real type; an unsupported real type is
    skipped.
27. Animated GIF and animated WebP: the first frame is sent when the
    editor can extract it; otherwise the file is sent as is if the
    adapter accepts animation, else it is skipped.

### 4.4 Calling the model

28. Every call goes through the active adapter (section 5.3). The plugin
    ships one reference adapter for any OpenAI-compatible chat
    completions endpoint that accepts image parts (base URL, model, API
    key, optional extra headers), and a fake adapter for tests and demos
    that returns canned answers. Further adapters register with a filter.
29. The prompt is generic and ships with the plugin. It asks for: what is
    visible, in one or two short sentences; the most important subject
    first; text in the image summarised (not transcribed) when it matters;
    no "image of", "photo of" or "picture of" opener; no file names, no
    URLs, no hashtags, no emoji; no guesses about identity, age, ethnicity,
    religion, health, gender or emotion; a person named only when the
    caption or post text names them and the picture plainly shows a single
    person consistent with that; the post title never copied; and the
    JSON shape below. The prompt is overridable by a filter and, when
    `prompt-library` is present, by a template slug the operator chooses.
30. The answer shape is a JSON object:
    - `alt` (string): the description, or empty when decorative;
    - `decorative` (boolean): true when the image carries no information
      (a divider, a spacer, a background texture);
    - `contains_text` (boolean): the image is mostly text, a chart, a
      screenshot or a document;
    - `names_person` (boolean): `alt` names a person;
    - `matches_context` (boolean): what is visible fits the post text;
    - `confidence` (number from 0 to 1).
31. Per call: timeout default 30 seconds, output token cap default 200,
    temperature default 0.2. One retry on a timeout or a 5xx answer within
    the same run. A 429 answer honours `Retry-After` and ends the run
    early with stop reason "rate limited".
32. Authentication or account errors (401, 402, 403, or the provider's
    documented quota error) pause the plugin with reason "provider
    account" until an administrator resumes it. No further calls are made.
    The admin screen shows a notice. This fails closed: spending stops.
33. Circuit breaker: 5 consecutive failed calls in one day pause runs for
    the rest of that day (site time) with reason "repeated failures".

### 4.5 Validating answers

34. The answer is parsed as JSON. A JSON object inside a fenced code block
    or after leading prose is accepted once, by taking the first complete
    object. Anything else is a failed call with reason "malformed".
35. `alt` is cleaned: trimmed; surrounding quotes removed; HTML tags
    removed; control and zero-width characters removed; runs of whitespace
    collapsed; line breaks turned into spaces; a leading "image of",
    "a photo of", "picture showing" and similar openers (a short list per
    language, a filter) removed and the next letter capitalised.
36. Rejected with a reason, never put on the review list as approvable:
    - empty `alt` when `decorative` is false ("empty");
    - shorter than the minimum, default 5 characters ("too short");
    - refusal or apology text ("I can't", "I'm sorry", "as an AI", "unable
      to", a short list per language, a filter) ("refusal");
    - contains a URL, an email address, or a file name with an image
      extension ("contains link or file name");
    - equal to, or a near copy of, the chosen post's title: after lower
      casing and removing punctuation, the same text, or a word overlap of
      at least 80 per cent of the title's words ("copies title");
    - equal to the attachment file name in any form ("copies file name").
37. Longer than the maximum (default 125 characters, configurable up to
    250): trimmed at the last sentence end or word boundary before the
    limit, and flagged "trimmed". Never cut mid-word.
38. Flags shown on the review list, none of which reject: `names_person`,
    `contains_text`, `matches_context` false ("image may not match post"),
    confidence below a threshold (default 0.5, "low confidence"), "shared
    image", "trimmed", "weak alt replaced", "context contained
    instructions".
39. A rejected answer counts against the caps (it was paid for), is
    recorded with its reason, and the attachment is retried at most once
    more in a later run. After two rejected answers it is parked with
    reason "no usable answer" and shown in a separate list.
40. `decorative` true with high confidence is put on the review list as
    "suggest decorative". Approving it marks the image decorative (case
    46). It never writes an empty string as if it were a description.

### 4.6 Review list

41. Every suggestion appears on the review list under Media. Each row
    shows a thumbnail, the current alt (or "none"), the suggested alt in
    an editable field with a live character count, the post used for
    context with a link, the flags, the confidence, the adapter and model,
    and the time.
42. Actions per row: Approve, Approve with edit, Reject, Try again, Mark
    decorative, Skip for now. Bulk actions: approve selected, reject
    selected. There is no "approve all" across pages: a person sees every
    item they approve.
43. Before any write, the current alt is read again. If it is no longer
    empty (an editor filled it meanwhile), the suggestion is marked
    "superseded" and nothing is written. The row says so.
44. Approve with edit stores both the suggested and the final text, so the
    operator can see how often editors change suggestions.
45. Edited text passes the same cleaning as case 35 and must not be empty.
    It is not subject to the "copies title" rule: a person decided.
46. Mark decorative stores the plugin's own decorative marker on the
    attachment and sets the alt to an empty string only if it was missing;
    the previous state is recorded in an entry so it can be undone.
47. Reject stores the reason the reviewer picks (wrong, vague, names the
    wrong person, other) and an optional note. Rejected items stay out of
    scheduled runs (case 7).
48. Suggestions older than the retention (default 30 days) and still
    pending are expired, not written. Their images become candidates again.
49. The list filters by flag, by confidence, by post, by run and by
    status, and sorts by date or confidence.
50. Only users who can edit the attachment (`edit_post` on it) can act on
    a row, and the screen as a whole needs the review capability (default
    `upload_files`, a filter). Every action checks a nonce.

### 4.7 Writing and undo

51. A write sets the attachment's alt value to the approved text and
    creates one entry: attachment ID, previous state (absent, or the exact
    previous string), new value, action (approve, approve-edited,
    decorative, undo), user, time, run ID, suggestion ID.
52. Undo of one entry restores the previous state exactly: it deletes the
    alt value when it was absent before, and restores the exact string
    when one existed, including whitespace.
53. Undo checks the current value first. If it no longer equals the value
    the entry wrote, undo refuses with "changed since", shows both values,
    and changes nothing. A `--force` flag on WP-CLI and a confirm step on
    screen override this for one entry at a time.
54. Undo by run reverses every entry of that run, newest first, applying
    case 53 to each, and reports how many were restored, refused and
    already undone.
55. Undo since a time reverses every write made by the plugin after that
    time on this site, with the same rules.
56. An undo is itself an entry, so an undo can be undone.
57. Undoing a write puts the image back as a candidate but marked so that
    scheduled runs skip it (case 7); the operator chooses to try again.
58. Writes and undos never touch post content, captions, titles,
    descriptions or any other field.

### 4.8 Runs, dry run and caps

59. Dry run: performs the scan and context steps for up to the per-run cap
    and reports, per image, whether it would be sent, the reason if not,
    the context that would be sent, the image size that would be sent and
    its byte count, and the estimated calls against today's remaining cap.
    It calls no model and writes nothing except the run record. A new
    install starts in "dry run only" mode: real runs are refused until an
    administrator switches it off.
60. Render fill (opt-in, off by default): when on, images rendered through
    WordPress's attachment image functions with an empty `alt` attribute
    take the attachment's stored alt at render time, and image blocks with
    no `alt` attribute at all do the same. An `alt=""` written in the block
    is left alone, because it may be deliberate.
61. Per-run cap: default 20 images. Each run stops at the cap, at the time
    budget (default 25 seconds under WP-Cron, none under WP-CLI unless
    given), or when a cap or switch says stop.
62. Daily cap: default 200 model calls per site per day, counted in the
    site's time zone, including failed and rejected calls. Optional money
    cap: the operator sets an estimated cost per call in their own
    currency; the run stops when the estimate for the next call would pass
    the cap. Both are checked before every call.
63. Kill switch: a setting on the screen, a WP-CLI command, and a constant
    `ALT_TEXT_FILL_DISABLED`. When set, no call is made and no run starts.
    A run in progress checks it before every call and stops. The constant
    wins over the setting. Writes from the review list still work when the
    switch is on, because they spend nothing.
64. When `spend-meter` is present, every call is first checked with its
    pre-spend filter (purpose `alt_text_fill.describe`) and recorded with
    its record action afterwards. A deny stops the run with stop reason
    "spend meter". The plugin's own caps and switch apply as well; the
    stricter wins.
65. When `gen-queue` is present and the operator opts in, each image is a
    job of its own type, enqueued through gen-queue's soft enqueue filter
    with an idempotency key of the attachment ID and file hash. Otherwise
    the plugin uses its own runner. Caps of this plugin still apply.
66. Only one run at a time per site. A lock with an expiry (default 10
    minutes) is taken before work starts and released in a `finally`. A
    stale lock is broken after expiry and logged.
67. Scheduling: off by default. When on, a WP-Cron event runs every
    15 minutes (a filter), and the site can turn WP-Cron off and call the
    WP-CLI command from system cron instead.
68. A run records: ID, start, end, mode (dry or real), trigger (cron, CLI,
    screen), counts (considered, skipped by reason, sent, suggestions,
    rejected answers, failures), stop reason, user.
69. An image that changes file (replaced media) after a suggestion was
    made has its suggestion marked "stale" by comparing a stored file
    hash; stale suggestions cannot be approved and the image becomes a
    candidate again.
70. Deleting an attachment deletes its pending suggestions; its entries are
    kept until retention so the history stays complete.

### 4.9 Failure modes

71. Model unreachable or slow: the item fails, counts against the daily
    cap, and is retried in a later run. The run continues with the next
    item until the circuit breaker (case 33).
72. Database write fails on approval: the reviewer sees an error, nothing
    is reported as done, no entry is created, and the suggestion stays
    pending. Fail closed.
73. Entry write fails after the alt write succeeded: the alt write is
    reverted to the previous state and the reviewer sees an error. A write
    without an entry is never left in place, because it could not be
    undone.
74. Adapter throws an unexpected exception: caught, logged with the class
    name and a redacted message, counted as a failure. Never a fatal error
    in a cron run.
75. The image editor is missing (no GD or Imagick): images within the byte
    limit are still sent as they are; larger ones are skipped (case 23).
    A Site Health notice says so.
76. API key missing or empty: real runs refuse to start with a clear
    message; dry runs still work.
77. Settings corrupted or unreadable: defaults apply, the kill switch is
    treated as on, and a notice asks the administrator to save settings.
    Fail closed: no spend on a configuration the plugin cannot read.

## 5. Public interface

Names are this project's own. Namespace `IndianOpinion\AltTextFill`.
Global functions, hooks, options and meta keys use the prefix
`alt_text_fill_` (meta keys start with an underscore so they are hidden
from the custom fields box).

### 5.1 PHP functions

| function | returns | notes |
|---|---|---|
| `alt_text_fill_is_empty(int $attachment_id)` | bool | Case 4, and case 5 when weak-alt mode is on |
| `alt_text_fill_scan(array $args = [])` | `ScanReport` | args: `limit`, `after_id`, `include_rejected`, `mime_types` |
| `alt_text_fill_run(array $args = [])` | `RunReport` | args: `limit`, `dry_run`, `time_budget`, `ids`, `trigger` |
| `alt_text_fill_approve(int $suggestion_id, ?string $edited = null)` | `Entry` | Throws on conflict, stale or permission |
| `alt_text_fill_reject(int $suggestion_id, string $reason, string $note = '')` | void | |
| `alt_text_fill_mark_decorative(int $attachment_id)` | `Entry` | |
| `alt_text_fill_undo(int $entry_id, bool $force = false)` | `UndoReport` | |
| `alt_text_fill_undo_run(string $run_id)` | `UndoReport` | |
| `alt_text_fill_undo_since(int $timestamp)` | `UndoReport` | |
| `alt_text_fill_status()` | array | counts, today's use against caps, switch state, last run |
| `alt_text_fill_pause(string $reason = 'manual')` / `alt_text_fill_resume()` | void | The kill switch setting |

### 5.2 Objects

Read-only value objects: `Candidate` (attachment ID, MIME, width, height,
file hash, context post ID, shared flag), `Context` (text, language,
post ID, shared flag, instruction-like flag), `ImageInput` (bytes or URL,
MIME, width, height, byte count), `Answer` (raw fields of case 30),
`Suggestion` (ID, attachment ID, text, flags, confidence, status, adapter,
model, run ID, created), `Entry` (case 51), `ScanReport`, `RunReport`
(case 68), `UndoReport` (restored, refused, already undone, details).

Exceptions, all extending `AltTextFillException`: `ConfigException`,
`AdapterException` (with `isRetryable()`, `isAccountError()`,
`retryAfter()`), `MalformedAnswerException`, `ConflictException`
(changed since), `StaleSuggestionException`, `PermissionException`,
`CapReachedException`, `DisabledException`.

### 5.3 Adapter interface

`VisionAdapter`:

- `id(): string`: a slug, `^[a-z0-9-]{1,40}$`.
- `label(): string`: for the settings screen.
- `capabilities(): array`: `mime_types` accepted, `accepts_url` (bool),
  `accepts_animation` (bool), `max_bytes` (int).
- `settingsFields(): array`: field definitions (key, label, type, secret
  flag, default) so the settings screen can render them without knowing
  the adapter.
- `validateSettings(array $settings): array`: returns a list of error
  strings.
- `describe(ImageInput $image, Context $context, Prompt $prompt, array $settings): AdapterResponse`.
  `AdapterResponse` holds the raw text, the model ID, input and output
  token counts when reported, the HTTP status and latency. It throws
  `AdapterException` on any failure.

Adapters do HTTP through the WordPress HTTP API only. No SDKs. Register
with filter `alt_text_fill_adapters` (array of `VisionAdapter` keyed by
ID). Bundled: `openai-compatible` (reference) and `fake`.

The reference adapter sends one chat completions request with a system
message, a user message holding the context as text and the image as an
image part (data URI by default), asks for a JSON object response when the
endpoint supports it, and reads the first choice's message content. Base
URL, model and API key are settings; the API key may instead come from the
constant `ALT_TEXT_FILL_API_KEY` or an environment variable of the same
name, which wins over the stored setting.

Build note (0.1.0, accepted at review): the reference adapter does not use
`indianopinion/llm-json-php`. That library's 0.1.0 request takes message
content as a string only, so it cannot carry an image part. The adapter
makes its one call through the WordPress HTTP API instead, and the filter
`alt_text_fill_http_caller` lets a site plug in its own caller. When
llm-json-php can carry images, an adapter built on it registers through
`alt_text_fill_adapters` like any other.

### 5.4 Actions

| action | arguments |
|---|---|
| `alt_text_fill_run_started` | `string $run_id, array $args` |
| `alt_text_fill_run_finished` | `RunReport $report` |
| `alt_text_fill_suggestion_created` | `Suggestion $s` |
| `alt_text_fill_answer_rejected` | `int $attachment_id, string $reason` |
| `alt_text_fill_written` | `Entry $entry` |
| `alt_text_fill_undone` | `Entry $undo_entry, Entry $original` |
| `alt_text_fill_paused` | `string $reason` |
| `alt_text_fill_log` | `string $level, string $message, array $context` |

### 5.5 Filters

| filter | default | purpose |
|---|---|---|
| `alt_text_fill_adapters` | bundled two | register adapters |
| `alt_text_fill_mime_types` | case 1 list | allowed types |
| `alt_text_fill_is_candidate` | true | can only exclude |
| `alt_text_fill_weak_alt_patterns` | case 5 list | weak alt |
| `alt_text_fill_context_posts` | case 14 result | add or remove posts |
| `alt_text_fill_context` | built text | change context before sending |
| `alt_text_fill_prompt` | built-in prompt | replace the prompt |
| `alt_text_fill_openers` | case 35 list | openers to strip, per language |
| `alt_text_fill_refusal_patterns` | case 36 list | refusals, per language |
| `alt_text_fill_validate` | cleaned text and flags | add rules; can only reject or flag |
| `alt_text_fill_review_capability` | `upload_files` | review screen |
| `alt_text_fill_admin_capability` | `manage_options` | settings, runs, undo by run or time |
| `alt_text_fill_shared_threshold` | 3 | case 18 |
| `alt_text_fill_cron_interval` | 900 | seconds |
| `alt_text_fill_retention_days` | see section 6 | per table |

### 5.6 WP-CLI

`wp alt-text-fill` with subcommands:

- `scan [--limit=<n>] [--format=table|json|csv]`
- `run [--limit=<n>] [--dry-run] [--ids=<list>] [--time=<seconds>]`
- `review list [--status=<s>] [--flag=<f>] [--format=...]`
- `review approve <id>... [--text=<text>]`, `review reject <id>...
  --reason=<r>`. No "approve everything" option, matching case 42.
- `decorative <attachment-id>...`
- `undo <entry-id> [--force]`, `undo --run=<run-id>`, `undo
  --since=<datetime>`; each asks for confirmation unless `--yes`.
- `status [--format=...]`, `pause [--reason=<r>]`, `resume`
- `history [--attachment=<id>] [--run=<id>]`

Exit codes: 0 success, 1 error, 2 refused by a cap or the switch, 3
partial (some items refused).

### 5.7 Admin screens

- Media, "Alt text review": the review list (section 4.6).
- Settings, "Alt text fill": adapter and its fields, caps, switch,
  schedule, exclusions, language, weak-alt mode, render fill, context
  options, dry-run-only mode, a "Test connection" button that sends one
  small built-in test image and shows the answer (counts against the
  daily cap).
- A runs tab: recent runs (case 68) with undo by run.
- A history tab: entries with undo per entry.
- On the attachment edit screen, a small box shows any pending suggestion
  and the entries for that attachment, with the same actions.

## 6. Data

- Option `alt_text_fill_settings` (one array; secrets excluded when a
  constant or environment variable supplies them). Option
  `alt_text_fill_state`: cursor, pause reason, today's counters, lock.
  Autoload off for both except a small switch flag that is read on every
  admin page.
- Table `{prefix}alt_text_fill_suggestions`: ID, attachment ID, run ID,
  text, original model text, flags (JSON), confidence, status (pending,
  approved, edited, rejected, superseded, stale, expired), reject reason
  and note, context post ID, file hash, adapter, model, token counts,
  created, decided, decided by. Index on status and attachment ID.
  Context text is not stored by default (a setting stores it for
  debugging, then it expires with the row).
- Table `{prefix}alt_text_fill_entries`: case 51. Index on attachment ID,
  run ID, created.
- Table `{prefix}alt_text_fill_runs`: case 68.
- Attachment meta (plugin's own, underscored): decorative marker,
  candidate state (rejected count, parked reason, last tried), file hash.
- Retention: pending suggestions 30 days (then expired); decided
  suggestions 90 days; runs 90 days; entries 365 days. Undo is only
  possible while the entry exists; the history tab says how long that is.
  All are filters; 0 means keep forever.
- Tables are created on activation with a schema version; an upgrade
  routine compares the version and verifies each expected column exists
  after creating or altering, and shows an admin notice if any is
  missing.
- Uninstall drops the three tables, deletes both options, the plugin's
  own attachment meta and the scheduled event. It never changes or
  deletes any alt text, whether written by the plugin or not. The
  uninstall screen text says so and suggests undo by time before
  uninstalling if the writes should go.
- Multisite: tables and options per site. Network uninstall walks every
  site in batches.

## 7. Configuration and sensible defaults

| setting | default |
|---|---|
| Dry-run-only mode | on for a new install |
| Kill switch | off (but case 59 blocks real runs until dry-run-only is off) |
| Adapter | none chosen; real runs refuse until one is set |
| Per-run cap | 20 images |
| Daily cap (calls) | 200 |
| Daily money cap | none; needs an operator estimate per call |
| Schedule | off; 15 minutes when on |
| Time budget (cron) | 25 seconds |
| Target image side | 1,024 px |
| Byte limit | 4 MB |
| Send as | base64 |
| Context length | 1,500 characters |
| Shared image threshold | 3 posts |
| Include non-public | off |
| Include unattached | on |
| Minimum image size | 32 x 32 px |
| Weak-alt mode | off |
| Alt length | 5 to 125 characters (maximum up to 250) |
| Low confidence threshold | 0.5 |
| Language | site locale |
| Render fill | off |
| Use gen-queue | off |
| Request timeout | 30 s; output cap 200 tokens; temperature 0.2 |

Constants (win over settings): `ALT_TEXT_FILL_DISABLED`,
`ALT_TEXT_FILL_API_KEY`, `ALT_TEXT_FILL_DAILY_CAP`,
`ALT_TEXT_FILL_PER_RUN_CAP`.

## 8. Security and privacy

- Images and a slice of post text leave the site for the provider. The
  settings screen says so in plain words beside the adapter choice, and
  the README says so. Non-public posts are excluded by default (case 8).
- API keys: stored in the settings option only when no constant or
  environment variable is set; never printed back in full (last four
  characters only); never logged; redacted from any error excerpt.
- Every admin action: capability check and nonce. Review actions check
  `edit_post` on the attachment as well.
- All output on admin screens escaped. Suggested text is shown as text,
  never as HTML.
- Stored alt text is plain text: tags stripped before writing.
- Remote fetches limited to the site's own upload host and operator-listed
  hosts (case 25), with redirects capped and checked against the same
  list, so the fetcher cannot be used to reach internal addresses.
- Context is treated as untrusted data (case 20).
- No identification of people from faces (case 29). The `names_person`
  flag puts a naming in front of a reviewer.
- No `eval`, no shell, no `@` error suppression.
- Logs never contain image bytes, API keys or full context text.

## 9. Performance budget

- Nothing runs on front-end requests. The render fill (case 60) adds one
  meta read per image, already cached by WordPress for attachments loaded
  on the page.
- Scan page: one query on posts by ID range and MIME type, one batched
  meta read. Under 200 ms for a page of 500 on a typical shared host.
- Context lookup by URL is bounded (case 15) and can be turned off.
- Memory: one image in memory at a time, bounded by the byte limit plus
  its base64 copy.
- Cron run: within the time budget; the next item is not started when
  less than the request timeout remains.
- Review screen: 20 rows per page by default, thumbnails from the
  `thumbnail` size, one query for rows and one for attachments.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later. Single site and multisite.
- GD or Imagick optional (case 75).
- Media offload plugins: works through attachment URLs (case 25); never
  needs to know which offload tool is used.
- Page caches and CDNs: no effect, except that the render fill changes
  HTML only after a cache expires.
- Classic editor and block editor: the attachment alt field is the same.
- Runs alongside `spend-meter`, `gen-queue` and `prompt-library` when
  present, through their public hooks; never requires them.
- Any provider with an OpenAI-compatible chat completions endpoint that
  accepts images works through the reference adapter, including
  self-hosted servers. Others need an adapter.

## 11. Observability

- Status block on the settings screen and `wp alt-text-fill status`:
  empty-alt count (from the last full scan, with its date), pending,
  approved, edited, rejected, parked, decorative, today's calls against
  the cap, money estimate against the cap, switch and pause reason, last
  run with stop reason.
- Run log (case 68) on the runs tab and in WP-CLI.
- `alt_text_fill_log` action for every notable event, plus `error_log`
  lines prefixed `[alt-text-fill]` for warnings and errors, throttled to
  one identical line per 10 minutes.
- Site Health tests: adapter configured, key present, image editor
  available, tables present with the expected columns, plugin paused.
- An admin notice when paused for a provider account error (case 32).
- Approval rate per adapter and model (approved, edited, rejected share
  over the last 30 days) on the status block.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer, the fake adapter and a mock HTTP layer. No network.

| # | test | cases |
|---|---|---|
| T1 | Allowed types become candidates; an SVG is skipped as unsupported | 1, 2 |
| T2 | HEIC with a JPEG intermediate size sends that size; without one it is skipped | 3 |
| T3 | Missing, empty, spaces, NBSP and zero-width alt are all empty; "A dog" is not | 4 |
| T4 | Weak-alt mode treats file-name, camera-pattern and filler alt as empty; off by default | 5 |
| T5 | Decorative-marked attachment is never a candidate | 6 |
| T6 | Rejected and undone images are skipped by scheduled runs and included with the flag | 7, 57 |
| T7 | Image used only by a draft is skipped "not public"; included when set | 8 |
| T8 | Unattached image is a candidate with no context; excluded when set | 9 |
| T9 | Exclusions by ID, type, date, minimum size; the filter can exclude but not include | 10 |
| T10 | Scan pages by ID with a resumable cursor that wraps | 11 |
| T11 | Scan opens no image file | 12 |
| T12 | Missing file not fetchable is skipped "file missing" | 13 |
| T13 | Posts using an image found by parent, featured, block ID, class and URL | 14 |
| T14 | URL lookup stops at 20 posts and can be turned off | 15 |
| T15 | Context is stripped of HTML, shortcodes, URLs, and trimmed at a word under the cap | 16 |
| T16 | Context post is the public parent, else newest public user | 17 |
| T17 | Image used by 4 posts, or listed as placeholder, gets no post context and the shared flag | 18 |
| T18 | File name never appears in the request | 19 |
| T19 | Context with "ignore previous instructions" is delimited and flagged | 20 |
| T20 | Site language is sent and overridable | 21 |
| T21 | Smallest size at or over 1,024 px is chosen | 22 |
| T22 | Oversized image is scaled in memory, never saved; without an editor it is skipped | 23, 75 |
| T23 | Base64 by default; URL mode only with adapter support and a 200 HEAD | 24 |
| T24 | Offloaded file fetched from allowed host only, redirects capped, byte limit enforced | 25 |
| T25 | PNG bytes named `.jpg` are sent as PNG; unsupported real type skipped | 26 |
| T26 | Animated GIF sends the first frame, or skips when neither path works | 27 |
| T27 | Reference adapter builds a valid request with image part and JSON response request | 28 |
| T28 | Prompt contains each required instruction; filter and prompt-library override work | 29 |
| T29 | Answer with all fields maps to a suggestion | 30 |
| T30 | Timeout retried once; 429 honours Retry-After and ends the run | 31 |
| T31 | 401 and 402 pause with "provider account"; no further calls | 32 |
| T32 | Five failures pause until the next site day | 33 |
| T33 | JSON in a code fence or after prose is accepted; other text is "malformed" | 34 |
| T34 | Quotes, tags, control characters, line breaks and "An image of" are cleaned | 35 |
| T35 | Each rejection reason fires on its example | 36 |
| T36 | Over-long text trimmed at a word or sentence end and flagged | 37 |
| T37 | Each flag shows and none rejects | 38 |
| T38 | Two rejected answers park the image | 39 |
| T39 | Decorative answer becomes "suggest decorative" and never writes empty text as a description | 40 |
| T40 | Review list renders every column, escaped | 41 |
| T41 | Row and bulk actions work; no cross-page approve-all exists in UI or CLI | 42 |
| T42 | Approval after an editor filled the field writes nothing and marks superseded | 43 |
| T43 | Edited approval stores both texts and is cleaned; empty edit refused; title copy allowed | 44, 45 |
| T44 | Mark decorative sets the marker, writes empty only if absent, and is undoable | 46 |
| T45 | Reject stores reason and note | 47 |
| T46 | Pending suggestion past retention expires and is not written | 48 |
| T47 | List filters and sorts | 49 |
| T48 | No capability or bad nonce is refused; `edit_post` checked per row | 50 |
| T49 | Write creates an entry with the exact previous state | 51 |
| T50 | Undo restores absent as absent and a whitespace string exactly | 52 |
| T51 | Undo refuses on "changed since"; force overrides one entry | 53 |
| T52 | Undo by run newest first with counts | 54 |
| T53 | Undo since a time reverses only later plugin writes | 55 |
| T54 | Undo of an undo restores the written value | 56 |
| T55 | Writes and undos change only the alt field | 58 |
| T56 | Dry run calls no adapter, writes nothing but the run record, reports each item | 59 |
| T57 | New install refuses real runs until dry-run-only is off | 59 |
| T58 | Render fill on: empty attribute filled, block `alt=""` kept; off by default | 60 |
| T59 | Per-run cap and time budget stop the run with the right stop reason | 61 |
| T60 | Daily call cap and money cap stop before the call; counter resets at site midnight | 62 |
| T61 | Kill switch by setting, CLI and constant; stops a run mid-way; review writes still work | 63 |
| T62 | With a spend-meter stub: pre-spend deny stops the run; record fires after a call | 64 |
| T63 | With a gen-queue stub: one job per image with the idempotency key; caps still apply | 65 |
| T64 | Second run while locked is refused; a stale lock is broken and logged | 66 |
| T65 | Schedule off by default; on adds the event at the filtered interval | 67 |
| T66 | Run record holds every field | 68 |
| T67 | Replaced file makes the suggestion stale and unapprovable | 69 |
| T68 | Deleting an attachment removes pending suggestions and keeps entries | 70 |
| T69 | Unreachable model counts a failure and the run continues | 71 |
| T70 | Failed alt write creates no entry and keeps the suggestion pending | 72 |
| T71 | Failed entry write reverts the alt write | 73 |
| T72 | Adapter throwing a runtime error is caught and logged with a redacted message | 74 |
| T73 | No key: real run refused, dry run works | 76 |
| T74 | Unreadable settings: switch treated as on and a notice shown | 77 |
| T75 | API key never appears in logs, errors or the settings page beyond four characters | 8 (section) |
| T76 | Uninstall drops tables, options and own meta and leaves every alt value unchanged | 6 (section) |
| T77 | Table upgrade verifies columns and warns when one is missing | 6 (section), lesson 14 |
| T78 | CLI exit codes 0, 1, 2 and 3 | 5.6 |

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`:

1. Should an alt meta row that exists with an empty string count as a
   deliberate "decorative" choice rather than "never set"? WordPress
   stores an empty string when the attachment form is saved with the
   field blank, so the two cannot always be told apart. The PRD treats
   both as empty and gives the explicit decorative marker instead; the
   builder confirms or proposes a better signal.
2. Is the first-frame extraction for animated GIF and WebP reliable enough
   on GD and Imagick to keep, or should animated files simply be skipped
   in 0.1.0?
3. How should the "copies title" overlap rule (80 per cent of title words)
   handle very short titles of one or two words, where any accurate
   description may share a word?
4. Should the money cap use only the operator's per-call estimate, or the
   token counts the provider reports multiplied by operator-entered
   prices? The PRD asks for the estimate; spend-meter, when present,
   covers real prices.
5. Is base64 in the request accepted by every target endpoint the builder
   tests, and what is the safe default byte limit after base64 growth?
6. Should a second bundled adapter be shipped for a widely used
   non-OpenAI-compatible vision API, or is the reference adapter plus the
   interface enough for 0.1.0?
7. Where the site has many languages through a translation plugin, should
   the context post's language win over the site locale? The PRD keeps
   one language per site and leaves the hook (case 21).

## 14. Lessons from production

Written as rules. They come from running image description on a busy WordPress
publication and from general WordPress practice.

1. Never use the post title as alt text, and reject a model answer that
   is a near copy of it. A headline in the alt field says what the story
   is about, not what the picture shows, and it hides a wrong picture
   instead of exposing it.
2. Assume the picture may not belong to the story. Publishers reuse file
   photos and library images from other stories. Describe what is
   visible; use post text only to name a thing the picture plainly shows,
   and flag a mismatch for the reviewer.
3. A placeholder or default image used on many posts gets one neutral
   description, never one post's subject.
4. Images that are mostly text (cards, charts, screenshots) need the
   gist of the text, not a transcription and not "a graphic".
5. Missing and empty alt are different in storage and the same to a
   reader. Treat both as empty, record which it was, and restore exactly
   that on undo.
6. Re-read the current value immediately before every write. An editor
   may have filled it since the suggestion was made.
7. Never write without an entry that can undo it. If the entry cannot be
   stored, revert the write.
8. A model answer is untrusted text. Parse strictly, clean it, and check
   for refusals, links, file names and openers before a person sees it.
9. Post text is untrusted input to the model. Delimit it and tell the
   model not to follow instructions inside it.
10. Every call that can spend money checks the switch and the caps first,
    and failed or rejected calls count too, because they were billed.
11. Stop on account errors at once. Retrying a quota or authentication
    failure only adds noise and, on some providers, cost.
12. Do not send originals. A phone photo can be many megabytes; a 1,024
    pixel copy describes just as well.
13. The block editor keeps its own copy of alt text in post content.
    Changing the attachment does not change posts already written; say so
    in the README so nobody expects otherwise.
14. Schema changes can be skipped silently by the WordPress table helper.
    After creating or altering a table, check every expected column exists
    and warn when one does not.
15. Scan large libraries by ID range with a cursor. A single query that
    joins every attachment to its meta to find a missing key is slow on
    big sites and holds locks.
16. Keep the switch and caps in this plugin's own settings even when a
    shared spend tool is installed. Shared tools get removed; the plugin
    must still stop.
