<?php
/**
 * Plugin Name: RepairFFM – Buchungen Übersicht & Selbstverwaltung
 * Description: (1) Admin-Übersicht der Termin-Buchungen (rc_booking) – ansehen, bearbeiten, Status setzen, löschen. (2) Shortcode [rfat_manage_booking] für Besucher: eigenen Termin per Code ansehen, stornieren oder verschieben – ohne Konto, E-Mail nur freiwillig. Rührt die Buchungslogik des Kern-Plugins selbst nicht an.
 * Version: 1.8.5
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

/*
 * Freiwillige E-Mail-Benachrichtigung.
 *
 * Die Seite wirbt mit Datensparsamkeit. Das bleibt der
 * Normalfall: Ohne Eintrag ändert sich nichts, der Code bleibt das einzige
 * Credential. Wer benachrichtigt werden möchte, trägt seine Adresse selbst
 * ein — und entscheidet mit einem zweiten, getrennten Haken, ob sie über den
 * Termin hinaus gespeichert bleiben darf.
 *
 * Ohne diesen Haken löscht rfat_cleanup_emails() die Adresse nach dem Termin
 * automatisch. Zwei Entscheidungen, zwei Häkchen — nicht eines für beides.
 */
define('RFAT_EMAIL_META', '_rfat_email');
define('RFAT_EMAIL_KEEP_META', '_rfat_email_keep');
define('RFAT_CLEANUP_HOOK', 'rfat_cleanup_emails');

