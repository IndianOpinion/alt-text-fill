<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Admin;

use IndianOpinion\AltTextFill\Container;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Review;
use IndianOpinion\AltTextFill\Validator;

/**
 * Media, "Alt text review" (PRD 4.6). Suggested text is shown as text,
 * never as HTML. Bulk actions only touch rows ticked on this page; there is
 * no approve-all.
 */
final class ReviewScreen
{
    public function __construct(private Container $c)
    {
    }

    /**
     * @param array<string, mixed> $q status, flag, run, post, min_confidence, max_confidence, orderby, order, paged.
     */
    public function render(array $q = []): string
    {
        $status = isset($q['status']) && in_array($q['status'], Suggestion::STATUSES, true) ? (string) $q['status'] : Suggestion::PENDING;
        $filter = ['status' => $status];
        if (!empty($q['flag'])) {
            $filter['flag'] = (string) $q['flag'];
        }
        if (!empty($q['run'])) {
            $filter['run_id'] = (string) $q['run'];
        }
        if (!empty($q['post'])) {
            $filter['context_post_id'] = (int) $q['post'];
        }
        foreach (['min_confidence', 'max_confidence'] as $k) {
            if (isset($q[$k])) {
                $filter[$k] = (float) $q[$k];
            }
        }
        $orderBy = ($q['orderby'] ?? '') === 'confidence' ? 'confidence' : 'created';
        $order = strtoupper((string) ($q['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($q['paged'] ?? 1));
        $per = Screens::PER_PAGE;
        $rows = $this->c->repository->findSuggestions($filter, $orderBy, $order, $per, ($page - 1) * $per);
        $total = $this->c->repository->countSuggestions($filter);
        if ($rows !== []) {
            _prime_post_caches(array_map(static fn (Suggestion $s): int => $s->attachmentId, $rows), false, true);
        }

        $h = '<div class="wrap alt-text-fill-review"><h1>' . esc_html__('Alt text review', 'alt-text-fill') . '</h1>';
        $h .= '<p>' . esc_html__('Nothing is written until you approve it. Changing an attachment does not change alt text already stored inside posts.', 'alt-text-fill') . '</p>';
        $h .= $this->filters($status, $q);
        $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $h .= '<input type="hidden" name="action" value="alt_text_fill_review" /><input type="hidden" name="id" value="" /><input type="hidden" name="attachment" value="" />' . Screens::nonceField(Screens::NONCE_REVIEW);
        if ($status === Suggestion::PENDING) {
            $h .= '<p><button class="button" name="op" value="bulk_approve">' . esc_html__('Approve selected', 'alt-text-fill') . '</button> ';
            $h .= '<button class="button" name="op" value="bulk_reject">' . esc_html__('Reject selected', 'alt-text-fill') . '</button></p>';
        }
        $h .= '<table class="widefat striped"><thead><tr>';
        foreach (['', 'Image', 'Current alt', 'Suggested alt', 'Context post', 'Flags', 'Confidence', 'Adapter and model', 'Time', 'Actions'] as $col) {
            $h .= '<th scope="col">' . esc_html($col) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        if ($rows === []) {
            $h .= '<tr><td colspan="10">' . esc_html__('Nothing here.', 'alt-text-fill') . '</td></tr>';
        }
        foreach ($rows as $s) {
            $h .= $this->row($s);
        }
        $h .= '</tbody></table></form>';
        $pages = (int) ceil($total / $per);
        if ($pages > 1) {
            $h .= '<p class="tablenav-pages">';
            for ($p = 1; $p <= $pages; $p++) {
                $url = add_query_arg(array_merge($q, ['page' => 'alt-text-fill-review', 'paged' => $p]), admin_url('upload.php'));
                $h .= $p === $page ? '<strong>' . $p . '</strong> ' : '<a href="' . esc_url($url) . '">' . $p . '</a> ';
            }
            $h .= '</p>';
        }
        $h .= self::script();

        return $h . '</div>';
    }

    private function row(Suggestion $s): string
    {
        $can = Screens::canActOn($s->attachmentId);
        $current = $this->c->field->read($s->attachmentId);
        $currentText = $current === null || trim($current) === '' ? __('none', 'alt-text-fill') : $current;
        $max = $this->c->settings->int('max_length');
        $h = '<tr>';
        $h .= '<td>' . ($can && $s->isPending() ? '<input type="checkbox" name="ids[]" value="' . esc_attr((string) $s->id) . '" />' : '') . '</td>';
        $h .= '<td>' . wp_get_attachment_image($s->attachmentId, 'thumbnail', false, ['alt' => '']) . '</td>';
        $h .= '<td>' . ($s->hasFlag(Validator::FLAG_WEAK_ALT) ? '<strong>' . esc_html($currentText) . '</strong>' : esc_html($currentText)) . '</td>';
        if ($s->kind === Suggestion::KIND_DECORATIVE) {
            $h .= '<td><em>' . esc_html__('Suggest decorative (no description)', 'alt-text-fill') . '</em></td>';
        } else {
            $text = $s->status === Suggestion::EDITED ? $s->finalText : $s->text;
            $h .= '<td>';
            if ($can && $s->isPending()) {
                $h .= '<textarea class="alt-text-fill-edit" rows="2" cols="40" maxlength="250" data-max="' . esc_attr((string) $max) . '" name="text[' . esc_attr((string) $s->id) . ']">' . esc_textarea($text) . '</textarea>';
                $h .= '<br /><span class="alt-text-fill-count">' . esc_html((string) mb_strlen($text)) . '</span> / ' . esc_html((string) $max);
            } else {
                $h .= esc_html($text);
                if ($s->status === Suggestion::EDITED) {
                    $h .= '<br /><small>' . esc_html__('Model text:', 'alt-text-fill') . ' ' . esc_html($s->text) . '</small>';
                }
            }
            $h .= '</td>';
        }
        $h .= '<td>';
        if ($s->contextPostId > 0) {
            $h .= '<a href="' . esc_url((string) get_permalink($s->contextPostId)) . '">' . esc_html(get_the_title($s->contextPostId)) . '</a>';
        } else {
            $h .= esc_html__('none', 'alt-text-fill');
        }
        $h .= '</td>';
        $h .= '<td>' . esc_html(implode(', ', $s->flags)) . ($s->rejectReason !== '' ? '<br />' . esc_html($s->rejectReason) : '') . '</td>';
        $h .= '<td>' . esc_html(number_format($s->confidence, 2)) . '</td>';
        $h .= '<td>' . esc_html($s->adapter . ' / ' . $s->model) . '</td>';
        $h .= '<td>' . esc_html(wp_date('Y-m-d H:i', $s->created) ?: '') . '</td>';
        $h .= '<td>' . ($can && $s->isPending() ? $this->actions($s) : esc_html($s->status)) . '</td>';

        return $h . '</tr>';
    }

    private function actions(Suggestion $s): string
    {
        $id = esc_attr((string) $s->id);
        $att = esc_attr((string) $s->attachmentId);
        $b = static fn (string $op, string $label, string $extra = ''): string => '<button class="button button-small" name="op" value="' . esc_attr($op) . '" onclick="this.form.elements[\'id\'].value=' . $id . ';this.form.elements[\'attachment\'].value=' . $att . ';"' . $extra . '>' . esc_html($label) . '</button> ';
        $h = $b('approve', __('Approve', 'alt-text-fill'));
        if ($s->kind === Suggestion::KIND_TEXT) {
            $h .= $b('approve_edit', __('Approve with edit', 'alt-text-fill'));
        }
        $h .= $b('reject', __('Reject', 'alt-text-fill'));
        $h .= '<select name="reason[' . $id . ']">';
        foreach (array_diff(Review::REJECT_REASONS, ['try again']) as $reason) {
            $h .= '<option value="' . esc_attr($reason) . '">' . esc_html($reason) . '</option>';
        }
        $h .= '</select> <input type="text" name="note[' . $id . ']" placeholder="' . esc_attr__('note', 'alt-text-fill') . '" size="10" /> ';
        $h .= $b('try_again', __('Try again', 'alt-text-fill'));
        $h .= $b('decorative', __('Mark decorative', 'alt-text-fill'));
        $h .= $b('skip', __('Skip for now', 'alt-text-fill'));

        return $h;
    }

    /**
     * @param array<string, mixed> $q
     */
    private function filters(string $status, array $q): string
    {
        $h = '<form method="get"><input type="hidden" name="page" value="alt-text-fill-review" />';
        $h .= '<select name="status">';
        foreach (Suggestion::STATUSES as $s) {
            $h .= '<option value="' . esc_attr($s) . '"' . selected($s, $status, false) . '>' . esc_html($s) . '</option>';
        }
        $h .= '</select> <select name="flag"><option value="">' . esc_html__('any flag', 'alt-text-fill') . '</option>';
        foreach ([Validator::FLAG_NAMES_PERSON, Validator::FLAG_CONTAINS_TEXT, Validator::FLAG_MISMATCH, Validator::FLAG_LOW_CONFIDENCE, Validator::FLAG_SHARED, Validator::FLAG_TRIMMED, Validator::FLAG_WEAK_ALT, Validator::FLAG_INSTRUCTIONS, Validator::FLAG_DECORATIVE] as $flag) {
            $h .= '<option value="' . esc_attr($flag) . '"' . selected($flag, (string) ($q['flag'] ?? ''), false) . '>' . esc_html($flag) . '</option>';
        }
        $h .= '</select> ';
        $h .= '<input type="number" step="0.05" min="0" max="1" name="min_confidence" placeholder="' . esc_attr__('min confidence', 'alt-text-fill') . '" value="' . esc_attr(isset($q['min_confidence']) ? (string) $q['min_confidence'] : '') . '" /> ';
        $h .= '<input type="number" min="1" name="post" placeholder="' . esc_attr__('post ID', 'alt-text-fill') . '" value="' . esc_attr(!empty($q['post']) ? (string) $q['post'] : '') . '" /> ';
        $h .= '<input type="text" name="run" placeholder="' . esc_attr__('run ID', 'alt-text-fill') . '" value="' . esc_attr((string) ($q['run'] ?? '')) . '" /> ';
        $h .= '<select name="orderby"><option value="created">' . esc_html__('newest', 'alt-text-fill') . '</option><option value="confidence"' . selected('confidence', (string) ($q['orderby'] ?? ''), false) . '>' . esc_html__('confidence', 'alt-text-fill') . '</option></select> ';
        $h .= '<select name="order"><option value="DESC">DESC</option><option value="ASC"' . selected('ASC', strtoupper((string) ($q['order'] ?? '')), false) . '>ASC</option></select> ';

        return $h . '<button class="button">' . esc_html__('Filter', 'alt-text-fill') . '</button></form>';
    }

    private static function script(): string
    {
        return '<script>document.querySelectorAll(".alt-text-fill-edit").forEach(function(t){t.addEventListener("input",function(){var c=t.parentNode.querySelector(".alt-text-fill-count");if(c){c.textContent=t.value.length;c.style.color=t.value.length>parseInt(t.dataset.max,10)?"#b32d2e":"";}});});</script>';
    }
}
