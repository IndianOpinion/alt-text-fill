<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Admin;

use IndianOpinion\AltTextFill\Container;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Plugin;

/**
 * A small box on the attachment edit screen: the pending suggestion and the
 * entries for this attachment, with the same actions.
 */
final class AttachmentBox
{
    public static function add(\WP_Post $post): void
    {
        if (!current_user_can(Screens::reviewCapability()) || !wp_attachment_is_image($post)) {
            return;
        }
        add_meta_box('alt-text-fill', __('Alt text fill', 'alt-text-fill'), [self::class, 'echoBox'], 'attachment', 'side');
    }

    public static function echoBox(\WP_Post $post): void
    {
        echo self::render(Plugin::container(), (int) $post->ID); // Escaped inside.
    }

    public static function render(Container $c, int $attachmentId): string
    {
        $can = Screens::canActOn($attachmentId);
        $h = '';
        $pending = $c->repository->findSuggestions(['attachment_id' => $attachmentId, 'status' => Suggestion::PENDING], 'created', 'DESC', 1);
        $form = static function (string $action, string $nonce, array $fields, string $label): string {
            $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="' . esc_attr($action) . '" />' . Screens::nonceField($nonce);
            foreach ($fields as $k => $v) {
                $h .= '<input type="hidden" name="' . esc_attr((string) $k) . '" value="' . esc_attr((string) $v) . '" />';
            }

            return $h . '<button class="button button-small">' . esc_html($label) . '</button></form> ';
        };
        if ($pending !== []) {
            $s = $pending[0];
            $h .= '<p><strong>' . esc_html__('Suggested:', 'alt-text-fill') . '</strong> ' . esc_html($s->kind === Suggestion::KIND_DECORATIVE ? __('decorative', 'alt-text-fill') : $s->text) . '</p>';
            if ($s->flags !== []) {
                $h .= '<p><small>' . esc_html(implode(', ', $s->flags)) . '</small></p>';
            }
            if ($can) {
                $h .= $form('alt_text_fill_review', Screens::NONCE_REVIEW, ['op' => 'approve', 'id' => $s->id], __('Approve', 'alt-text-fill'));
                $h .= $form('alt_text_fill_review', Screens::NONCE_REVIEW, ['op' => 'reject', 'id' => $s->id, 'reason' => 'other'], __('Reject', 'alt-text-fill'));
            }
        } else {
            $h .= '<p>' . esc_html__('No pending suggestion.', 'alt-text-fill') . '</p>';
        }
        if ($can) {
            $h .= '<p>' . $form('alt_text_fill_review', Screens::NONCE_REVIEW, ['op' => 'decorative', 'attachment' => $attachmentId], __('Mark decorative', 'alt-text-fill'));
            $h .= $form('alt_text_fill_review', Screens::NONCE_REVIEW, ['op' => 'try_again', 'attachment' => $attachmentId], __('Try again', 'alt-text-fill')) . '</p>';
        }
        $entries = $c->repository->findEntries(['attachment_id' => $attachmentId], 10);
        if ($entries !== []) {
            $h .= '<ul>';
            foreach ($entries as $e) {
                $h .= '<li>' . esc_html(sprintf('#%d %s: %s', $e->id, $e->action, $e->newExists ? (string) $e->newValue : '(absent)'));
                if ($can && !$e->isUndone()) {
                    $h .= ' ' . $form('alt_text_fill_undo', Screens::NONCE_UNDO, ['entry' => $e->id], __('Undo', 'alt-text-fill'));
                }
                $h .= '</li>';
            }
            $h .= '</ul>';
        }

        return $h;
    }
}
