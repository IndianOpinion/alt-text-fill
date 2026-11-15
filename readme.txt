=== Alt Text Fill ===
Contributors: indianopinion
Tags: alt text, accessibility, media library, images, ai
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Suggests alt text for images that have none, with a vision model you choose. Nothing is written until a person approves it, and every write can be undone.

== Description ==

Alt Text Fill finds every image in the media library with no alt text, asks a vision model to describe it from the image and the text of the post that uses it, and puts each suggestion on a review list under Media. An editor approves, edits, rejects or marks the image decorative. Only approved text is written, each write keeps the exact previous value, and undo works for one image, a whole run, or everything since a time.

Written from scratch; not derived from any third-party plugin.

* Dry run that shows exactly what would be sent, without calling anything.
* New installs start in dry-run-only mode.
* Per-run cap, daily call cap, optional money cap from reported tokens and your own prices, and a kill switch.
* Stops at once on account or quota errors; pauses for the day after five failures in a row.
* Never sends the file name; post text is sent as data, not instructions.
* Rejects answers that copy the post title, contain links, or are refusals.
* Images used by drafts and private posts are not sent unless you allow it.
* WP-CLI: `wp alt-text-fill`.

Privacy: images and a slice of the text around them are sent to the provider you configure, under your own API key.

== Installation ==

1. Upload the `alt-text-fill` directory to `wp-content/plugins/` and activate it.
2. Settings, Alt text fill: choose the adapter, set the endpoint, model and key (or define `ALT_TEXT_FILL_API_KEY` in `wp-config.php`).
3. Press Test connection, then Dry run now.
4. Turn off dry-run-only mode and run.
5. Media, Alt text review: approve what is right.

== Frequently Asked Questions ==

= Will it write alt text on its own? =

No. There is no auto-approve and no approve-all.

= Does it change alt text already inside my posts? =

No. The block editor keeps its own copy in post content. The plugin writes the attachment's alt field only. An optional render fill uses the stored value when a rendered image has no alt.

= What does uninstall remove? =

The plugin's tables, options, its own attachment meta and schedules. Alt text is never changed or deleted. Undo by time first if the plugin's writes should go.

== Changelog ==

= 0.1.0 =
* First release.
