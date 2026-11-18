# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-18

### Added

- Scan of the media library by ID range with a resumable cursor; empty,
  whitespace-only and (opt-in) weak alt; exclusions by type, ID, upload
  date, size, post visibility and a filter that can only exclude.
- Context from the posts that use an image: parent, featured image, image
  block ID, class and URL, bounded; shared images get no post context.
- Image preparation: size choice, in-memory scaling to 1,024 px with GD or
  Imagick, a 4 MB limit, type from the bytes, offloaded media fetched from
  allowed hosts only, animated GIF and WebP skipped.
- Vision adapter interface, a reference adapter for OpenAI-compatible chat
  completions endpoints with image parts, and a fake adapter.
- Answer parsing, cleaning, rejection rules and review flags.
- Review list under Media, attachment box, settings screen with status,
  runs and history tabs, Site Health test.
- Writes with an exact-previous-state history entry; undo of one entry, a
  run, or everything since a time.
- Dry run, dry-run-only mode for new installs, per-run cap, daily call
  cap, money cap from reported tokens and operator prices, kill switch,
  circuit breaker, rate-limit wait, run lock.
- Optional spend-meter, gen-queue and prompt-library integration.
- Opt-in render fill.
- WP-CLI command `wp alt-text-fill` with exit codes 0 to 3.
- Uninstall that removes the plugin's data and never touches alt text.

[Unreleased]: https://github.com/IndianOpinion/alt-text-fill/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/alt-text-fill/releases/tag/v0.1.0