// Internal WP core meta keys we never want to show/edit.
function rfat_meta_blocklist() {
    return [
        '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
        '_wp_page_template', '_thumbnail_id', '_wp_desired_post_slug',
        RFAT_STATUS_META, RFAT_EMAIL_META, RFAT_EMAIL_KEEP_META,
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
                        <td>
                            <code><?php echo esc_html($analysis['code']['value'] ?? '—'); ?></code>
                            <?php
                            /*
                             * Freiwillig hinterlassene Adresse. Steht bewusst
                             * hier und nicht unter den erkannten Feldern: Sie
                             * darf nicht versehentlich mitbearbeitet werden,
                             * und ob sie über den Termin hinaus bleiben darf,
                             * hat allein der Besucher entschieden.
                             */
                            $mail = (string) get_post_meta($p->ID, RFAT_EMAIL_META, true);
                            if ($mail !== '') :
                                $keep = get_post_meta($p->ID, RFAT_EMAIL_KEEP_META, true) === '1';
                                ?>
                                <div style="margin-top:6px;font-size:12px;line-height:1.5;">
                                    <a href="mailto:<?php echo esc_attr($mail); ?>"><?php echo esc_html($mail); ?></a><br />
                                    <span style="color:<?php echo $keep ? '#5b6b62' : '#8a6d1f'; ?>;">
                                        <?php echo $keep
                                            ? 'darf gespeichert bleiben'
                                            : 'wird nach dem Termin gelöscht'; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
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
                /*
                 * Der Termin findet nicht statt — damit entfällt der Zweck,
                 * für den die Adresse erhoben wurde. Sie geht sofort mit,
                 * es sei denn, weitere Speicherung wurde ausdrücklich erlaubt.
                 */
                if (get_post_meta($match['post']->ID, RFAT_EMAIL_KEEP_META, true) !== '1') {
                    delete_post_meta($match['post']->ID, RFAT_EMAIL_META);
                    delete_post_meta($match['post']->ID, RFAT_EMAIL_KEEP_META);
                }
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

    // Freiwillige E-Mail speichern oder entfernen.
    if (!empty($_POST['rfat_pub_action']) && $_POST['rfat_pub_action'] === 'email'
        && !empty($_POST['rfat_pub_code']) && !empty($_POST['_wpnonce'])) {
        $code_value = sanitize_text_field(wp_unslash($_POST['rfat_pub_code']));
        if (!wp_verify_nonce($_POST['_wpnonce'], 'rfat_pub_email_' . rfat_normalize_code($code_value))) {
            $notice = '<div class="rfat-pub-notice rfat-pub-error">Sicherheitsprüfung fehlgeschlagen, bitte erneut versuchen.</div>';
        } else {
            $match = rfat_find_booking_by_code($code_value);
            if (!$match) {
                $notice = '<div class="rfat-pub-notice rfat-pub-error">Dieser Code wurde nicht gefunden.</div>';
            } else {
                $pid   = $match['post']->ID;
                $email = isset($_POST['rfat_pub_email'])
                    ? sanitize_email(wp_unslash($_POST['rfat_pub_email'])) : '';
                $keep  = !empty($_POST['rfat_pub_email_keep']);

                if ($email === '') {
                    // Leeres Feld heißt: wieder löschen.
                    delete_post_meta($pid, RFAT_EMAIL_META);
                    delete_post_meta($pid, RFAT_EMAIL_KEEP_META);
                    $notice = '<div class="rfat-pub-notice rfat-pub-success">Deine E-Mail-Adresse wurde gelöscht. Dein Termin bleibt bestehen.</div>';
                } elseif (!is_email($email)) {
                    $notice = '<div class="rfat-pub-notice rfat-pub-error">Das sieht nicht nach einer gültigen E-Mail-Adresse aus.</div>';
                } else {
                    update_post_meta($pid, RFAT_EMAIL_META, $email);
                    if ($keep) {
                        update_post_meta($pid, RFAT_EMAIL_KEEP_META, '1');
                    } else {
                        delete_post_meta($pid, RFAT_EMAIL_KEEP_META);
                    }
                    $notice = '<div class="rfat-pub-notice rfat-pub-success">Gespeichert. '
                        . ($keep
                            ? 'Deine Adresse bleibt gespeichert, bis du sie hier wieder löschst.'
                            : 'Deine Adresse wird nach dem Termin automatisch gelöscht.')
                        . '</div>';
                }
            }
        }
    }

    // Lookup per Formular.
    if (!empty($_POST['rfat_pub_action']) && $_POST['rfat_pub_action'] === 'lookup' && !empty($_POST['rfat_pub_code'])) {
        $code_value = sanitize_text_field(wp_unslash($_POST['rfat_pub_code']));
    }

    /*
     * Lookup per Link: /termin-abrufen/?code=RC-AB12C
     *
     * Bisher ging der Abruf nur über das Formular — ein Termin ließ sich
     * also nicht verlinken, nur abtippen. Mit dem GET-Weg entsteht eine
     * Adresse, die sich speichern und teilen lässt.
     *
     * Sicherheitslage unverändert: Der Code war schon immer das einzige
     * Credential, wer ihn kennt, sieht den Termin. Neu ist nur, dass er
     * jetzt auch in der Adresszeile stehen darf — und damit in Verlauf
     * und Lesezeichen landen kann. Genau das ist hier der Zweck.
     */
    if ($code_value === '' && !empty($_GET['code'])) {
        $code_value = sanitize_text_field(wp_unslash($_GET['code']));
    }

    /*
     * WordPress' Cron läuft nur bei Seitenaufrufen; auf einer ruhigen Seite
     * kann die Löschung dadurch liegen bleiben. Diese Seite wird von
     * Besuchern angesteuert, also räumen wir hier zusätzlich auf — gedeckelt
     * auf einmal pro Tag, damit es keine Last erzeugt.
     */
    if (!get_transient('rfat_cleanup_ran')) {
        set_transient('rfat_cleanup_ran', 1, DAY_IN_SECONDS);
        rfat_cleanup_emails();
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

            .rfat-pub-mail {
                margin: 18px 0;
                padding: 16px 18px;
                border: 1px solid #e7ebe8;
                border-radius: 16px;
                background: #fff;
            }
            .rfat-pub-mail-label { display: block; font-weight: 700; font-size: 15px; color: #1c2a22; }
            .rfat-pub-optional {
                display: inline-block; margin-left: 6px; padding: 2px 9px;
                border-radius: 999px; background: #e8f1eb; color: #1f5a38;
                font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
                vertical-align: middle;
            }
            .rfat-pub-mail-intro { margin: 8px 0 12px; font-size: 13px; color: #5b6b62; line-height: 1.5; }
            .rfat-pub-mail-input { max-width: 100%; }
            .rfat-pub-keep {
                display: flex; gap: 10px; align-items: flex-start;
                margin: 12px 0; font-size: 13px; color: #1c2a22; line-height: 1.5;
                cursor: pointer;
            }
            .rfat-pub-keep input { margin-top: 3px; flex-shrink: 0; width: 18px; height: 18px; }
            .rfat-pub-keep em { display: block; color: #5b6b62; font-style: normal; }
            .rfat-pub-mail-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
            .rfat-pub-mail-state { font-size: 12px; color: #5b6b62; line-height: 1.5; }

            .rfat-pub-share {
                margin: 18px 0;
                padding: 16px 18px;
                background: #f4f8f5;
                border: 1px solid #dbe6df;
                border-radius: 16px;
            }
            .rfat-pub-share-label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 8px; color: #1c2a22; }
            .rfat-pub-share-row { display: flex; gap: 8px; flex-wrap: wrap; }
            .rfat-pub-share-url {
                flex: 1 1 220px;
                min-width: 0;
                font-size: 15px;
                padding: 11px 14px;
                border: 1.5px solid #d7e0da;
                border-radius: 12px;
                background: #fff;
                color: #1c2a22;
                box-sizing: border-box;
            }
            .rfat-pub-copy { flex: 0 0 auto; }
            .rfat-pub-copy.is-done { background: #1f5a38; }
            .rfat-pub-share-hint { margin: 10px 0 0; font-size: 13px; color: #5b6b62; }

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

            <?php
            // Direktlink auf genau diesen Termin — zum Speichern statt Abtippen.
            $share_url = add_query_arg('code', $norm_code, get_permalink());
            ?>
            <div class="rfat-pub-share">
                <label class="rfat-pub-share-label" for="rfat-share-<?php echo esc_attr($post->ID); ?>">
                    Dein persönlicher Link — damit kommst du jederzeit wieder hierher:
                </label>
                <div class="rfat-pub-share-row">
                    <input class="rfat-pub-share-url" type="text" readonly
                           id="rfat-share-<?php echo esc_attr($post->ID); ?>"
                           value="<?php echo esc_url($share_url); ?>"
                           onfocus="this.select();" />
                    <button type="button" class="btn rfat-pub-copy"
                            data-target="rfat-share-<?php echo esc_attr($post->ID); ?>">Kopieren</button>
                </div>
                <p class="rfat-pub-share-hint">
                    Bewahre ihn gut auf: Wir speichern zu deinem Termin weder Namen
                    noch Konto, deshalb ist er zusammen mit deinem Code
                    <?php echo esc_html($norm_code); ?> dein Weg zurück.
                </p>
            </div>

            <?php
            $cur_email = (string) get_post_meta($post->ID, RFAT_EMAIL_META, true);
            $cur_keep  = get_post_meta($post->ID, RFAT_EMAIL_KEEP_META, true) === '1';
            ?>
            <form method="post" class="rfat-pub-mail">
                <?php wp_nonce_field('rfat_pub_email_' . $norm_code); ?>
                <input type="hidden" name="rfat_pub_action" value="email" />
                <input type="hidden" name="rfat_pub_code" value="<?php echo esc_attr($norm_code); ?>" />

                <label class="rfat-pub-mail-label" for="rfat-mail-<?php echo esc_attr($post->ID); ?>">
                    Benachrichtigung per E-Mail <span class="rfat-pub-optional">freiwillig</span>
                </label>
                <p class="rfat-pub-mail-intro">
                    Normalerweise speichern wir zu deinem Termin gar nichts – kein Name,
                    keine Adresse. Wenn du über den weiteren Verlauf informiert werden
                    möchtest, kannst du hier freiwillig eine E-Mail hinterlassen.
                    Für den Termin selbst ist das nicht nötig.
                </p>

                <input class="rfat-pub-code-input rfat-pub-mail-input" type="email"
                       id="rfat-mail-<?php echo esc_attr($post->ID); ?>"
                       name="rfat_pub_email" placeholder="dein@beispiel.de"
                       value="<?php echo esc_attr($cur_email); ?>" />

                <label class="rfat-pub-keep">
                    <input type="checkbox" name="rfat_pub_email_keep" value="1"
                           <?php checked($cur_keep); ?> />
                    <span>
                        Meine Adresse darf auch <strong>nach dem Termin</strong> gespeichert bleiben.
                        <em>Ohne Haken wird sie nach dem Termin automatisch gelöscht.</em>
                    </span>
                </label>

                <div class="rfat-pub-mail-actions">
                    <button type="submit" class="btn"><?php echo $cur_email !== '' ? 'Änderung speichern' : 'E-Mail speichern'; ?></button>
                    <?php if ($cur_email !== ''): ?>
                        <span class="rfat-pub-mail-state">
                            Gespeichert: <strong><?php echo esc_html($cur_email); ?></strong> —
                            <?php echo $cur_keep
                                ? 'bleibt gespeichert, bis du sie löschst'
                                : 'wird nach dem Termin gelöscht'; ?>.
                            Zum Löschen einfach das Feld leeren und speichern.
                        </span>
                    <?php endif; ?>
                </div>
            </form>

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

        <script>
        (function () {
            var btns = document.querySelectorAll('.rfat-pub-copy');
            if (!btns.length) { return; }

            Array.prototype.forEach.call(btns, function (btn) {
                btn.addEventListener('click', function () {
                    var field = document.getElementById(btn.getAttribute('data-target'));
                    if (!field) { return; }

                    function done() {
                        var before = btn.textContent;
                        btn.textContent = 'Kopiert ✓';
                        btn.classList.add('is-done');
                        window.setTimeout(function () {
                            btn.textContent = before;
                            btn.classList.remove('is-done');
                        }, 2000);
                    }

                    /* Die Zwischenablage-API gibt es nur im sicheren Kontext
                     * und nicht in älteren Browsern — deshalb der alte Weg
                     * als Rückfall. Klappt beides nicht, bleibt der Text
                     * markiert und lässt sich von Hand kopieren. */
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(field.value).then(done, function () {
                            field.select();
                        });
                        return;
                    }
                    field.select();
                    field.setSelectionRange(0, 99999);
                    try {
                        if (document.execCommand('copy')) { done(); }
                    } catch (e) { /* Auswahl bleibt bestehen */ }
                });
            });
        })();
        </script>
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
 *
 * Breakpoints: Layout-Anpassungen laufen bis 782px, das Handy-MENÜ dagegen
 * bis 600px — das ist die Grenze, ab der WordPress' Navigationsblock auf
 * Overlay umschaltet. Vorher standen beide auf 782px, wodurch zwischen 600
 * und 782px Menü-Styling auf eine Desktop-Navigation traf.
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

        /*
         * Waagerechten Überlauf abfangen. Bewusst auf body statt html:
         * "overflow-x: hidden" auf dem html-Element schaltet in allen
         * Browsern "position: sticky" für Kindelemente ab — ein klebriger
         * Theme-Header hätte damit nicht mehr funktioniert.
         */
        body {
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

        /* ============ Eigenständiges Handy-Menü (siehe wp_footer unten) ============ */
        .rfat-nav-open {
            display: none;
            position: fixed;
            top: 12px;
            right: 12px;
            z-index: 99998;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            padding: 0;
            border: 1px solid #cfd8d2;
            border-radius: 12px;
            background: #fff;
            color: #1c2a22;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(28, 42, 34, .12);
        }
        /* Eingeloggt schiebt die Adminleiste alles nach unten (mobil 46px hoch) */
        body.admin-bar .rfat-nav-open {
            top: 58px;
        }
        body.admin-bar .rfat-nav-overlay {
            top: 46px;
        }
        .rfat-nav-overlay {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            background: #f7f8f6;
            /* Lange Menüs müssen scrollbar bleiben, sonst sind untere
               Punkte auf kleinen Displays nicht erreichbar. */
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            opacity: 0;
            transition: opacity .18s ease;
        }
        .rfat-nav-overlay.is-open {
            opacity: 1;
        }
        .rfat-nav-overlay[hidden] {
            display: none;
        }
        .rfat-nav-overlay__bar {
            display: flex;
            justify-content: flex-end;
            padding: 12px;
        }
        .rfat-nav-close {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            padding: 0;
            border: 1px solid #cfd8d2;
            border-radius: 12px;
            background: #fff;
            color: #1c2a22;
            cursor: pointer;
        }
        .rfat-nav-overlay__list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 8px 22px 28px;
        }
        .rfat-nav-link {
            display: block;
            box-sizing: border-box;
            width: 100%;
            padding: 15px 18px;
            border: 1px solid #e7ebe8;
            border-radius: 14px;
            background: #fff;
            color: #1c2a22;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 19px;
            font-weight: 700;
            text-decoration: none;
        }
        .rfat-nav-link:hover,
        .rfat-nav-link:focus-visible {
            background: #e8f1eb;
            border-color: #2f7d4f;
            color: #1f5a38;
        }
        .rfat-nav-link.is-active {
            border-color: #2f7d4f;
            background: #e8f1eb;
            color: #1f5a38;
        }
        /* "Termin buchen" bleibt auch im Menü der grüne Haupt-Button */
        .rfat-nav-link.is-primary,
        .rfat-nav-link.is-primary:hover,
        .rfat-nav-link.is-primary:focus-visible {
            background: #2f7d4f;
            border-color: #2f7d4f;
            color: #fff;
        }
        /* Seite hinter dem offenen Menü nicht mitscrollen lassen */
        .rfat-nav-locked body {
            overflow: hidden;
        }

        @media (max-width: 600px) {
            /* Nur auf dem Handy taucht der Hamburger auf. Das Skript nimmt
               das [hidden] weg, sobald klar ist, dass das Theme kein
               eigenes Menü mitbringt. */
            .rfat-nav-open:not([hidden]) {
                display: flex;
            }

            /* ---- Handy-Menü des Themes, falls doch eins da ist ---- */
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
                overflow-y: auto !important;
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
        }

        /* ============ Buchungs-Popup des Kern-Plugins entschärfen ============
         * Das Modal kommt aus dem mu-Plugin, das wir nicht anfassen. Auf dem
         * Handy lief es aus dem Bild, wodurch der Schließen-Button unerreichbar
         * wurde. Statt den X-Button zu suchen (dessen Klasse wir nicht kennen),
         * deckeln wir die Höhe und machen den Inhalt scrollbar — damit rückt
         * er von selbst in Reichweite.
         *
         * Die Klassennamen stammen aus dem gerenderten HTML und sind NICHT
         * durch Quellcode bestätigt. Greifen sie nicht, bleiben diese Regeln
         * wirkungslos; die Diagnose unten sagt, welcher Fall vorliegt. */
        .rc-overlay {
            align-items: flex-start;
            overflow-y: auto;
            padding: max(16px, env(safe-area-inset-top)) 12px
                     max(16px, env(safe-area-inset-bottom));
        }
        /* Nicht auf .rc-modal verlassen: Der Screenshot vom 21.08. zeigte den
         * Dialog weiterhin unten abgeschnitten, die Klasse heißt also
         * vermutlich anders. Über den direkten Kindselektor trifft die
         * Begrenzung den Dialog unabhängig von seinem Namen. */
        .rc-overlay > * {
            max-height: 85vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            box-sizing: border-box;
        }

        /* Solange der Buchungsdialog offen ist, hat unser Menüknopf dort
         * nichts zu suchen — er lag sonst sichtbar über dem Dialog. */
        .rfat-rc-open .rfat-nav-open {
            display: none !important;
        }
        /* Eingeloggt verdeckt die Adminleiste sonst den oberen Rand */
        body.admin-bar .rc-overlay {
            padding-top: max(62px, env(safe-area-inset-top));
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
    // Die Diagnose-Box unten ist ein Werkzeug für uns, kein Seiteninhalt —
    // Besucher bekommen sie nie zu sehen.
    $show_diag = current_user_can('manage_options') ? 'true' : 'false';
    ?>
    <script id="rfat-btn-cleanup">
    window.rfatShowDiag = <?php echo $show_diag; ?>;
    (function () {
        var b = document.getElementById('rc-open');
        if (b) { b.textContent = b.textContent.replace(/[\u{1F4C5}\u{FE0F}]/gu, '').trim(); }

        /*
         * Diagnose für das Buchungs-Popup: Die Klassennamen .rc-overlay und
         * .rc-modal sind aus dem gerenderten HTML rekonstruiert, nicht aus
         * Quellcode. Ob unser CSS überhaupt etwas trifft, war deshalb bisher
         * reine Vermutung. Nach dem Öffnen setzen wir eine Klasse auf <html>:
         *
         *   rfat-rc-ok      – .rc-modal existiert, unser CSS greift
         *   rfat-rc-missing – Klasse heißt anders, das CSS läuft ins Leere
         *
         * Ein Blick in den Inspektor beantwortet damit eine Frage, die dieses
         * Projekt schon mehrere wirkungslose Versionen gekostet hat.
         */
        /*
         * Ist der Buchungsdialog gerade offen? Wir kennen weder seinen
         * Schließen-Button noch seine genauen Klassen, deshalb wird der
         * Zustand beobachtet statt an Klicks gekoppelt: Ein MutationObserver
         * meldet jede Änderung, und geprüft wird, ob das Overlay tatsächlich
         * sichtbar ist. Das funktioniert auch, wenn der Dialog über einen
         * Weg geschlossen wird, den wir nicht kennen.
         */
        function rcOverlay() {
            var el = document.querySelector('.rc-overlay');
            if (!el) { return null; }
            var cs = window.getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden' || el.offsetParent === null) {
                return null;
            }
            return el;
        }

        var rcWasOpen = false;
        function rcSync() {
            var open = !!rcOverlay();
            if (open === rcWasOpen) { return; }
            rcWasOpen = open;
            document.documentElement.classList.toggle('rfat-rc-open', open);
            if (open) { rcReport(); }
        }

        /* Der Observer hängt am ganzen body und feuert entsprechend oft.
         * Ohne Bremse liefe bei jeder Mutation ein getComputedStyle — deshalb
         * wird die Prüfung auf höchstens einmal pro Frame gebündelt. */
        var rcPending = false;
        function rcQueue() {
            if (rcPending) { return; }
            rcPending = true;
            window.requestAnimationFrame(function () {
                rcPending = false;
                rcSync();
            });
        }

        if (window.MutationObserver) {
            new MutationObserver(rcQueue).observe(document.body, {
                childList: true, subtree: true, attributes: true,
                attributeFilter: ['class', 'style', 'hidden']
            });
        }
        rcSync();

        /*
         * Sichtbare Diagnose – nur für angemeldete Redakteure.
         *
         * Die Klassennamen des Buchungsdialogs stammen aus dem gerenderten
         * HTML und sind nicht durch Quellcode bestätigt; zweimal wurde
         * deshalb CSS geschrieben, das ins Leere lief. Der Entwickler-
         * Inspektor ist auf dem Handy praktisch nicht bedienbar, also zeigt
         * diese Box die echte Struktur direkt auf dem Bildschirm an — ein
         * Screenshot genügt, um die richtigen Selektoren zu erfahren.
         *
         * Für Besucher ist sie unsichtbar (siehe rfatShowDiag unten).
         */
        function rcReport() {
            if (!window.rfatShowDiag) { return; }
            var box = document.getElementById('rfat-diag');
            if (!box) {
                box = document.createElement('div');
                box.id = 'rfat-diag';
                box.setAttribute('style',
                    'position:fixed;left:8px;right:8px;bottom:8px;z-index:100001;' +
                    'background:#1c2a22;color:#fff;font:12px/1.45 ui-monospace,monospace;' +
                    'padding:10px 12px;border-radius:10px;max-height:45vh;overflow:auto;' +
                    'white-space:pre-wrap;word-break:break-all');
                box.addEventListener('click', function () { box.remove(); });
                document.body.appendChild(box);
            }
            var ov = rcOverlay();
            var lines = ['RFAT-Diagnose (antippen zum Schließen)'];
            lines.push('overlay: ' + (ov ? '.' + ov.className.trim().split(/\s+/).join('.') : 'NICHT GEFUNDEN'));
            if (ov && ov.firstElementChild) {
                var d = ov.firstElementChild;
                lines.push('dialog:  <' + d.tagName.toLowerCase() + '> .' +
                    d.className.trim().split(/\s+/).join('.'));
                lines.push('hoehe:   ' + Math.round(d.getBoundingClientRect().height) +
                    'px / Fenster ' + window.innerHeight + 'px');
                var btns = d.querySelectorAll('button, a[role="button"], .close, [aria-label]');
                lines.push('buttons: ' + (btns.length ? '' : 'keine'));
                Array.prototype.slice.call(btns, 0, 8).forEach(function (x) {
                    lines.push('  <' + x.tagName.toLowerCase() + '> "' +
                        (x.textContent || '').trim().slice(0, 18) + '" .' +
                        (x.className.trim() ? x.className.trim().split(/\s+/).join('.') : '(ohne)') +
                        (x.id ? ' #' + x.id : ''));
                });
            }
            box.textContent = lines.join('\n');
        }

        /*
         * Fallback: Falls die Navigation kein core/navigation-Block ist und
         * der Server-Filter deshalb nicht griff, "Termin abrufen"
         * hier clientseitig neben "Termine & Ort" einhängen.
         */
        if (document.querySelector('a[href*="termin-abrufen"]')) { return; }

        var ref = document.querySelector('header a[href*="termine-ort"], nav a[href*="termine-ort"]');
        if (!ref) { return; }

        var li = ref.closest('li');
        if (li) {
            var clone = li.cloneNode(true);
            var a = clone.querySelector('a');
            // Ohne diese Prüfung warf ein <li> ohne Link einen TypeError,
            // der den Rest des Skripts mitgerissen hat.
            if (!a) { return; }
            a.setAttribute('href', '/termin-abrufen/');
            a.textContent = 'Termin abrufen';
            li.parentNode.insertBefore(clone, li.nextSibling);
        } else {
            var a2 = ref.cloneNode(false);
            a2.setAttribute('href', '/termin-abrufen/');
            a2.textContent = 'Termin abrufen';
            ref.parentNode.insertBefore(a2, ref.nextSibling);
        }
    })();
    </script>
    <?php
}, 999);

/* =========================================================================
 * AUFRÄUMEN DER FREIWILLIGEN E-MAIL-ADRESSEN
 *
 * Wer den Haken nicht gesetzt hat, dessen Adresse verschwindet nach dem
 * Termin von selbst. Das ist kein Komfort, sondern die Bedingung, unter der
 * sie überhaupt erhoben wurde — deshalb läuft es automatisch und nicht auf
 * Zuruf.
 *
 * Kulanzfrist von einem Tag, damit eine Nachricht zum Abschluss des Termins
 * noch verschickt werden kann, bevor die Adresse gelöscht wird.
 * ========================================================================= */
define('RFAT_EMAIL_GRACE', DAY_IN_SECONDS);

/**
 * Adressen entfernen, deren Termin vorbei ist und für die keine weitere
 * Speicherung erlaubt wurde.
 *
 * @return int Anzahl der gelöschten Adressen.
 */
function rfat_cleanup_emails() {
    $posts = get_posts([
        'post_type'        => 'rc_booking',
        'posts_per_page'   => 200,
        'post_status'      => 'any',
        'fields'           => 'ids',
        'meta_query'       => [
            [
                'key'     => RFAT_EMAIL_META,
                'compare' => 'EXISTS',
            ],
        ],
        'suppress_filters' => false,
    ]);
    if (!$posts) {
        return 0;
    }

    /*
     * time() und nicht current_time('timestamp'): Letzteres liefert einen um
     * die Zeitzone verschobenen Wert, DateTime::getTimestamp() dagegen einen
     * echten Unix-Zeitstempel. Beides zu vergleichen ginge im Sommer um zwei
     * Stunden daneben — dieselbe Falle wie beim Zeitzonen-Bug in 1.1.1.
     */
    $cutoff  = time() - RFAT_EMAIL_GRACE;
    $removed = 0;

    foreach ($posts as $post_id) {
        // Ausdrücklich erlaubt: bleibt liegen.
        if (get_post_meta($post_id, RFAT_EMAIL_KEEP_META, true) === '1') {
            continue;
        }

        $analysis = rfat_analyse_booking($post_id);
        $dt       = $analysis['datetime'];

        /*
         * Ohne erkennbares Datum lässt sich "nach dem Termin" nicht
         * bestimmen. Dann greift das Alter des Eintrags als Notbremse —
         * liegen bleiben darf die Adresse auf keinen Fall.
         */
        if ($dt) {
            $ts = $dt->getTimestamp();
        } else {
            $ts = get_post_time('U', true, $post_id);
            if (!$ts) {
                continue;
            }
            $ts += 30 * DAY_IN_SECONDS;
        }

        if ($ts > $cutoff) {
            continue;
        }

        delete_post_meta($post_id, RFAT_EMAIL_META);
        delete_post_meta($post_id, RFAT_EMAIL_KEEP_META);
        $removed++;
    }

    return $removed;
}
add_action(RFAT_CLEANUP_HOOK, 'rfat_cleanup_emails');

/*
 * WordPress' Cron läuft nur bei Seitenaufrufen. Auf einer ruhigen Seite kann
 * sich die Löschung dadurch verzögern — deshalb zusätzlich beim Abruf einer
 * Buchung (siehe Shortcode) und nicht allein auf den Zeitplan verlassen.
 */
add_action('init', function () {
    if (!wp_next_scheduled(RFAT_CLEANUP_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', RFAT_CLEANUP_HOOK);
    }
});

register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled(RFAT_CLEANUP_HOOK);
    if ($ts) {
        wp_unschedule_event($ts, RFAT_CLEANUP_HOOK);
    }
});

/* =========================================================================
 * NAVIGATION: "Termin abrufen" ins Menü + Overlay-Modus erzwingen
 *
 * Beides ohne Eingriff ins Theme. Greift nur, wenn die Navigation
 * tatsächlich ein core/navigation-Block ist — ist sie das nicht, übernimmt
 * das eigenständige Handy-Menü weiter unten.
 * ========================================================================= */
add_filter('render_block_data', function ($block) {
    if (($block['blockName'] ?? '') === 'core/navigation') {
        // 'mobile' ist zwar WordPress-Default, aber Themes setzen hier
        // gerne 'never' — dann rendert WordPress gar keinen Hamburger.
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
 * MENÜPUNKTE ERMITTELN (für das eigenständige Handy-Menü unten)
 *
 * Warum überhaupt: Das Handy-Menü des Themes kam nie zustande (kein
 * Hamburger-Button im DOM). Das passiert, wenn die Kopf-Navigation KEIN
 * core/navigation-Block ist — dann greift weder der overlayMenu-Filter noch
 * WordPress' eingebautes Overlay, und es gibt schlicht keinen Button.
 *
 * Statt im Browser nach Links zu suchen (fragil), holen wir die Menüpunkte
 * hier serverseitig aus den echten WordPress-Daten. Reihenfolge:
 *   1. klassisches Menü (falls registriert)
 *   2. wp_navigation-Post (so speichern Block-Themes ihre Menüs)
 *   3. veröffentlichte Top-Level-Seiten
 * ========================================================================= */

/**
 * Menüpunkte aus einem geparsten Block-Baum einsammeln (rekursiv, damit
 * auch Untermenüs und in Gruppen verschachtelte Links mitkommen).
 *
 * @param array $blocks Ergebnis von parse_blocks().
 * @return array<int,array{url:string,label:string}>
 */
function rfat_collect_nav_links($blocks) {
    $items = [];
    foreach ($blocks as $block) {
        $name = $block['blockName'] ?? '';

        if ($name === 'core/navigation-link' || $name === 'core/navigation-submenu') {
            $url   = $block['attrs']['url'] ?? '';
            $label = $block['attrs']['label'] ?? '';
            if ($url !== '' && $label !== '') {
                $items[] = ['url' => $url, 'label' => wp_strip_all_tags($label)];
            }
        } elseif ($name === 'core/page-list') {
            foreach (rfat_menu_items_from_pages() as $page_item) {
                $items[] = $page_item;
            }
        }

        if (!empty($block['innerBlocks'])) {
            foreach (rfat_collect_nav_links($block['innerBlocks']) as $child) {
                $items[] = $child;
            }
        }
    }
    return $items;
}

/**
 * Letzter Rückfallweg: veröffentlichte Top-Level-Seiten in Menü-Reihenfolge.
 *
 * @return array<int,array{url:string,label:string}>
 */
function rfat_menu_items_from_pages() {
    $pages = get_pages([
        'parent'      => 0,
        'sort_column' => 'menu_order,post_title',
        'post_status' => 'publish',
    ]);
    if (!is_array($pages)) {
        return [];
    }
    $items = [];
    foreach ($pages as $page) {
        $items[] = [
            'url'   => get_permalink($page->ID),
            'label' => get_the_title($page->ID),
        ];
    }
    return $items;
}

/**
 * Die Menüpunkte für das Handy-Menü — mit Cache, damit die Block-Analyse
 * nicht bei jedem Seitenaufruf erneut läuft.
 *
 * @return array<int,array{url:string,label:string}>
 */
function rfat_get_menu_items() {
    $cached = get_transient('rfat_menu_items');
    if (is_array($cached)) {
        return $cached;
    }

    $items = [];

    // 1. Klassisches Menü (falls das Theme eins registriert hat).
    $locations = get_nav_menu_locations();
    if (!empty($locations)) {
        foreach ($locations as $menu_id) {
            if (!$menu_id) {
                continue;
            }
            $menu_objects = wp_get_nav_menu_items($menu_id);
            if (!empty($menu_objects)) {
                foreach ($menu_objects as $menu_item) {
                    $items[] = [
                        'url'   => $menu_item->url,
                        'label' => $menu_item->title,
                    ];
                }
                break;
            }
        }
    }

    // 2. Block-Theme: Menü liegt als wp_navigation-Post.
    if (!$items) {
        $navs = get_posts([
            'post_type'        => 'wp_navigation',
            'posts_per_page'   => 1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ]);
        if (!empty($navs[0]->post_content)) {
            $items = rfat_collect_nav_links(parse_blocks($navs[0]->post_content));
        }
    }

    // 3. Nichts gefunden: einfach die Seiten nehmen.
    if (!$items) {
        $items = rfat_menu_items_from_pages();
    }

    // "Termin abrufen" sicherstellen (dieselbe Ergänzung wie in der Navigation).
    $manage_url = home_url('/termin-abrufen/');
    $has_manage = false;
    foreach ($items as $item) {
        if (strpos($item['url'], 'termin-abrufen') !== false) {
            $has_manage = true;
            break;
        }
    }
    if (!$has_manage) {
        $items[] = ['url' => $manage_url, 'label' => 'Termin abrufen'];
    }

    // Doppelte Ziele entfernen, Reihenfolge beibehalten.
    $seen   = [];
    $unique = [];
    foreach ($items as $item) {
        $key = untrailingslashit((string) $item['url']);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[]   = $item;
    }

    set_transient('rfat_menu_items', $unique, 12 * HOUR_IN_SECONDS);

    return $unique;
}

// Menü-Cache verwerfen, wenn sich Menüs oder Seiten ändern.
add_action('wp_update_nav_menu', function () {
    delete_transient('rfat_menu_items');
});
add_action('save_post', function ($post_id, $post) {
    if (in_array($post->post_type, ['page', 'wp_navigation'], true)) {
        delete_transient('rfat_menu_items');
    }
}, 10, 2);

/* =========================================================================
 * EIGENSTÄNDIGES HANDY-MENÜ (Hamburger)
 *
 * Button und Overlay werden komplett serverseitig ausgegeben — unabhängig
 * davon, welchen Navigationsblock das Theme benutzt. Damit gibt es auf dem
 * Handy in jedem Fall ein Menü.
 *
 * Doppelte Hamburger sind ausgeschlossen: Bringt das Theme ein eigenes,
 * funktionierendes Overlay mit (core/navigation), entfernt das Skript unser
 * Menü beim Laden wieder.
 * ========================================================================= */
add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    $items = rfat_get_menu_items();
    if (!$items) {
        return;
    }
    // Aktuelle Seite, um den passenden Menüpunkt zu markieren.
    $current = '';
    if (is_front_page() || is_home()) {
        $current = untrailingslashit(home_url('/'));
    } elseif (is_singular()) {
        $permalink = get_permalink();
        $current   = $permalink ? untrailingslashit($permalink) : '';
    }
    ?>
    <button type="button" class="rfat-nav-open" id="rfat-nav-open"
            aria-label="Menü öffnen" aria-expanded="false" aria-controls="rfat-nav-overlay" hidden>
        <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
        </svg>
    </button>

    <div class="rfat-nav-overlay" id="rfat-nav-overlay" hidden>
        <div class="rfat-nav-overlay__bar">
            <button type="button" class="rfat-nav-close" id="rfat-nav-close" aria-label="Menü schließen">
                <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6 6.4 5z"/>
                </svg>
            </button>
        </div>
        <nav class="rfat-nav-overlay__list" aria-label="Hauptmenü">
            <?php foreach ($items as $item) :
                $url       = untrailingslashit((string) $item['url']);
                $is_active = ($url !== '' && $url === $current);
                $is_book   = (strpos($url, 'termin-buchen') !== false);
                $classes   = 'rfat-nav-link'
                    . ($is_active ? ' is-active' : '')
                    . ($is_book ? ' is-primary' : '');
                ?>
                <a class="<?php echo esc_attr($classes); ?>" href="<?php echo esc_url($item['url']); ?>"
                   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <script id="rfat-mobile-nav">
    (function () {
        var openBtn = document.getElementById('rfat-nav-open');
        var overlay = document.getElementById('rfat-nav-overlay');
        var closeBtn = document.getElementById('rfat-nav-close');
        if (!openBtn || !overlay || !closeBtn) { return; }

        /*
         * Bringt das Theme schon ein eigenes Handy-Menü mit? Dann unseres
         * restlos entfernen, damit nicht zwei Hamburger übereinanderliegen.
         */
        var themeToggle = document.querySelector('.wp-block-navigation__responsive-container-open');
        if (themeToggle) {
            document.documentElement.classList.add('rfat-nav-core');
            openBtn.parentNode.removeChild(openBtn);
            overlay.parentNode.removeChild(overlay);
            return;
        }
        document.documentElement.classList.add('rfat-nav-fallback');
        openBtn.hidden = false;

        var lastFocus = null;

        function openMenu() {
            lastFocus = document.activeElement;
            overlay.hidden = false;
            // Erzwingt einen Reflow, damit der Einblend-Übergang greift.
            void overlay.offsetWidth;
            overlay.classList.add('is-open');
            openBtn.setAttribute('aria-expanded', 'true');
            document.documentElement.classList.add('rfat-nav-locked');
            closeBtn.focus();
            document.addEventListener('keydown', onKeydown);
        }

        function closeMenu() {
            overlay.classList.remove('is-open');
            openBtn.setAttribute('aria-expanded', 'false');
            document.documentElement.classList.remove('rfat-nav-locked');
            document.removeEventListener('keydown', onKeydown);
            // Erst nach dem Ausblenden aus dem Fokusbaum nehmen.
            window.setTimeout(function () {
                if (!overlay.classList.contains('is-open')) { overlay.hidden = true; }
            }, 200);
            if (lastFocus && document.contains(lastFocus)) { lastFocus.focus(); }
        }

        function onKeydown(event) {
            if (event.key === 'Escape') {
                closeMenu();
                return;
            }
            if (event.key !== 'Tab') { return; }
            // Fokus im offenen Menü halten.
            var focusable = overlay.querySelectorAll('a[href], button:not([disabled])');
            if (!focusable.length) { return; }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        openBtn.addEventListener('click', openMenu);
        closeBtn.addEventListener('click', closeMenu);
        // Klick auf die Fläche neben den Links schließt ebenfalls.
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) { closeMenu(); }
        });
        // Beim Wechsel auf Desktop-Breite aufräumen.
        window.addEventListener('resize', function () {
            if (window.innerWidth > 600 && overlay.classList.contains('is-open')) { closeMenu(); }
        });
    })();
    </script>
    <?php
}, 1000);

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
 *  - der fertigen Plugin-Zip als Release-Asset
 *
 * Der Ordnername in der Zip darf inzwischen abweichen: upgrader_source_selection
 * unten biegt ihn vor der Installation auf den installierten Ordner zurück.
 * ========================================================================= */

define('RFAT_GH_REPO', 'Biospargel/repairffm-admin-tools');
define('RFAT_GH_CACHE_KEY', 'rfat_github_release');

/**
 * Der Plugin-Basename ("ordner/datei.php"), unter dem WordPress dieses
 * Plugin führt. Wird für slug und Ordner-Korrektur beim Update gebraucht.
 */
function rfat_plugin_basename() {
    return plugin_basename(__FILE__);
}

/**
 * Der Ordnername des Plugins — oder der Dateiname ohne Endung, falls das
 * Plugin als Einzeldatei direkt in /wp-content/plugins/ liegt (dann liefert
 * dirname() nämlich "." und der Slug wäre kaputt).
 */
function rfat_plugin_slug() {
    $dir = dirname(rfat_plugin_basename());
    if ($dir === '.' || $dir === '' || $dir === DIRECTORY_SEPARATOR) {
        return basename(__FILE__, '.php');
    }
    return $dir;
}

/**
 * Neuestes Release von GitHub holen (mit Cache, damit das API-Limit von
 * 60 Anfragen/Stunde nicht ausgereizt wird).
 *
 * @param bool $force Cache umgehen (z. B. bei "Erneut prüfen").
 * @return array{version:string,package:string,url:string,published:string}|null
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

    /*
     * Die hochgeladene Plugin-Zip suchen. Bevorzugt eine, deren Name zum
     * Plugin passt — sonst die erste Zip. GitHubs automatische
     * "Source code (zip)" taucht in assets[] gar nicht auf, die steckt in
     * zipball_url und wird hier bewusst nur als letzter Ausweg genommen.
     */
    $package  = '';
    $slug     = rfat_plugin_slug();
    $fallback = '';
    if (!empty($body['assets']) && is_array($body['assets'])) {
        foreach ($body['assets'] as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '' || substr($name, -4) !== '.zip') {
                continue;
            }
            if (stripos($name, $slug) !== false) {
                $package = $url;
                break;
            }
            if ($fallback === '') {
                $fallback = $url;
            }
        }
    }
    if ($package === '') {
        $package = $fallback;
    }
    if ($package === '') {
        set_site_transient(RFAT_GH_CACHE_KEY, 'none', HOUR_IN_SECONDS);
        return null;
    }

    $release = [
        'version'   => ltrim((string) $body['tag_name'], 'vV'),
        'package'   => $package,
        'url'       => !empty($body['html_url']) ? $body['html_url'] : 'https://github.com/' . RFAT_GH_REPO,
        'published' => (string) ($body['published_at'] ?? ''),
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

    /*
     * Vollständiger Satz Felder: die Update-Oberfläche und der Installer
     * lesen je nach Ansicht "version" ODER "new_version", und ohne "plugin"
     * bzw. "id" bleibt die Zeile auf der Plugin-Seite leer.
     */
    return [
        'id'           => 'github.com/' . RFAT_GH_REPO,
        'slug'         => rfat_plugin_slug(),
        'plugin'       => $plugin_file,
        'version'      => $release['version'],
        'new_version'  => $release['version'],
        'url'          => $release['url'],
        'package'      => $release['package'],
        'tested'       => get_bloginfo('version'),
        'requires_php' => '7.4',
    ];
}, 10, 3);

/**
 * Den entpackten Ordner auf den installierten Namen zurückbiegen.
 *
 * GitHub-Zips heißen je nach Bauart "repo-1.8.0" o. ä. Ohne diese Korrektur
 * würde WordPress das Plugin unter dem neuen Ordnernamen installieren — das
 * alte bliebe liegen und das Plugin wäre nach dem Update deaktiviert.
 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $args = []) {
    global $wp_filesystem;

    $plugin_file = $args['plugin'] ?? '';
    if ($plugin_file !== rfat_plugin_basename()) {
        return $source; // Anderes Plugin: nicht anfassen.
    }
    if (!$wp_filesystem) {
        return $source;
    }

    $desired = trailingslashit($remote_source) . rfat_plugin_slug();
    if (untrailingslashit($source) === $desired) {
        return $source; // Passt schon.
    }

    if ($wp_filesystem->move(untrailingslashit($source), $desired)) {
        return trailingslashit($desired);
    }

    return new WP_Error(
        'rfat_rename_failed',
        'Der entpackte Plugin-Ordner konnte nicht auf "' . rfat_plugin_slug() . '" umbenannt werden.'
    );
}, 10, 4);

// Bei "Erneut prüfen" im Dashboard den Cache verwerfen, damit sofort die
// echten GitHub-Daten geholt werden statt der bis zu 6 Stunden alten.
add_action('load-update-core.php', function () {
    if (isset($_GET['force-check'])) {
        delete_site_transient(RFAT_GH_CACHE_KEY);
    }
});
