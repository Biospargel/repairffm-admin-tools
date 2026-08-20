<?php
/**
 * Plugin Name: RepairFFM – Buchungen Übersicht & Selbstverwaltung
 * Description: (1) Admin-Übersicht der Termin-Buchungen (rc_booking) – ansehen, bearbeiten, Status setzen, löschen. (2) Shortcode [rfat_manage_booking] für Besucher: eigenen Termin per Code ansehen, stornieren oder verschieben – ganz ohne Name/E-Mail. Rührt die Buchungslogik des Kern-Plugins selbst nicht an.
 * Version: 1.7.0
 * Author: Till (mit Claude)
 * Text Domain: rfat
 * Update URI: https://github.com/Biospargel/repairffm-admin-tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * This plugin deliberately does NOT know the exact custom-field names used
 * by the booking must-use plugin. Instead it inspects each rc_booking post's
 * meta at render time and classifies fields by their VALUE SHAPE (looks like
 * a date? a time? a booking code?). Anything it can't classify is still shown
 * and editable, labelled with its raw meta key, so nothing is hidden.
 *
 * This makes the plugin robust to internal naming choices in the booking
 * plugin without needing direct access to that code.
 */

define('RFAT_STATUS_META', '_rfat_status');
define('RFAT_NONCE_ACTION', 'rfat_save_booking');

// Internal WP core meta keys we never want to show/edit.
function rfat_meta_blocklist() {
    return [
        '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
        '_wp_page_template', '_thumbnail_id', '_wp_desired_post_slug',
        RFAT_STATUS_META,
    ];
}

function rfat_looks_like_datetime($v) {
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', trim($v));
}
function rfat_looks_like_date($v) {
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v));
}
function rfat_looks_like_time($v) {
    return is_string($v) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', trim($v));
}
function rfat_looks_like_code($v) {
    return is_string($v) && preg_match('/^RC-[A-Z0-9]{3,}$/i', trim($v));
}

/**
 * Analyse one booking post and return a structured description:
 * [
 *   'datetime' => DateTime|null,
 *   'datetime_field' => ['type'=>'combined'|'split', 'key'=>..., 'date_key'=>..., 'time_key'=>...] | null,
 *   'code' => ['key'=>..., 'value'=>...] | null,
 *   'other' => [ ['key'=>..., 'value'=>...], ... ],
 * ]
 */
function rfat_analyse_booking($post_id) {
    $all_meta = rfat_get_meta_or_empty($post_id);
    $blocklist = rfat_meta_blocklist();

    $result = [
        'datetime' => null,
        'datetime_field' => null,
        'code' => null,
        'other' => [],
    ];

    $date_key = null;
    $time_key = null;
    $combined_key = null;
    $code_key = null;
    $other = [];

    foreach ($all_meta as $key => $values) {
        if (in_array($key, $blocklist, true)) {
            continue;
        }
        $raw = isset($values[0]) ? $values[0] : '';
        $value = maybe_unserialize($raw);
        if (!is_scalar($value)) {
            continue;
        }
        $value = (string) $value;

        if ($combined_key === null && rfat_looks_like_datetime($value)) {
            $combined_key = $key;
            continue;
        }
        if ($date_key === null && rfat_looks_like_date($value)) {
            $date_key = $key;
            continue;
        }
        if ($time_key === null && rfat_looks_like_time($value)) {
            $time_key = $key;
            continue;
        }
        if ($code_key === null && rfat_looks_like_code($value)) {
            $code_key = $key;
            continue;
        }
        $other[] = ['key' => $key, 'value' => $value];
    }

    // Build datetime. The stored strings are naive local (site timezone) values
    // with no offset info — e.g. "14:30" means 14:30 Europe/Berlin, not UTC.
    // We must parse them using the site's configured timezone, otherwise PHP
    // falls back to the server's default timezone (often UTC) and every
    // displayed time silently drifts by the UTC offset (+1h/+2h in Germany).
    $dt = null;
    $tz = wp_timezone();
    if ($combined_key !== null) {
        $raw = get_post_meta($post_id, $combined_key, true);
        $raw = str_replace('T', ' ', $raw);
        $dt = date_create($raw, $tz) ?: null;
        $result['datetime_field'] = ['type' => 'combined', 'key' => $combined_key];
    } elseif ($date_key !== null) {
        $raw_date = get_post_meta($post_id, $date_key, true);
        $raw_time = $time_key !== null ? get_post_meta($post_id, $time_key, true) : '00:00';
        $dt = date_create(trim($raw_date . ' ' . $raw_time), $tz) ?: null;
        $result['datetime_field'] = ['type' => 'split', 'date_key' => $date_key, 'time_key' => $time_key];
    }
    $result['datetime'] = $dt;

    if ($code_key !== null) {
        $result['code'] = ['key' => $code_key, 'value' => get_post_meta($post_id, $code_key, true)];
    }

    $result['other'] = $other;

    return $result;
}

function rfat_get_meta_or_empty($post_id) {
    $meta = get_post_meta($post_id);
    return is_array($meta) ? $meta : [];
}

function rfat_humanize_key($key) {
    $label = ltrim($key, '_');
    $label = str_replace(['rc_', 'rc-', '_', '-'], [' ', ' ', ' ', ' '], $label);
    $label = trim($label);
    return $label !== '' ? ucfirst($label) : $key;
}

function rfat_get_status($post_id) {
    $status = get_post_meta($post_id, RFAT_STATUS_META, true);
    return $status ? $status : 'offen';
}

