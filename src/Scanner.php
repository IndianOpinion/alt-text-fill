<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Adapter\AdapterRegistry;
use IndianOpinion\AltTextFill\Context\MediaLibrary;
use IndianOpinion\AltTextFill\Context\UsageFinder;
use IndianOpinion\AltTextFill\Model\Candidate;
use IndianOpinion\AltTextFill\Model\ScanReport;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Storage\Repository;

/**
 * Finds candidates (PRD 4.1). It reads post and meta rows only and never
 * opens an image file (case 12).
 */
class Scanner
{
    public const DEFAULT_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
    /** Sent only when the adapter accepts them or a JPEG intermediate size exists (case 3). */
    public const CONVERTIBLE = ['image/heic', 'image/heif', 'image/tiff', 'image/bmp', 'image/x-ms-bmp', 'image/x-icon', 'image/vnd.microsoft.icon'];

    public const SKIP_UNSUPPORTED = 'unsupported type';
    public const SKIP_DECORATIVE = 'decorative';
    public const SKIP_HELD = 'rejected or undone';
    public const SKIP_PARKED = 'no usable answer';
    public const SKIP_PENDING = 'pending review';
    public const SKIP_NOT_PUBLIC = 'not public';
    public const SKIP_UNATTACHED = 'unattached';
    public const SKIP_EXCLUDED = 'excluded';
    public const SKIP_TOO_SMALL = 'too small';
    public const SKIP_FILTERED = 'filtered';

    public function __construct(
        private MediaLibrary $library,
        private UsageFinder $finder,
        private EmptyAlt $emptyAlt,
        private AltField $field,
        private Settings $settings,
        private State $state,
        private Repository $repository,
        private AdapterRegistry $adapters,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        $filtered = apply_filters('alt_text_fill_mime_types', self::DEFAULT_MIMES);
        $list = is_array($filtered) ? array_values(array_map('strval', $filtered)) : self::DEFAULT_MIMES;

        return array_values(array_filter($list, static fn (string $m): bool => $m !== 'image/svg+xml'));
    }

    /**
     * One page of the scan.
     *
     * args: limit (page size), after_id (else the stored cursor, which then
     * advances), include_rejected, mime_types (narrows the allowed list).
     *
     * @param array<string, mixed> $args
     */
    public function scan(array $args = []): ScanReport
    {
        $useCursor = !isset($args['after_id']);
        $state = $this->state->all();
        $after = $useCursor ? (int) $state['cursor'] : max(0, (int) $args['after_id']);
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : $this->settings->int('scan_page');
        $include = !empty($args['include_rejected']);
        $mimes = isset($args['mime_types']) && is_array($args['mime_types']) ? array_values(array_map('strval', $args['mime_types'])) : null;

        $ids = $this->library->attachmentPage($after, $limit);
        $this->library->primeMeta($ids);
        $candidates = [];
        $skipped = [];
        $reasons = [];
        $empty = 0;
        $generation = (int) $state['generation'];
        foreach ($ids as $id) {
            $result = $this->evaluate($id, $include, $mimes, $generation);
            if ($result === null) {
                continue;
            }
            $empty++;
            if ($result instanceof Candidate) {
                $candidates[] = $result;
            } else {
                $skipped[$result] = ($skipped[$result] ?? 0) + 1;
                $reasons[$id] = $result;
            }
        }
        $wrapped = count($ids) < $limit;
        $cursor = $wrapped ? 0 : (int) end($ids);
        if ($useCursor) {
            $changes = ['cursor' => $cursor, 'scan_empty' => (int) $state['scan_empty'] + $empty];
            if ($wrapped) {
                $changes['generation'] = $generation + 1;
                $changes['last_full_scan'] = ['date' => time(), 'empty' => (int) $state['scan_empty'] + $empty];
                $changes['scan_empty'] = 0;
            }
            $this->state->update($changes);
        }

        return new ScanReport(count($ids), $candidates, $skipped, $reasons, $cursor, $wrapped, $empty);
    }

    /**
     * Pages through the library from the cursor until $want candidates are
     * found, the scan wraps once, or $maxPages pages have been read.
     *
     * @param list<int>|null $ids Explicit attachment IDs instead of the cursor.
     * @return array{candidates: list<Candidate>, skipped: array<string, int>, reasons: array<int, string>, considered: int}
     */
    public function collect(int $want, bool $includeRejected = false, ?array $ids = null, int $maxPages = 20): array
    {
        $candidates = [];
        $skipped = [];
        $reasons = [];
        $considered = 0;
        if ($ids !== null) {
            $generation = (int) $this->state->all()['generation'];
            foreach ($ids as $id) {
                $considered++;
                $result = $this->evaluate((int) $id, true, null, $generation);
                $result ??= 'has alt';
                if ($result instanceof Candidate) {
                    if (count($candidates) < $want) {
                        $candidates[] = $result;
                    }
                } else {
                    $skipped[$result] = ($skipped[$result] ?? 0) + 1;
                    $reasons[(int) $id] = $result;
                }
            }

            return ['candidates' => $candidates, 'skipped' => $skipped, 'reasons' => $reasons, 'considered' => $considered];
        }
        for ($page = 0; $page < $maxPages && count($candidates) < $want; $page++) {
            $report = $this->scan(['include_rejected' => $includeRejected]);
            $considered += $report->considered;
            foreach ($report->candidates as $candidate) {
                $candidates[] = $candidate;
            }
            foreach ($report->skipped as $reason => $n) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + $n;
            }
            $reasons += $report->reasons;
            if ($report->wrapped) {
                break;
            }
        }

