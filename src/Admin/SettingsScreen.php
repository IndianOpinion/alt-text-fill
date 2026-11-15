<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Admin;

use IndianOpinion\AltTextFill\Container;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Redactor;
use IndianOpinion\AltTextFill\Settings;

/**
 * Settings, "Alt text fill": settings, status, runs and history tabs.
 * API keys are never printed back beyond their last four characters.
 */
final class SettingsScreen
{
    public function __construct(private Container $c)
    {
    }

    public function render(string $tab = 'settings'): string
    {
        $tabs = ['settings' => 'Settings', 'status' => 'Status', 'runs' => 'Runs', 'history' => 'History'];
        $tab = isset($tabs[$tab]) ? $tab : 'settings';
        $h = '<div class="wrap"><h1>' . esc_html__('Alt text fill', 'alt-text-fill') . '</h1><nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(['page' => 'alt-text-fill', 'tab' => $key], admin_url('options-general.php'));
            $h .= '<a class="nav-tab' . ($key === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        $h .= '</nav>';
        $h .= match ($tab) {
            'status' => $this->status(),
            'runs' => $this->runs(),
            'history' => $this->history(),
            default => $this->settings(),
        };

        return $h . '</div>';
    }

    private function form(string $op, string $label, string $class = 'button'): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="alt_text_fill_settings" />' . Screens::nonceField(Screens::NONCE_SETTINGS)
            . '<button class="' . esc_attr($class) . '" name="op" value="' . esc_attr($op) . '">' . esc_html($label) . '</button></form> ';
    }