function rfat_status_label($status) {
    $labels = [
        'offen'     => 'Offen',
        'erledigt'  => 'Erledigt',
        'storniert' => 'Storniert',
    ];
    return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
}

function rfat_status_color($status) {
    $colors = [
        'offen'     => '#2f7d4f',
        'erledigt'  => '#5b6b62',
        'storniert' => '#b3402f',
    ];
    return isset($colors[$status]) ? $colors[$status] : '#5b6b62';
}

add_action('admin_menu', function () {
    if (!post_type_exists('rc_booking')) {
        add_menu_page(
            'Buchungen Übersicht',
            'Buchungen Übersicht',
            'manage_options',
            'rfat-overview',
            'rfat_render_missing_notice',
            'dashicons-calendar-alt',
            26
        );
        return;
    }
    add_submenu_page(
        'edit.php?post_type=rc_booking',
        'Übersicht',
        'Übersicht',
        'manage_options',
        'rfat-overview',
        'rfat_render_overview_page'
    );
});

function rfat_render_missing_notice() {
    echo '<div class="wrap"><h1>Buchungen Übersicht</h1>';
    echo '<p>Der Buchungs-Beitragstyp <code>rc_booking</code> wurde nicht gefunden. Ist das Buchungs-Plugin aktiv?</p></div>';
}