        return ['candidates' => array_slice($candidates, 0, $want), 'skipped' => $skipped, 'reasons' => $reasons, 'considered' => $considered];
    }

    /**
     * Null when the alt is not empty (not a candidate and not counted as
     * skipped), a skip reason string, or the Candidate.
     *
     * @param list<string>|null $mimes
     */
    public function evaluate(int $id, bool $includeRejected = false, ?array $mimes = null, int $generation = -2): Candidate|string|null
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return null;
        }
        if ($this->field->isDecorative($id)) {
            return null === $this->field->read($id) || Text::isBlank((string) $this->field->read($id)) ? self::SKIP_DECORATIVE : null;
        }
        if (!$this->emptyAlt->isEmpty($id)) {
            return null;
        }
        $mime = strtolower((string) $post->post_mime_type);
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : [];
        $reason = $this->mimeReason($mime, $meta, $mimes);
        if ($reason !== null) {
            return $reason;
        }
        if (in_array($id, $this->settings->intList('exclude_ids'), true) || in_array($mime, $this->settings->stringList('exclude_mimes'), true)) {
            return self::SKIP_EXCLUDED;
        }
        $day = substr((string) $post->post_date, 0, 10);
        $from = $this->settings->string('date_from');
        $to = $this->settings->string('date_to');
        if (($from !== '' && $day < $from) || ($to !== '' && $day > $to)) {
            return self::SKIP_EXCLUDED;
        }
        if ($this->repository->countSuggestions(['attachment_id' => $id, 'status' => Suggestion::PENDING]) > 0) {
            return self::SKIP_PENDING;
        }
        $state = $this->field->state($id);
        if ($generation === -2) {
            $generation = (int) $this->state->all()['generation'];
        }
        if (!$includeRejected && $state['hold']) {
            return self::SKIP_HELD;
        }
        if (!$includeRejected && $state['parked'] !== '') {
            return self::SKIP_PARKED;
        }
        if ($state['skip'] !== '' && $state['skip_gen'] === $generation) {
            return $state['skip'];
        }
        $width = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);
        if (($width > 0 && $width < $this->settings->int('min_width')) || ($height > 0 && $height < $this->settings->int('min_height'))) {
            return self::SKIP_TOO_SMALL;
        }

        $users = $this->finder->find($id);
        $public = array_values(array_filter($users, [UsageFinder::class, 'isPublic']));
        $includeNonPublic = $this->settings->bool('include_nonpublic');
        if ($users === []) {
            if (!$this->settings->bool('include_unattached')) {
                return self::SKIP_UNATTACHED;
            }
            $contextPost = 0;
        } elseif ($public === [] && !$includeNonPublic) {
            return self::SKIP_NOT_PUBLIC;
        } else {
            $parent = (int) $post->post_parent;
            if ($parent > 0 && in_array($parent, $public, true)) {
                $contextPost = $parent;
            } else {
                $pool = $public !== [] ? $public : $users;
                usort($pool, static function (int $a, int $b): int {
                    $pa = get_post($a);
                    $pb = get_post($b);

                    return strcmp($pb instanceof \WP_Post ? (string) $pb->post_date : '', $pa instanceof \WP_Post ? (string) $pa->post_date : '');
                });
                $contextPost = $pool[0];
            }
        }
        $threshold = (int) apply_filters('alt_text_fill_shared_threshold', $this->settings->int('shared_threshold'));
        $shared = count($users) > $threshold || in_array($id, $this->settings->intList('placeholder_ids'), true);

        $current = (string) $this->field->read($id);
        $candidate = new Candidate(
            $id,
            $mime,
            $width,
            $height,
            $contextPost,
            $shared,
            $users,
            !Text::isBlank($current),
            $current
        );
        if (apply_filters('alt_text_fill_is_candidate', true, $id, $candidate) === false) {
            return self::SKIP_FILTERED;
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string>|null    $narrow
     */
    private function mimeReason(string $mime, array $meta, ?array $narrow): ?string
    {
        if ($mime === 'image/svg+xml') {
            return self::SKIP_UNSUPPORTED;
        }
        $allowed = self::allowedMimes();
        if ($narrow !== null) {
            $allowed = array_values(array_intersect($allowed, $narrow));
        }
        if (in_array($mime, $allowed, true)) {
            return null;
        }
        if (in_array($mime, self::CONVERTIBLE, true) && ($narrow === null || in_array($mime, $narrow, true))) {
            $adapter = $this->adapters->active();
            if ($adapter !== null && in_array($mime, $adapter->capabilities()['mime_types'], true)) {
                return null;
            }

            return self::jpegSize($meta) !== null ? null : self::SKIP_UNSUPPORTED;
        }

        return self::SKIP_UNSUPPORTED;
    }

    /**
     * The JPEG intermediate size WordPress made for a non-JPEG original, if any.
     *
     * @param array<string, mixed> $meta
     */
    public static function jpegSize(array $meta): ?string
    {
        foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
            if (is_array($size) && ($size['mime-type'] ?? '') === 'image/jpeg') {
                return (string) $name;
            }
        }

        return null;
    }
}