    private function settings(): string
    {
        $s = $this->c->settings;
        $switch = $this->c->switches->reason();
        $h = '<p>' . esc_html__('Images and a short slice of the text around them are sent to the provider you choose. Images used only by drafts, private or password-protected posts are not sent unless you include them below.', 'alt-text-fill') . '</p>';
        $h .= '<p>' . ($switch !== null
            ? esc_html(sprintf('Kill switch: ON (%s). No calls are made.', $switch)) . ' ' . $this->form('resume', __('Resume', 'alt-text-fill'))
            : esc_html__('Kill switch: off.', 'alt-text-fill') . ' ' . $this->form('pause', __('Pause now', 'alt-text-fill')));
        $h .= $this->form('test', __('Test connection (one call)', 'alt-text-fill'));
        $h .= $this->form('dry_run', __('Dry run now', 'alt-text-fill'));
        if (!$s->bool('dry_run_only')) {
            $h .= $this->form('run', __('Run now', 'alt-text-fill'), 'button button-primary');
        }
        $h .= '</p>';

        $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $h .= '<input type="hidden" name="action" value="alt_text_fill_settings" /><input type="hidden" name="op" value="save" />' . Screens::nonceField(Screens::NONCE_SETTINGS);
        $h .= '<table class="form-table">';
        $h .= $this->checkbox('dry_run_only', 'Dry-run-only mode (real runs refused)');
        $h .= '<tr><th>' . esc_html__('Adapter', 'alt-text-fill') . '</th><td><select name="settings[adapter]"><option value="">' . esc_html__('none', 'alt-text-fill') . '</option>';
        foreach ($this->c->adapters->all() as $adapter) {
            $h .= '<option value="' . esc_attr($adapter->id()) . '"' . selected($adapter->id(), $s->string('adapter'), false) . '>' . esc_html($adapter->label()) . '</option>';
        }
        $h .= '</select></td></tr>';
        $active = $this->c->adapters->active();
        if ($active !== null) {
            $values = $s->adapterSettings($active->id());
            foreach ($active->settingsFields() as $field) {
                $name = 'settings[adapter_settings][' . $active->id() . '][' . $field['key'] . ']';
                $value = (string) ($values[$field['key']] ?? (is_bool($field['default']) ? '' : $field['default']));
                $h .= '<tr><th>' . esc_html($field['label']) . '</th><td>';
                if ($field['secret']) {
                    $fromConstant = $field['key'] === 'api_key' && Settings::apiKeyOverride() !== null;
                    $h .= $fromConstant
                        ? esc_html(sprintf('Set by constant or environment (%s).', Redactor::mask($value)))
                        : '<input type="password" autocomplete="off" name="' . esc_attr($name) . '" value="" placeholder="' . esc_attr($value !== '' ? Redactor::mask($value) : '') . '" /> <small>' . esc_html__('Leave empty to keep the stored key.', 'alt-text-fill') . '</small>';
                } elseif ($field['type'] === 'checkbox') {
                    $on = $values[$field['key']] ?? $field['default'];
                    $h .= '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked((bool) $on, true, false) . ' />';
                } elseif ($field['type'] === 'textarea') {
                    $h .= '<textarea name="' . esc_attr($name) . '" rows="3" cols="50">' . esc_textarea($value) . '</textarea>';
                } else {
                    $h .= '<input type="text" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" />';
                }
                $h .= '</td></tr>';
            }
        }
        foreach (
            [
            'per_run_cap' => 'Per-run cap (images)', 'daily_cap' => 'Daily cap (model calls)',
            'price_input' => 'Price per million input tokens (your estimate)', 'price_output' => 'Price per million output tokens (your estimate)',
            'money_cap' => 'Daily money cap (off until prices are entered)', 'currency' => 'Currency label',
            'time_budget' => 'Time budget under WP-Cron (seconds)', 'target_side' => 'Target image side (px)',
            'byte_limit' => 'Byte limit per image', 'context_length' => 'Context length (characters)',
            'shared_threshold' => 'Shared image threshold (posts)', 'placeholder_ids' => 'Placeholder image IDs',
            'min_width' => 'Minimum width (px)', 'min_height' => 'Minimum height (px)',
            'exclude_ids' => 'Exclude attachment IDs', 'exclude_mimes' => 'Exclude MIME types',
            'date_from' => 'Uploaded from (YYYY-MM-DD)', 'date_to' => 'Uploaded to (YYYY-MM-DD)',
            'min_length' => 'Minimum alt length', 'max_length' => 'Maximum alt length (up to 250)',
            'low_confidence' => 'Low confidence threshold', 'decorative_confidence' => 'Confidence needed to suggest decorative',
            'language' => 'Language override (empty: site locale)', 'timeout' => 'Request timeout (seconds)',
            'max_tokens' => 'Output token cap', 'temperature' => 'Temperature',
            'url_lookup_limit' => 'Posts examined per image by URL', 'remote_hosts' => 'Extra hosts allowed for offloaded media',
            'prompt_slug' => 'Prompt template slug (prompt-library)',
            ] as $key => $label
        ) {
            $value = $s->get($key);
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            $h .= '<tr><th>' . esc_html($label) . '</th><td><input type="text" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" /></td></tr>';
        }
        $h .= '<tr><th>' . esc_html__('Send image as', 'alt-text-fill') . '</th><td><select name="settings[send_as]">'
            . '<option value="base64"' . selected('base64', $s->string('send_as'), false) . '>base64</option>'
            . '<option value="url"' . selected('url', $s->string('send_as'), false) . '>' . esc_html__('public URL (only adapters that accept it)', 'alt-text-fill') . '</option></select></td></tr>';
        foreach (
            [
            'schedule' => 'Run on a schedule (WP-Cron)', 'include_nonpublic' => 'Include images used only by non-public posts',
            'include_unattached' => 'Include unattached images', 'weak_alt' => 'Weak-alt mode (treat file names and filler words as empty)',
            'render_fill' => 'Render fill (use stored alt when a rendered image has none)', 'use_gen_queue' => 'Use gen-queue when installed',
            'url_lookup' => 'Find posts by image URL', 'store_context' => 'Store context text for debugging',
            ] as $key => $label
        ) {
            $h .= $this->checkbox($key, $label);
        }
        $h .= '</table><p><button class="button button-primary">' . esc_html__('Save settings', 'alt-text-fill') . '</button></p></form>';

        return $h;
    }

    private function checkbox(string $key, string $label): string
    {
        return '<tr><th>' . esc_html($label) . '</th><td><input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1"' . checked($this->c->settings->bool($key), true, false) . ' /></td></tr>';
    }