/**
 * Handle form submissions (save + status change + trash) before any output.
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['rfat_action']) && $_POST['rfat_action'] === 'save' && isset($_POST['post_id'])) {
        $post_id = (int) $_POST['post_id'];
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', RFAT_NONCE_ACTION . '_' . $post_id)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }
        if (get_post_type($post_id) !== 'rc_booking') {
            wp_die('Ungültiger Beitrag.');
        }

        $analysis = rfat_analyse_booking($post_id);

        // Datetime.
        if (!empty($_POST['rfat_date'])) {
            $date = sanitize_text_field($_POST['rfat_date']);
            $time = isset($_POST['rfat_time']) ? sanitize_text_field($_POST['rfat_time']) : '00:00';
            $field = $analysis['datetime_field'];
            if ($field && $field['type'] === 'combined') {
                update_post_meta($post_id, $field['key'], $date . ' ' . $time);
            } elseif ($field && $field['type'] === 'split') {
                update_post_meta($post_id, $field['date_key'], $date);
                if (!empty($field['time_key'])) {
                    update_post_meta($post_id, $field['time_key'], $time);
                }
            }
        }

        // Other dynamic fields.
        if (!empty($_POST['rfat_field_raw']) && is_array($_POST['rfat_field_raw'])) {
            foreach ($_POST['rfat_field_raw'] as $encoded_key => $value) {
                $real_key = rfat_decode_key($encoded_key);
                if ($real_key === '') {
                    continue;
                }
                update_post_meta($post_id, $real_key, sanitize_textarea_field(wp_unslash($value)));
            }
        }

        // Status.
        if (!empty($_POST['rfat_status'])) {
            $status = sanitize_key($_POST['rfat_status']);
            update_post_meta($post_id, RFAT_STATUS_META, $status);
        }

        $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=rc_booking&page=rfat-overview');
        wp_safe_redirect(add_query_arg('rfat_saved', $post_id, $redirect));
        exit;
    }

    if (isset($_POST['rfat_action']) && $_POST['rfat_action'] === 'trash' && isset($_POST['post_id'])) {
        $post_id = (int) $_POST['post_id'];
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rfat_trash_' . $post_id)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }
        if (get_post_type($post_id) === 'rc_booking') {
            wp_trash_post($post_id);
        }
        $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=rc_booking&page=rfat-overview');
        wp_safe_redirect(add_query_arg('rfat_trashed', $post_id, $redirect));
        exit;
    }
});

// Meta keys can contain characters not safe as raw HTML form field names; encode/decode simply.
function rfat_encode_key($key) {
    return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
}
function rfat_decode_key($encoded) {
    $encoded = strtr($encoded, '-_', '+/');
    $decoded = base64_decode($encoded, true);
    return $decoded === false ? '' : $decoded;
}

function rfat_render_overview_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    $tab = isset($_GET['rfat_tab']) ? sanitize_key($_GET['rfat_tab']) : 'kommende';
    if (!in_array($tab, ['kommende', 'vergangene', 'alle'], true)) {
        $tab = 'kommende';
    }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

    $posts = get_posts([
        'post_type'      => 'rc_booking',
        'post_status'    => ['publish', 'draft', 'pending', 'future'],
        'numberposts'    => -1,
    ]);

    $now = current_time('timestamp');
    $rows = [];
    foreach ($posts as $p) {
        $analysis = rfat_analyse_booking($p->ID);
        $dt = $analysis['datetime'];
        $ts = $dt ? $dt->getTimestamp() : null;

        if ($tab === 'kommende' && ($ts === null || $ts < $now)) {
            continue;
        }
        if ($tab === 'vergangene' && ($ts === null || $ts >= $now)) {
            continue;
        }

        if ($search !== '') {
            $haystack = $p->post_title . ' ' . ($analysis['code']['value'] ?? '');
            foreach ($analysis['other'] as $o) {
                $haystack .= ' ' . $o['value'];
            }
            if (stripos($haystack, $search) === false) {
                continue;
            }
        }

        $rows[] = ['post' => $p, 'analysis' => $analysis, 'ts' => $ts];
    }

    usort($rows, function ($a, $b) use ($tab) {
        $ta = $a['ts'] ?? PHP_INT_MAX;
        $tb = $b['ts'] ?? PHP_INT_MAX;
        return $tab === 'vergangene' ? ($tb <=> $ta) : ($ta <=> $tb);
    });

    ?>
    <div class="wrap rfat-wrap">
        <h1>Buchungen – Übersicht</h1>

        <?php if (isset($_GET['rfat_saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>Buchung gespeichert.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['rfat_trashed'])): ?>
            <div class="notice notice-success is-dismissible"><p>Buchung in den Papierkorb verschoben.</p></div>
        <?php endif; ?>

        <h2 class="nav-tab-wrapper">
            <?php foreach (['kommende' => 'Kommende', 'vergangene' => 'Vergangene', 'alle' => 'Alle'] as $key => $label): ?>
                <a href="<?php echo esc_url(add_query_arg(['rfat_tab' => $key, 's' => $search])); ?>"
                   class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </h2>

        <form method="get" style="margin: 14px 0;">
            <input type="hidden" name="post_type" value="rc_booking" />
            <input type="hidden" name="page" value="rfat-overview" />
            <input type="hidden" name="rfat_tab" value="<?php echo esc_attr($tab); ?>" />
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Suche nach Code oder Stichwort …" style="width: 320px;" />
            <button class="button">Suchen</button>
            <?php if ($search !== ''): ?>
                <a class="button" href="<?php echo esc_url(remove_query_arg('s')); ?>">Zurücksetzen</a>
            <?php endif; ?>
        </form>

        <?php if (empty($rows)): ?>
            <p>Keine Buchungen in dieser Ansicht.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:170px;">Termin</th>
                        <th>Details</th>
                        <th style="width:110px;">Code</th>
                        <th style="width:140px;">Status</th>
                        <th style="width:220px;">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $p = $row['post'];
                    $analysis = $row['analysis'];
                    $status = rfat_get_status($p->ID);
                    $edit_id = 'rfat-edit-' . $p->ID;
                    ?>
                    <tr>
                        <td>
                            <?php
                            if ($row['ts']) {
                                echo esc_html(wp_date('D, d.m.Y H:i', $row['ts']));
                            } else {
                                echo '<em>unbekannt</em>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $details = [];
                            foreach ($analysis['other'] as $o) {
                                $details[] = '<strong>' . esc_html(rfat_humanize_key($o['key'])) . ':</strong> ' . esc_html($o['value']);
                            }
                            echo $details ? implode('<br />', $details) : '<em>—</em>';
                            ?>
                        </td>
                        <td><code><?php echo esc_html($analysis['code']['value'] ?? '—'); ?></code></td>
                        <td>
                            <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?php echo esc_attr(rfat_status_color($status)); ?>;color:#fff;font-weight:600;font-size:12px;">
                                <?php echo esc_html(rfat_status_label($status)); ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="button rfat-toggle" data-target="<?php echo esc_attr($edit_id); ?>">Bearbeiten</button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Diese Buchung in den Papierkorb verschieben?');">
                                <?php wp_nonce_field('rfat_trash_' . $p->ID); ?>
                                <input type="hidden" name="rfat_action" value="trash" />
                                <input type="hidden" name="post_id" value="<?php echo esc_attr($p->ID); ?>" />
                                <button type="submit" class="button">Papierkorb</button>
                            </form>
                        </td>
                    </tr>
                    <tr id="<?php echo esc_attr($edit_id); ?>" class="rfat-edit-row" style="display:none;">
                        <td colspan="5" style="background:#f6f7f7;">
                            <form method="post" style="padding:12px;">
                                <?php wp_nonce_field(RFAT_NONCE_ACTION . '_' . $p->ID); ?>
                                <input type="hidden" name="rfat_action" value="save" />
                                <input type="hidden" name="post_id" value="<?php echo esc_attr($p->ID); ?>" />

                                <?php if ($analysis['datetime_field']): ?>
                                    <p>
                                        <label>Datum: <input type="date" name="rfat_date" value="<?php echo esc_attr($row['ts'] ? wp_date('Y-m-d', $row['ts']) : ''); ?>" /></label>
                                        <label style="margin-left:10px;">Uhrzeit: <input type="time" name="rfat_time" value="<?php echo esc_attr($row['ts'] ? wp_date('H:i', $row['ts']) : ''); ?>" /></label>
                                    </p>
                                <?php endif; ?>

                                <?php foreach ($analysis['other'] as $o):
                                    $encoded = rfat_encode_key($o['key']);
                                    $is_long = strlen($o['value']) > 60;
                                    ?>
                                    <p>
                                        <label style="display:block;font-weight:600;margin-bottom:2px;"><?php echo esc_html(rfat_humanize_key($o['key'])); ?></label>
                                        <?php if ($is_long): ?>
                                            <textarea name="rfat_field_raw[<?php echo esc_attr($encoded); ?>]" rows="3" style="width:100%;max-width:480px;"><?php echo esc_textarea($o['value']); ?></textarea>
                                        <?php else: ?>
                                            <input type="text" name="rfat_field_raw[<?php echo esc_attr($encoded); ?>]" value="<?php echo esc_attr($o['value']); ?>" style="width:100%;max-width:480px;" />
                                        <?php endif; ?>
                                    </p>
                                <?php endforeach; ?>

                                <p>
                                    <label style="display:block;font-weight:600;margin-bottom:2px;">Status</label>
                                    <select name="rfat_status">
                                        <?php foreach (['offen', 'erledigt', 'storniert'] as $s): ?>
                                            <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html(rfat_status_label($s)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>

                                <p>
                                    <button type="submit" class="button button-primary">Speichern</button>
                                    <button type="button" class="button rfat-toggle" data-target="<?php echo esc_attr($edit_id); ?>">Abbrechen</button>
                                </p>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <script>
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.rfat-toggle');
        if (!btn) return;
        var row = document.getElementById(btn.getAttribute('data-target'));
        if (row) {
            row.style.display = (row.style.display === 'none' || !row.style.display) ? 'table-row' : 'none';
        }
    });
    </script>
    <?php
}

/* =========================================================================
 * PUBLIC SELF-SERVICE: [rfat_manage_booking]
 * Visitors enter their booking code (the only "credential" this site ever
 * hands out) to view their booking, cancel it, or move it to a new slot.
 * No name/email is collected or required anywhere in this flow.
 * ========================================================================= */

function rfat_normalize_code($code) {
    return strtoupper(trim((string) $code));
}

/**
 * Find a published rc_booking post whose detected code matches.
 * Returns [post, analysis] or null.
 */
function rfat_find_booking_by_code($code) {
    $code = rfat_normalize_code($code);
    if ($code === '') {
        return null;
    }
    $posts = get_posts([
        'post_type'   => 'rc_booking',
        'numberposts' => -1,
        'post_status' => ['publish', 'draft', 'pending', 'future'],
    ]);
    foreach ($posts as $p) {
        $analysis = rfat_analyse_booking($p->ID);
        $found_code = $analysis['code']['value'] ?? '';
        if ($found_code !== '' && rfat_normalize_code($found_code) === $code) {
            return ['post' => $p, 'analysis' => $analysis];
        }
    }
    return null;
}

/**
 * Known short category codes -> the friendly label as shown on the booking
 * page itself. Anything not in this list just falls back to a humanized
 * version of the raw value, so nothing is ever hidden.
 */
function rfat_friendly_category($raw) {
    $map = [
        'it'    => 'IT & Elektronik (Handy · Laptop · IT)',
        'ebike' => 'E-Bike-Service',
    ];
    $key = strtolower(trim((string) $raw));
    if (isset($map[$key])) {
        return $map[$key];
    }
    return rfat_humanize_key((string) $raw);
}

/**
 * Fields we deliberately don't surface to the public (internal identifiers
 * that are redundant with the nicely formatted date/time already shown).
 */
function rfat_public_hidden_field($key) {
    return (bool) preg_match('/slot|internal|hash|token/i', $key);
}