    private function status(): string
    {
        $status = $this->c->status->get();
        $h = '<table class="widefat striped">';
        foreach ($status as $key => $value) {
            if (is_array($value)) {
                $value = (string) wp_json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $h .= '<tr><th>' . esc_html(str_replace('_', ' ', (string) $key)) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }

        return $h . '</table>';
    }

    private function runs(): string
    {
        $h = '<table class="widefat striped"><thead><tr><th>Run</th><th>Mode</th><th>Trigger</th><th>Started</th><th>Counts</th><th>Skipped</th><th>Stop reason</th><th></th></tr></thead><tbody>';
        foreach ($this->c->repository->recentRuns(50) as $run) {
            $h .= '<tr><td>' . esc_html($run->id) . '</td><td>' . esc_html($run->mode) . '</td><td>' . esc_html($run->trigger) . '</td><td>'
                . esc_html(wp_date('Y-m-d H:i', $run->started) ?: '') . '</td><td>' . esc_html((string) wp_json_encode($run->counts)) . '</td><td>'
                . esc_html((string) wp_json_encode($run->skipped)) . '</td><td>' . esc_html($run->stopReason) . '</td><td>';
            if ($run->mode === 'real') {
                $h .= $this->undoForm(['run' => $run->id], __('Undo this run', 'alt-text-fill'));
            }
            $h .= '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    private function history(): string
    {
        $days = (int) apply_filters('alt_text_fill_retention_days', 365, 'entries');
        $h = '<p>' . esc_html($days > 0 ? sprintf('Entries are kept for %d days. Undo is possible while an entry exists.', $days) : 'Entries are kept forever.') . '</p>';
        $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="alt_text_fill_undo" />'
            . Screens::nonceField(Screens::NONCE_UNDO) . esc_html__('Undo every plugin write since', 'alt-text-fill')
            . ' <input type="datetime-local" name="since" required /> <button class="button" onclick="return confirm(\'Undo every write since this time?\')">' . esc_html__('Undo', 'alt-text-fill') . '</button></form>';
        $h .= '<table class="widefat striped"><thead><tr><th>Entry</th><th>Attachment</th><th>Action</th><th>Before</th><th>After</th><th>User</th><th>Time</th><th>Run</th><th></th></tr></thead><tbody>';
        foreach ($this->c->repository->findEntries([], 100) as $e) {
            $h .= '<tr><td>' . esc_html((string) $e->id) . '</td><td>' . esc_html((string) $e->attachmentId) . '</td><td>' . esc_html($e->action) . '</td><td>'
                . esc_html($e->previousExists ? '"' . (string) $e->previousValue . '"' : '(absent)') . '</td><td>'
                . esc_html($e->newExists ? '"' . (string) $e->newValue . '"' : '(absent)') . '</td><td>' . esc_html((string) $e->userId) . '</td><td>'
                . esc_html(wp_date('Y-m-d H:i', $e->created) ?: '') . '</td><td>' . esc_html($e->runId) . '</td><td>';
            $h .= $e->isUndone() ? esc_html(sprintf('undone by %d', $e->undoneBy)) : $this->undoForm(['entry' => (string) $e->id], __('Undo', 'alt-text-fill'), true);
            $h .= '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    /**
     * @param array<string, string> $fields
     */
    private function undoForm(array $fields, string $label, bool $forceOption = false): string
    {
        $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="alt_text_fill_undo" />' . Screens::nonceField(Screens::NONCE_UNDO);
        foreach ($fields as $k => $v) {
            $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '" />';
        }
        if ($forceOption) {
            $h .= '<label><input type="checkbox" name="force" value="1" /> ' . esc_html__('even if changed since', 'alt-text-fill') . '</label> <input type="hidden" name="confirm" value="1" />';
        }

        return $h . '<button class="button button-small" onclick="return !this.form.force || !this.form.force.checked || confirm(\'Overwrite the current value?\')">' . esc_html($label) . '</button></form>';
    }
}