function rfat_public_booking_details_html($post, $analysis) {
    $status = rfat_get_status($post->ID);
    $ts = $analysis['datetime'] ? $analysis['datetime']->getTimestamp() : null;

    ob_start();
    ?>
    <div class="rfat-pub-card">
        <div class="rfat-pub-card-top">
            <div>
                <p class="rfat-pub-eyebrow">Dein Termin</p>
                <p class="rfat-pub-when">
                    <?php echo $ts ? esc_html(wp_date('l, d.m.Y', $ts)) : '<em>Termin unbekannt</em>'; ?>
                </p>
                <?php if ($ts): ?>
                    <p class="rfat-pub-time"><?php echo esc_html(wp_date('H:i', $ts)); ?> Uhr</p>
                <?php endif; ?>
            </div>
            <span class="rfat-pub-status" style="background:<?php echo esc_attr(rfat_status_color($status)); ?>;">
                <?php echo esc_html(rfat_status_label($status)); ?>
            </span>
        </div>
        <dl class="rfat-pub-fields">
            <?php foreach ($analysis['other'] as $o):
                if (rfat_public_hidden_field($o['key'])) {
                    continue;
                }
                // Match on the humanized label, not the raw key: the raw key is
                // usually prefixed (e.g. "_rc_cat"), so an anchored check needs
                // the prefix already stripped to reliably catch "Cat"/"Category".
                $humanized = rfat_humanize_key($o['key']);
                $is_category = (bool) preg_match('/^cat/i', $humanized);
                $label = $is_category ? 'Kategorie' : $humanized;
                $value = $is_category ? rfat_friendly_category($o['value']) : $o['value'];
                if ($value === '') {
                    continue;
                }
                ?>
                <div class="rfat-pub-field">
                    <dt><?php echo esc_html($label); ?></dt>
                    <dd><?php echo esc_html($value); ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('rfat_manage_booking', function ($atts) {
    if (!post_type_exists('rc_booking')) {
        return '<p>Die Terminverwaltung ist gerade nicht verfügbar.</p>';
    }

    $notice = '';
    $found = null;
    $code_value = '';

    // Cancel action.
    if (!empty($_POST['rfat_pub_action']) && $_POST['rfat_pub_action'] === 'cancel'
        && !empty($_POST['rfat_pub_code']) && !empty($_POST['_wpnonce'])) {
        $code_value = sanitize_text_field(wp_unslash($_POST['rfat_pub_code']));
        if (wp_verify_nonce($_POST['_wpnonce'], 'rfat_pub_cancel_' . rfat_normalize_code($code_value))) {
            $match = rfat_find_booking_by_code($code_value);
            if ($match) {
                wp_trash_post($match['post']->ID);
                $notice = '<div class="rfat-pub-notice rfat-pub-success">Dein Termin wurde storniert. Der Slot ist jetzt wieder frei für andere.</div>';
                $code_value = ''; // Clear so the lookup form shows fresh.
                $found = null;
            } else {
                $notice = '<div class="rfat-pub-notice rfat-pub-error">Dieser Code wurde nicht gefunden (eventuell schon storniert).</div>';
            }
        } else {
            $notice = '<div class="rfat-pub-notice rfat-pub-error">Sicherheitsprüfung fehlgeschlagen, bitte erneut versuchen.</div>';
        }
    }

    // Finish reschedule: cancel old code once a new one has been booked.
    if (!empty($_POST['rfat_pub_action']) && $_POST['rfat_pub_action'] === 'finish_reschedule'
        && !empty($_POST['rfat_old_code']) && !empty($_POST['rfat_new_code']) && !empty($_POST['_wpnonce'])) {
        $old_code = sanitize_text_field(wp_unslash($_POST['rfat_old_code']));
        $new_code = sanitize_text_field(wp_unslash($_POST['rfat_new_code']));
        if (wp_verify_nonce($_POST['_wpnonce'], 'rfat_pub_reschedule_' . rfat_normalize_code($old_code))) {
            $new_match = rfat_find_booking_by_code($new_code);
            if (!$new_match) {
                $notice = '<div class="rfat-pub-notice rfat-pub-error">Der neue Code wurde nicht gefunden. Hast du den neuen Termin schon fertig gebucht?</div>';
                $code_value = $old_code;
            } elseif (rfat_normalize_code($new_code) === rfat_normalize_code($old_code)) {
                $notice = '<div class="rfat-pub-notice rfat-pub-error">Das ist noch dein alter Code. Bitte zuerst unten einen neuen Termin buchen.</div>';
                $code_value = $old_code;
            } else {
                $old_match = rfat_find_booking_by_code($old_code);
                if ($old_match) {
                    wp_trash_post($old_match['post']->ID);
                }
                $notice = '<div class="rfat-pub-notice rfat-pub-success">Erledigt! Dein alter Termin wurde storniert, dein neuer Termin (Code ' . esc_html(rfat_normalize_code($new_code)) . ') bleibt bestehen.</div>';
                $code_value = '';
            }
        } else {
            $notice = '<div class="rfat-pub-notice rfat-pub-error">Sicherheitsprüfung fehlgeschlagen, bitte erneut versuchen.</div>';
        }
    }

    // Lookup.
    if (!empty($_POST['rfat_pub_action']) && $_POST['rfat_pub_action'] === 'lookup' && !empty($_POST['rfat_pub_code'])) {
        $code_value = sanitize_text_field(wp_unslash($_POST['rfat_pub_code']));
    }

    if ($code_value !== '') {
        $found = rfat_find_booking_by_code($code_value);
        if (!$found && $notice === '') {
            $notice = '<div class="rfat-pub-notice rfat-pub-error">Kein Termin mit diesem Code gefunden. Bitte prüfe die Schreibweise (z. B. RC-AB12C).</div>';
        }
    }

    ob_start();
    ?>
    <div class="rfat-pub-wrap">
        <style>
            .rfat-pub-wrap { max-width: 560px; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
            .rfat-pub-notice { padding: 14px 18px; border-radius: 14px; margin-bottom: 18px; font-weight: 600; }
            .rfat-pub-success { background: #e8f1eb; color: #1f5a38; }
            .rfat-pub-error { background: #fbeceb; color: #8a2c20; }

            .rfat-pub-card {
                background: #fff;
                border: 1px solid #e7ebe8;
                border-radius: 20px;
                padding: 22px 24px;
                margin: 16px 0;
                box-shadow: 0 6px 20px rgba(31, 47, 39, 0.06);
            }
            .rfat-pub-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
            .rfat-pub-eyebrow { margin: 0 0 4px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6f7d74; }
            .rfat-pub-when { font-weight: 800; font-size: 20px; margin: 0; color: #1c2a22; }
            .rfat-pub-time { margin: 2px 0 0; font-size: 15px; color: #5b6b62; font-weight: 600; }
            .rfat-pub-status { flex-shrink: 0; display: inline-block; padding: 5px 14px; border-radius: 999px; color: #fff; font-weight: 700; font-size: 12px; letter-spacing: .02em; }

            .rfat-pub-fields { margin: 18px 0 0; padding-top: 16px; border-top: 1px solid #eef1ef; display: grid; gap: 10px; }
            .rfat-pub-field { display: flex; justify-content: space-between; gap: 16px; }
            .rfat-pub-field dt { margin: 0; color: #5b6b62; font-size: 14px; }
            .rfat-pub-field dd { margin: 0; font-weight: 600; color: #1c2a22; font-size: 14px; text-align: right; }

            .rfat-pub-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 18px 0; }
            .rfat-pub-reschedule { border: 2px dashed #d7e0da; border-radius: 18px; padding: 20px; margin-top: 20px; background: #fbfcfb; }
            .rfat-pub-reschedule ol { margin: 6px 0 0; padding-left: 20px; }
            .rfat-pub-reschedule li { margin-bottom: 4px; }

            .rfat-pub-code-input {
                font-size: 16px;
                padding: 12px 16px;
                border: 1.5px solid #d7e0da;
                border-radius: 14px;
                width: 100%;
                max-width: 280px;
                box-sizing: border-box;
                transition: border-color .15s ease, box-shadow .15s ease;
            }
            .rfat-pub-code-input:focus {
                outline: none;
                border-color: #2f7d4f;
                box-shadow: 0 0 0 3px rgba(47, 125, 79, 0.15);
            }
        </style>

        <?php echo $notice; ?>

        <?php if (!$found): ?>
            <form method="post">
                <input type="hidden" name="rfat_pub_action" value="lookup" />
                <p>
                    <label for="rfat_pub_code" style="display:block;font-weight:600;margin-bottom:6px;">Dein Buchungscode</label>
                    <input class="rfat-pub-code-input" type="text" id="rfat_pub_code" name="rfat_pub_code" placeholder="RC-AB12C" value="<?php echo esc_attr($code_value); ?>" required />
                </p>
                <button type="submit" class="btn">Termin anzeigen</button>
            </form>
        <?php else:
            $post = $found['post'];
            $analysis = $found['analysis'];
            $norm_code = rfat_normalize_code($analysis['code']['value']);
            ?>
            <?php echo rfat_public_booking_details_html($post, $analysis); ?>

            <div class="rfat-pub-actions">
                <form method="post" onsubmit="return confirm('Diesen Termin wirklich stornieren?');">
                    <?php wp_nonce_field('rfat_pub_cancel_' . $norm_code); ?>
                    <input type="hidden" name="rfat_pub_action" value="cancel" />
                    <input type="hidden" name="rfat_pub_code" value="<?php echo esc_attr($norm_code); ?>" />
                    <button type="submit" class="btn ghost">Termin stornieren</button>
                </form>
                <button type="button" class="btn ghost" onclick="var el=document.getElementById('rfat-reschedule-<?php echo esc_js($post->ID); ?>'); el.style.display = el.style.display==='none' ? 'block' : 'none';">Termin verschieben</button>
            </div>

            <div id="rfat-reschedule-<?php echo esc_attr($post->ID); ?>" class="rfat-pub-reschedule" style="display:none;">
                <p><strong>So verschiebst du deinen Termin:</strong></p>
                <ol>
                    <li>Buche unten einen neuen Termin über die normale Buchung – du bekommst einen neuen Code.</li>
                    <li>Trage den neuen Code danach hier ein. Wir stornieren dann automatisch deinen alten Termin (<?php echo esc_html($norm_code); ?>).</li>
                </ol>

                <div style="margin: 16px 0;">
                    <?php echo do_shortcode('[repairffm_booking]'); ?>
                </div>

                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field('rfat_pub_reschedule_' . $norm_code); ?>
                    <input type="hidden" name="rfat_pub_action" value="finish_reschedule" />
                    <input type="hidden" name="rfat_old_code" value="<?php echo esc_attr($norm_code); ?>" />
                    <p>
                        <label for="rfat_new_code" style="display:block;font-weight:600;margin-bottom:6px;">Dein neuer Buchungscode</label>
                        <input class="rfat-pub-code-input" type="text" id="rfat_new_code" name="rfat_new_code" placeholder="RC-XY99Z" required />
                    </p>
                    <button type="submit" class="btn">Alten Termin jetzt stornieren</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

/* =========================================================================
 * RESPONSIVE / FLUID BUTTON + LAYOUT CSS
 * Ships as part of this plugin (instead of living only in Customizer ->
 * Additional CSS) so it survives a theme switch and is versioned with the
 * rest of this toolkit. Hooked very late on wp_head so it wins the cascade
 * over the theme's own stylesheet and any Customizer CSS for equal-
 * specificity rules, without needing !important everywhere.
 * ========================================================================= */
add_action('wp_head', function () {
    ?>
    <style id="rfat-responsive-css">
        /* Grundlegende Button-Optik – fluid: skaliert stufenlos zwischen mobil und Desktop */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #2f7d4f;
            color: #fff;
            border: 0;
            border-radius: clamp(9px, 2vw, 11px);
            padding: clamp(10px, 2.5vw, 14px) clamp(16px, 5vw, 26px);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: clamp(14px, 3.8vw, 17px);
            font-weight: 700;
            line-height: 1.25;
            cursor: pointer;
            max-width: 100%;
            box-sizing: border-box;
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }
        .btn:hover,
        .btn:focus-visible {
            background: #256a42;
        }
        .btn.ghost {
            background: transparent;
            color: #2f7d4f;
            border: 2px solid #2f7d4f;
            padding: clamp(8px, 2.2vw, 12px) clamp(14px, 4.5vw, 24px);
        }
        .btn.ghost:hover,
        .btn.ghost:focus-visible {
            background: #e8f1eb;
            color: #1f5a38;
        }

        /* Sicherheitsabstand zum Bildschirmrand, überall */
        html {
            overflow-x: hidden;
        }
        .rfat-pub-wrap {
            max-width: 560px;
            padding: 0 16px;
            box-sizing: border-box;
            margin-left: auto;
            margin-right: auto;
        }
        .rfat-pub-card {
            box-sizing: border-box;
            width: 100%;
        }
        .rfat-pub-code-input {
            max-width: 100%;
            box-sizing: border-box;
        }

        /* Navigations-Buttons (z.B. "Termin buchen" oben): nie randlos/überdimensioniert */
        .wp-block-navigation-item a,
        .wp-block-navigation-item__content {
            padding: clamp(8px, 2.2vw, 14px) clamp(12px, 4vw, 26px) !important;
            line-height: 1.3 !important;
            min-height: 0 !important;
            box-sizing: border-box !important;
        }

        /* ============ Handy-Ansicht: Inhalt vom Rand lösen, alles schlanker ============ */
        @media (max-width: 782px) {
            /*
             * WordPress-Block-Themes steuern den Seitenrand über diese zwei
             * CSS-Variablen (theme.json "root padding"). Dieses Theme setzt
             * sie auf schmalen Screens offenbar nicht — deshalb klebt alles
             * am Rand. Hier global überschreiben: Hintergründe bleiben
             * randlos, nur der INHALT rückt ein (der offizielle Mechanismus).
             */
            :root,
            body {
                --wp--style--root--padding-left: 20px !important;
                --wp--style--root--padding-right: 20px !important;
            }
            /* Fallback für Container, die nicht am Global-Padding hängen */
            .wp-block-post-content,
            .entry-content,
            .wp-block-post-title,
            .wp-site-blocks .is-layout-constrained > :not(.alignfull):not(.has-global-padding),
            .wp-site-blocks .is-layout-flow > :not(.alignfull):not(.has-global-padding) {
                padding-left: 20px;
                padding-right: 20px;
                box-sizing: border-box;
            }
            /* Doppelten Innenabstand vermeiden, wo wir schon selbst polstern */
            .rfat-pub-wrap {
                padding-left: 0;
                padding-right: 0;
            }

            /* Überschriften deutlich schlanker (Hero kann h1 oder h2 sein) */
            h1 { font-size: clamp(28px, 8.5vw, 36px) !important; line-height: 1.12 !important; }
            h2 { font-size: clamp(24px, 7vw, 30px) !important; line-height: 1.15 !important; }

            /*
             * ALLE Theme-Buttons kompakter: .wp-element-button ist die
             * Standardklasse, die WordPress jedem Block-Button gibt —
             * deckt den grünen Nav-Pill UND den Hero-Button ab.
             */
            .wp-element-button,
            .wp-block-button__link {
                padding: 10px 18px !important;
                font-size: 16px !important;
                border-radius: 10px !important;
            }

            /* Navigation kompakter */
            .wp-block-navigation-item a,
            .wp-block-navigation-item__content {
                padding: 8px 14px !important;
                font-size: 15px !important;
                line-height: 1.3 !important;
            }

            /* Alle .btn-Buttons (Buchungswidget) schlanker */
            .btn {
                padding: 10px 18px;
                font-size: 15px;
                border-radius: 10px;
                gap: 6px;
            }
            .btn.ghost {
                padding: 8px 16px;
            }

            /* ---- Modernes Handy-Menü (Overlay des core/navigation-Blocks) ---- */
            .wp-block-navigation__responsive-container-open,
            .wp-block-navigation__responsive-container-close {
                padding: 10px !important;
                border-radius: 10px;
                color: #1c2a22;
            }
            .wp-block-navigation__responsive-container-open svg,
            .wp-block-navigation__responsive-container-close svg {
                width: 28px;
                height: 28px;
            }
            .wp-block-navigation__responsive-container.is-menu-open {
                background: #f7f8f6 !important;
                padding: 76px 22px 28px !important;
            }
            .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation__container {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
                width: 100%;
            }
            .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item {
                width: 100%;
            }
            .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item a,
            .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item__content {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                font-size: 19px !important;
                font-weight: 700 !important;
                padding: 15px 18px !important;
                border-radius: 14px !important;
                color: #1c2a22 !important;
                background: #fff;
                border: 1px solid #e7ebe8;
            }
            .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item a:active,
            .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item a:hover {
                background: #e8f1eb;
                border-color: #2f7d4f;
            }
            /* Der aktuelle grüne "Termin buchen"-Pill bleibt auch im Overlay grün */
            .wp-block-navigation__responsive-container.is-menu-open .wp-element-button,
            .wp-block-navigation__responsive-container.is-menu-open .current-menu-item > a {
                background: #2f7d4f !important;
                color: #fff !important;
                border-color: #2f7d4f !important;
            }
        }

        /* Ab sehr schmalen Screens: Aktions-Buttons stapeln statt quetschen */
        @media (max-width: 420px) {
            .rfat-pub-actions {
                flex-direction: column;
            }
            .rfat-pub-actions .btn {
                width: 100%;
            }
        }
    </style>
    <?php
}, 999);

/*
 * Das 📅-Emoji im Buchungs-Button (kommt aus dem Kern-Buchungsplugin, das wir
 * nicht anfassen) wirkt auf iOS klobig — hier per unauffälligem Skript
 * entfernen, der Buttontext bleibt unverändert.
 */
add_action('wp_footer', function () {
    ?>
    <script id="rfat-btn-cleanup">
    (function () {
        var b = document.getElementById('rc-open');
        if (b) { b.textContent = b.textContent.replace(/[\u{1F4C5}\u{FE0F}]/gu, '').trim(); }

        /*
         * Fallback: Falls die Navigation kein core/navigation-Block ist und
         * der Server-Filter unten deshalb nicht griff, "Termin abrufen"
         * hier clientseitig neben "Termine & Ort" einhängen.
         */
        if (!document.querySelector('a[href*="termin-abrufen"]')) {
            var ref = document.querySelector('header a[href*="termine-ort"], nav a[href*="termine-ort"]');
            if (ref) {
                var li = ref.closest('li');
                if (li) {
                    var clone = li.cloneNode(true);
                    var a = clone.querySelector('a');
                    a.setAttribute('href', '/termin-abrufen/');
                    a.textContent = 'Termin abrufen';
                    li.parentNode.insertBefore(clone, li.nextSibling);
                } else {
                    var a2 = ref.cloneNode(false);
                    a2.setAttribute('href', '/termin-abrufen/');
                    a2.textContent = 'Termin abrufen';
                    ref.parentNode.insertBefore(a2, ref.nextSibling);
                }
            }
        }
    })();
    </script>
    <?php
}, 999);

/* =========================================================================
 * NAVIGATION: "Termin abrufen" ins Menü + modernes Handy-Menü (Hamburger)
 *
 * Beides ohne Eingriff ins Theme:
 * - render_block_data zwingt den core/navigation-Block in den mobilen
 *   Overlay-Modus (WordPress' eingebautes Hamburger-Menü — barrierefrei,
 *   mit Schließen-Button und Fokus-Handling frei Haus).
 * - render_block_core/navigation hängt den Menüpunkt "Termin abrufen"
 *   serverseitig an die Linkliste an (kein Aufblitzen beim Laden).
 * ========================================================================= */
add_filter('render_block_data', function ($block) {
    if (($block['blockName'] ?? '') === 'core/navigation') {
        $block['attrs']['overlayMenu'] = 'mobile';
        $block['attrs']['hasIcon']     = true;
    }
    return $block;
});

add_filter('render_block_core/navigation', function ($content) {
    if (strpos($content, 'termin-abrufen') !== false) {
        return $content; // Schon vorhanden (z. B. manuell ergänzt).
    }
    $item = '<li class="wp-block-navigation-item wp-block-navigation-link">'
          . '<a class="wp-block-navigation-item__content" href="' . esc_url(home_url('/termin-abrufen/')) . '">'
          . '<span class="wp-block-navigation-item__label">Termin abrufen</span>'
          . '</a></li>';
    $pos = strrpos($content, '</ul>');
    if ($pos !== false) {
        $content = substr_replace($content, $item . '</ul>', $pos, 5);
    }
    return $content;
});

/* =========================================================================
 * AUTO-UPDATE VON GITHUB
 *
 * Nutzt den offiziellen WordPress-Mechanismus (seit 5.8): der "Update URI"-
 * Header im Plugin-Kopf oben lässt WordPress den Filter
 * update_plugins_<hostname> fragen, statt wordpress.org. Wir antworten mit
 * den Daten des neuesten GitHub-Releases.
 *
 * Damit ein Update erkannt wird, braucht es im Repo ein Release mit:
 *  - Tag wie "v1.8.0" oder "1.8.0" (muss zur Version im Plugin-Kopf passen)
 *  - der fertigen Plugin-Zip als Release-Asset (NICHT GitHubs
 *    automatisches "Source code (zip)" — das hat den falschen Ordnernamen)
 * ========================================================================= */

define('RFAT_GH_REPO', 'Biospargel/repairffm-admin-tools');
define('RFAT_GH_CACHE_KEY', 'rfat_github_release');

/**
 * Neuestes Release von GitHub holen (mit Cache, damit das API-Limit von
 * 60 Anfragen/Stunde nicht ausgereizt wird).
 *
 * @param bool $force Cache umgehen (z. B. bei "Erneut prüfen").
 * @return array{version:string,package:string,url:string}|null
 */
function rfat_fetch_latest_release($force = false) {
    if (!$force) {
        $cached = get_site_transient(RFAT_GH_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === 'none') {
            return null; // Kein Release / Fehler, nicht sofort erneut fragen.
        }
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . RFAT_GH_REPO . '/releases/latest',
        [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'RepairFFM-Admin-Tools',
            ],
        ]
    );

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        set_site_transient(RFAT_GH_CACHE_KEY, 'none', HOUR_IN_SECONDS);
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['tag_name'])) {
        set_site_transient(RFAT_GH_CACHE_KEY, 'none', HOUR_IN_SECONDS);
        return null;
    }

    // Die hochgeladene Plugin-Zip suchen (Source-Code-Zip ignorieren).
    $package = '';
    if (!empty($body['assets']) && is_array($body['assets'])) {
        foreach ($body['assets'] as $asset) {
            if (!empty($asset['browser_download_url'])
                && substr((string) $asset['name'], -4) === '.zip') {
                $package = $asset['browser_download_url'];
                break;
            }
        }
    }
    if ($package === '') {
        set_site_transient(RFAT_GH_CACHE_KEY, 'none', HOUR_IN_SECONDS);
        return null;
    }

    $release = [
        'version' => ltrim((string) $body['tag_name'], 'vV'),
        'package' => $package,
        'url'     => !empty($body['html_url']) ? $body['html_url'] : 'https://github.com/' . RFAT_GH_REPO,
    ];

    set_site_transient(RFAT_GH_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);

    return $release;
}

/**
 * WordPress fragt hier nach Updates, weil "Update URI" auf github.com zeigt.
 * Der Versionsvergleich passiert im Core — wir liefern nur die Release-Daten.
 */
add_filter('update_plugins_github.com', function ($update, $plugin_data, $plugin_file) {
    if (($plugin_data['UpdateURI'] ?? '') !== 'https://github.com/' . RFAT_GH_REPO) {
        return $update; // Anderes Plugin mit GitHub-Update-URI: nicht anfassen.
    }

    $release = rfat_fetch_latest_release();
    if (!$release) {
        return $update;
    }

    return [
        'slug'    => dirname($plugin_file),
        'version' => $release['version'],
        'url'     => $release['url'],
        'package' => $release['package'],
    ];
}, 10, 3);

// Bei "Erneut prüfen" im Dashboard den Cache verwerfen, damit sofort die
// echten GitHub-Daten geholt werden statt der bis zu 6 Stunden alten.
add_action('load-update-core.php', function () {
    if (isset($_GET['force-check'])) {
        delete_site_transient(RFAT_GH_CACHE_KEY);
    }
});
