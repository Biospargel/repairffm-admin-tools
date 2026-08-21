<?php
/**
 * Plugin Name: RepairFFM – Buchungen Übersicht & Selbstverwaltung
 * Description: (1) Admin-Übersicht der Termin-Buchungen (rc_booking) – ansehen, bearbeiten, Status setzen, löschen. (2) Shortcode [rfat_manage_booking] für Besucher: eigenen Termin per Code ansehen, stornieren oder verschieben – ohne Konto, E-Mail nur freiwillig. Rührt die Buchungslogik des Kern-Plugins selbst nicht an.
 * Version: 1.9.3
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
define('RFAT_NOTIFIED_META', '_rfat_notified');
define('RFAT_NOTIFY_OPTION', 'rfat_notify_to');
define('RFAT_NOTIFY_DEFAULT', 'repair.ffm@outlook.com');

// Internal WP core meta keys we never want to show/edit.
function rfat_meta_blocklist() {
    return [
        '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
        '_wp_page_template', '_thumbnail_id', '_wp_desired_post_slug',
        RFAT_STATUS_META, RFAT_EMAIL_META, RFAT_EMAIL_KEEP_META,
        RFAT_NOTIFIED_META,
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

/*
 * Termine gelten seit 1.9.0 nicht mehr automatisch als angenommen.
 *
 * Eine neue Buchung ist eine ANFRAGE; erst die Werkstatt bestätigt sie.
 * Der alte Wert 'offen' bleibt gültig, damit Buchungen aus der Zeit davor
 * nicht plötzlich als unbestätigt dastehen — sie waren es ja nie.
 */
function rfat_status_values() {
    return ['angefragt', 'bestaetigt', 'offen', 'erledigt', 'storniert'];
}

function rfat_get_status($post_id) {
    $status = get_post_meta($post_id, RFAT_STATUS_META, true);
    return $status ? $status : 'offen';
}

function rfat_status_label($status) {
    $labels = [
        'angefragt'  => 'Angefragt',
        'bestaetigt' => 'Bestätigt',
        'offen'      => 'Offen',
        'erledigt'   => 'Erledigt',
        'storniert'  => 'Storniert',
    ];
    return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
}

function rfat_status_color($status) {
    $colors = [
        'angefragt'  => '#c08a1e',
        'bestaetigt' => '#2f7d4f',
        'offen'      => '#2f7d4f',
        'erledigt'   => '#5b6b62',
        'storniert'  => '#b3402f',
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

    if (isset($_POST['rfat_action']) && $_POST['rfat_action'] === 'save_notify') {
        if (!current_user_can('manage_options')
            || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rfat_save_notify')) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }
        $raw   = sanitize_text_field(wp_unslash($_POST['rfat_notify_to'] ?? ''));
        $clean = [];
        foreach (explode(',', $raw) as $candidate) {
            $candidate = sanitize_email(trim($candidate));
            if ($candidate !== '' && is_email($candidate)) {
                $clean[] = $candidate;
            }
        }
        update_option(RFAT_NOTIFY_OPTION, implode(', ', array_unique($clean)));
        wp_safe_redirect(add_query_arg('rfat_notify_saved', '1', wp_get_referer() ?: admin_url()));
        exit;
    }

    if (isset($_POST['rfat_action']) && $_POST['rfat_action'] === 'check_update') {
        if (!current_user_can('manage_options')
            || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rfat_check_update')) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }
        // Cache leeren, damit wirklich bei GitHub nachgefragt wird.
        delete_site_transient(RFAT_GH_CACHE_KEY);
        rfat_fetch_latest_release(true);
        // WordPress soll seine Update-Liste ebenfalls neu aufbauen.
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_safe_redirect(add_query_arg('rfat_checked', '1', wp_get_referer() ?: admin_url()));
        exit;
    }

    if (isset($_POST['rfat_action']) && $_POST['rfat_action'] === 'test_notify') {
        if (!current_user_can('manage_options')
            || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rfat_test_notify')) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }
        $to = rfat_notify_recipients();
        if ($to) {
            rfat_send_logged(
                $to,
                sprintf('[%s] Testmail', get_bloginfo('name')),
                "Das ist eine Testmail aus der Buchungsübersicht.\n\n"
                . "Kommt sie an, funktioniert der Versand. Kommt trotzdem bei einer\n"
                . "echten Buchung nichts, liegt es nicht am Mailversand, sondern\n"
                . "daran, dass die Benachrichtigung nicht ausgelöst wird.\n\n"
                . "-- \n" . get_bloginfo('name')
            );
        } else {
            rfat_log_notify(false, [], 'Keine gültige Empfängeradresse hinterlegt.');
        }
        wp_safe_redirect(add_query_arg('rfat_tested', '1', wp_get_referer() ?: admin_url()));
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
        <?php if (isset($_GET['rfat_checked'])): ?>
            <div class="notice notice-info is-dismissible"><p>Bei GitHub nachgefragt — Ergebnis siehe unten.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['rfat_tested'])): ?>
            <div class="notice notice-info is-dismissible"><p>Testmail ausgelöst — Ergebnis siehe unten.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['rfat_notify_saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>Empfänger gespeichert.</p></div>
        <?php endif; ?>

        <?php
        $notify_now   = rfat_notify_recipients();
        $notify_value = get_option(RFAT_NOTIFY_OPTION, null);
        if ($notify_value === null || trim((string) $notify_value) === '') {
            $notify_value = RFAT_NOTIFY_DEFAULT;
        }
        ?>
        <div class="notice notice-info" style="padding:12px 14px;">
            <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0;">
                <?php wp_nonce_field('rfat_save_notify'); ?>
                <input type="hidden" name="rfat_action" value="save_notify" />
                <label for="rfat_notify_to" style="font-weight:600;">Benachrichtigung bei neuer Buchung an</label>
                <input type="text" id="rfat_notify_to" name="rfat_notify_to" class="regular-text"
                       value="<?php echo esc_attr($notify_value); ?>"
                       placeholder="<?php echo esc_attr(RFAT_NOTIFY_DEFAULT); ?>" />
                <button type="submit" class="button">Speichern</button>
                <span class="description" style="flex-basis:100%;margin-top:4px;">
                    <?php if ($notify_now): ?>
                        Mails gehen derzeit an <strong><?php echo esc_html(implode(', ', $notify_now)); ?></strong>.
                    <?php else: ?>
                        <strong style="color:#b3402f;">Derzeit geht keine Mail raus</strong> - keine gültige Adresse hinterlegt.
                    <?php endif; ?>
                    Mehrere Adressen durch Komma trennen.
                </span>
            </form>

            <?php
            $log = get_option('rfat_notify_log');
            ?>
            <form method="post" style="margin:10px 0 0;">
                <?php wp_nonce_field('rfat_test_notify'); ?>
                <input type="hidden" name="rfat_action" value="test_notify" />
                <button type="submit" class="button">Testmail senden</button>
                <span class="description" style="margin-left:8px;">
                    <?php if (!is_array($log) || empty($log['time'])): ?>
                        <strong>Bisher wurde nie eine Mail versucht.</strong>
                        Kam nach einer echten Buchung nichts an, wird die Benachrichtigung
                        gar nicht ausgelöst — dann liegt es nicht am Mailversand.
                    <?php else: ?>
                        Letzter Versuch:
                        <strong><?php echo esc_html(wp_date('d.m.Y H:i', $log['time'])); ?></strong>
                        an <?php echo esc_html($log['to'] !== '' ? $log['to'] : '(niemanden)'); ?> —
                        <?php if (!empty($log['ok'])): ?>
                            <span style="color:#2f7d4f;font-weight:600;">vom Server angenommen</span>.
                            Kommt trotzdem nichts an, wird sie unterwegs verworfen (Spam, SPF/DKIM).
                        <?php else: ?>
                            <span style="color:#b3402f;font-weight:600;">fehlgeschlagen</span>.
                            <?php if (!empty($log['error'])): ?>
                                <br /><code><?php echo esc_html($log['error']); ?></code>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </form>

            <?php
            $rlog    = get_option('rfat_release_log');
            $current = rfat_plugin_version();
            ?>
            <form method="post" style="margin:10px 0 0;">
                <?php wp_nonce_field('rfat_check_update'); ?>
                <input type="hidden" name="rfat_action" value="check_update" />
                <button type="submit" class="button">Bei GitHub nach Updates sehen</button>
                <span class="description" style="margin-left:8px;">
                    Installiert: <strong>v<?php echo esc_html($current); ?></strong>.
                    <?php if (!is_array($rlog) || empty($rlog['time'])): ?>
                        Es wurde noch nie bei GitHub nachgefragt.
                    <?php else: ?>
                        Letzte Abfrage <strong><?php echo esc_html(wp_date('d.m.Y H:i', $rlog['time'])); ?></strong>:
                        <?php if (!empty($rlog['ok'])): ?>
                            <span style="color:#2f7d4f;font-weight:600;"><?php echo esc_html($rlog['message']); ?></span>
                            Erscheint trotzdem kein Update, ist die installierte Version bereits die neueste.
                        <?php else: ?>
                            <span style="color:#b3402f;font-weight:600;"><?php echo esc_html($rlog['message']); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </form>
        </div>

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
                            <?php if (in_array($status, ['angefragt', 'offen'], true)): ?>
                                <a class="button button-primary" style="margin-bottom:4px;"
                                   href="<?php echo esc_url(rfat_action_url($p->ID, 'bestaetigen')); ?>">Zusagen</a>
                            <?php endif; ?>
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
                                        <?php foreach (rfat_status_values() as $s): ?>
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

    /*
     * Abmeldung über Link: /termin-abrufen/?abmelden=RC-AB12C
     *
     * Der Link darf nicht selbst schon löschen - Mailprogramme und
     * Virenscanner rufen Links in Nachrichten ungefragt auf, und die
     * Adresse wäre weg, bevor jemand sie bewusst entfernt hat. Deshalb
     * zeigt der Link nur eine Frage, gelöscht wird per Knopfdruck.
     */
    $unsub_code = '';
    if (!empty($_GET['abmelden'])) {
        $unsub_code = sanitize_text_field(wp_unslash($_GET['abmelden']));
    }

    if (!empty($_POST['rfat_pub_action']) && $_POST['rfat_pub_action'] === 'unsubscribe'
        && !empty($_POST['rfat_pub_code']) && !empty($_POST['_wpnonce'])) {
        $ucode = sanitize_text_field(wp_unslash($_POST['rfat_pub_code']));
        if (!wp_verify_nonce($_POST['_wpnonce'], 'rfat_pub_unsub_' . rfat_normalize_code($ucode))) {
            $notice = '<div class="rfat-pub-notice rfat-pub-error">Sicherheitsprüfung fehlgeschlagen, bitte erneut versuchen.</div>';
        } else {
            $um = rfat_find_booking_by_code($ucode);
            if ($um) {
                delete_post_meta($um['post']->ID, RFAT_EMAIL_META);
                delete_post_meta($um['post']->ID, RFAT_EMAIL_KEEP_META);
            }
            /*
             * Auch bei unbekanntem Code dieselbe Bestaetigung: Sonst
             * verrät die Seite, welche Codes existieren.
             */
            $notice = '<div class="rfat-pub-notice rfat-pub-success">Erledigt. Zu diesem Code ist keine E-Mail-Adresse mehr gespeichert. Dein Termin bleibt unverändert bestehen.</div>';
            $unsub_code = '';
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

        <?php if ($unsub_code !== ''): ?>
            <div class="rfat-pub-card">
                <p class="rfat-pub-eyebrow">E-Mail-Benachrichtigung</p>
                <p class="rfat-pub-when" style="font-size:18px;">Adresse zu <?php echo esc_html(rfat_normalize_code($unsub_code)); ?> löschen?</p>
                <p style="color:#5b6b62;font-size:14px;margin:10px 0 0;">
                    Wir entfernen dann die hinterlegte E-Mail-Adresse. Du bekommst
                    keine Nachrichten mehr. <strong>Dein Termin bleibt bestehen</strong> -
                    abgesagt wird dadurch nichts.
                </p>
                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field('rfat_pub_unsub_' . rfat_normalize_code($unsub_code)); ?>
                    <input type="hidden" name="rfat_pub_action" value="unsubscribe" />
                    <input type="hidden" name="rfat_pub_code" value="<?php echo esc_attr($unsub_code); ?>" />
                    <button type="submit" class="btn">Ja, Adresse löschen</button>
                </form>
                <p style="margin:14px 0 0;font-size:13px;">
                    <a href="<?php echo esc_url(add_query_arg('code', rfat_normalize_code($unsub_code), get_permalink())); ?>">Nein, zurück zu meinem Termin</a>
                </p>
            </div>

        <?php elseif (!$found): ?>
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

            <?php if (rfat_get_status($post->ID) === 'angefragt'): ?>
                <div class="rfat-pub-notice" style="background:#fbf2e2;color:#7a5510;">
                    <strong>Noch nicht bestätigt.</strong> Deine Anfrage ist bei uns eingegangen —
                    wir melden uns, sobald wir sie angesehen haben. Komm bitte erst vorbei,
                    wenn hier <em>Bestätigt</em> steht.
                </div>
            <?php endif; ?>

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

            <?php if ($analysis['datetime']): ?>
                <p style="margin:0 0 18px;">
                    <a class="btn ghost" href="<?php echo esc_url(add_query_arg('ics', $norm_code, get_permalink())); ?>">
                        &#128197; Zum Kalender hinzufügen
                    </a>
                </p>
            <?php endif; ?>

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

        /* Statusvermerk im Seitenfuß */
        .rfat-status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 14px 16px 20px;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #7d8b81;
            flex-wrap: wrap;
        }
        .rfat-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #2f7d4f;
            flex-shrink: 0;
            /* Ein schwacher Hof macht den Punkt auf kleinen Displays
               erkennbar, ohne dass er sich in den Vordergrund draengt. */
            box-shadow: 0 0 0 3px rgba(47, 125, 79, .16);
        }
        .rfat-status-dot.is-warn {
            background: #c08a1e;
            box-shadow: 0 0 0 3px rgba(192, 138, 30, .18);
        }
        .rfat-status-sep { opacity: .5; }
        .rfat-status-version { font-variant-numeric: tabular-nums; }

        /* Block, den wir nach der Buchung in den fremden Dialog hängen */
        .rfat-after-booking {
            margin: 18px 0;
            padding: 16px 18px;
            border: 1px solid #dbe6df;
            border-radius: 16px;
            background: #f4f8f5;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            text-align: left;
        }
        .rfat-ab-head {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 700;
            color: #1c2a22;
        }
        .rfat-ab-text {
            margin: 0 0 14px;
            font-size: 13px;
            line-height: 1.5;
            color: #5b6b62;
        }
        .rfat-ab-input {
            display: block;
            box-sizing: border-box;
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 10px;
            border: 1.5px solid #d7e0da;
            border-radius: 11px;
            background: #fff;
            color: #1c2a22;
            font-size: 16px; /* unter 16px zoomt iOS beim Antippen hinein */
            font-family: inherit;
        }
        .rfat-ab-input:focus {
            outline: none;
            border-color: #2f7d4f;
            box-shadow: 0 0 0 3px rgba(47, 125, 79, .15);
        }
        .rfat-ab-keep {
            display: flex;
            gap: 9px;
            align-items: flex-start;
            margin: 0 0 12px;
            font-size: 12px;
            line-height: 1.45;
            color: #5b6b62;
            cursor: pointer;
        }
        .rfat-ab-keep input { margin-top: 2px; flex-shrink: 0; width: 17px; height: 17px; }
        .rfat-ab-hint { margin: 0 0 10px; font-size: 13px; color: #b3402f; }
        .rfat-ab-btn[disabled] { opacity: .65; cursor: default; }

        .rfat-ab-row { display: flex; flex-direction: column; gap: 8px; }
        .rfat-ab-btn {
            display: block;
            box-sizing: border-box;
            width: 100%;
            padding: 12px 16px;
            border-radius: 11px;
            background: #2f7d4f;
            color: #fff !important;
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            text-decoration: none !important;
        }
        .rfat-ab-btn:hover,
        .rfat-ab-btn:focus-visible { background: #256a42; }
        .rfat-ab-ghost {
            background: transparent;
            color: #2f7d4f !important;
            border: 2px solid #2f7d4f;
        }
        .rfat-ab-ghost:hover,
        .rfat-ab-ghost:focus-visible {
            background: #e8f1eb;
            color: #1f5a38 !important;
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
    var rfatManageUrl = <?php echo wp_json_encode(home_url('/termin-abrufen/')); ?>;
    var rfatAjax = <?php echo wp_json_encode([
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('rfat_save_email'),
    ]); ?>;
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
        function rcVisible(el) {
            if (!el) { return false; }
            var cs = window.getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') {
                return false;
            }
            var r = el.getBoundingClientRect();
            return r.width > 0 && r.height > 0;
        }

        /*
         * Den offenen Buchungsdialog finden.
         *
         * Zuerst über die aus dem HTML rekonstruierte Klasse .rc-overlay.
         * Trifft die nicht — der Screenshot vom 21.08. legt nahe, dass sie
         * anders heißt —, wird nach der Bauform gesucht: ein sichtbares,
         * fest positioniertes Kind von <body>, das den Bildschirm ausfüllt.
         * So sieht ein Modal aus, egal wie seine Klasse heißt.
         *
         * Nur direkte Kinder von <body> werden geprüft; dort hängen Overlays
         * praktisch immer, und die Liste ist kurz genug für jeden Frame.
         */
        function rcOverlay() {
            var named = document.querySelector('.rc-overlay');
            if (rcVisible(named)) { return named; }

            var kids = document.body.children;
            for (var i = 0; i < kids.length; i++) {
                var el = kids[i];
                if (el.id && el.id.indexOf('rfat') === 0) { continue; }   // unsere eigenen
                if (el.tagName === 'SCRIPT' || el.tagName === 'STYLE') { continue; }
                if (!rcVisible(el)) { continue; }

                var cs = window.getComputedStyle(el);
                if (cs.position !== 'fixed' && cs.position !== 'absolute') { continue; }

                var r = el.getBoundingClientRect();
                if (r.width >= window.innerWidth * 0.75 && r.height >= window.innerHeight * 0.4) {
                    return el;
                }
            }
            return null;
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
         * Nach der Buchung: Weg zum eigenen Termin anbieten.
         *
         * Der Bestätigungsschritt gehört dem Kern-Plugin, das wir nicht
         * anfassen. Erkannt wird er deshalb nicht an Klassennamen, sondern am
         * Buchungscode selbst: Das Muster RC-XXXXX steht sichtbar im Dialog
         * und ändert sich nicht, egal wie das Plugin seine Elemente nennt.
         *
         * Eingehängt wird vor "Weiteren Termin buchen", sonst am Ende des
         * Dialogs. Angefasst wird nichts Bestehendes — nur ein Element
         * danebengesetzt, damit die fremde Logik unberührt bleibt.
         */
        function rcAfterBooking() {
            var existing = document.getElementById('rfat-after-booking');
            var ov = rcOverlay();

            if (!ov) {
                if (existing) { existing.parentNode.removeChild(existing); }
                return;
            }

            var match = (ov.textContent || '').match(/RC-[A-Z0-9]{4,}/i);
            if (!match) {
                if (existing) { existing.parentNode.removeChild(existing); }
                return;
            }

            var code = match[0].toUpperCase();
            if (existing) {
                if (existing.getAttribute('data-code') === code) { return; }
                existing.parentNode.removeChild(existing);
            }

            var box = document.createElement('div');
            box.id = 'rfat-after-booking';
            box.className = 'rfat-after-booking';
            box.setAttribute('data-code', code);

            var head = document.createElement('p');
            head.className = 'rfat-ab-head';
            head.textContent = 'Anfrage eingegangen';
            box.appendChild(head);

            var intro = document.createElement('p');
            intro.className = 'rfat-ab-text';
            /* Der Dialog des Kern-Plugins meldet "Termin gebucht!". Seit 1.9.0
             * ist das eine Anfrage, die noch bestätigt werden muss - hier wird
             * das richtiggestellt, solange wir an jenen Text nicht herankommen. */
            intro.textContent = 'Wir sehen uns deine Anfrage an und bestätigen sie. '
                + 'Magst du darüber benachrichtigt werden? Dann trag hier freiwillig '
                + 'eine E-Mail ein — nötig ist sie nicht, dein Code genügt.';
            box.appendChild(intro);

            /* Adresse gleich hier abfragen statt erst auf der naechsten
             * Seite: Wer den Bestaetigungsschritt wegklickt, kommt sonst
             * nie dazu. Freiwillig bleibt es trotzdem - der Weg darunter
             * fuehrt ohne Eingabe weiter. */
            var mail = document.createElement('input');
            mail.type = 'email';
            mail.className = 'rfat-ab-input';
            mail.placeholder = 'dein@beispiel.de (freiwillig)';
            mail.setAttribute('aria-label', 'E-Mail-Adresse, freiwillig');
            box.appendChild(mail);

            var keepWrap = document.createElement('label');
            keepWrap.className = 'rfat-ab-keep';
            var keep = document.createElement('input');
            keep.type = 'checkbox';
            keepWrap.appendChild(keep);
            var keepText = document.createElement('span');
            keepText.textContent = 'Adresse darf auch nach dem Termin gespeichert bleiben. '
                + 'Ohne Haken wird sie danach automatisch gelöscht.';
            keepWrap.appendChild(keepText);
            box.appendChild(keepWrap);

            var hint = document.createElement('p');
            hint.className = 'rfat-ab-hint';
            box.appendChild(hint);

            var row = document.createElement('div');
            row.className = 'rfat-ab-row';

            var save = document.createElement('button');
            save.type = 'button';
            save.className = 'rfat-ab-btn';
            save.textContent = 'E-Mail speichern und weiter';
            save.addEventListener('click', function () {
                if (!mail.value) {
                    hint.textContent = 'Bitte eine Adresse eingeben — oder unten ohne fortfahren.';
                    mail.focus();
                    return;
                }
                save.disabled = true;
                save.textContent = 'Wird gespeichert …';
                hint.textContent = '';

                var body = 'action=rfat_save_email'
                    + '&nonce=' + encodeURIComponent(rfatAjax.nonce)
                    + '&code=' + encodeURIComponent(code)
                    + '&email=' + encodeURIComponent(mail.value)
                    + (keep.checked ? '&keep=1' : '');

                fetch(rfatAjax.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    credentials: 'same-origin',
                    body: body
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success && res.data && res.data.redirect) {
                        window.location.href = res.data.redirect;
                        return;
                    }
                    save.disabled = false;
                    save.textContent = 'E-Mail speichern und weiter';
                    hint.textContent = (res && res.data && res.data.message)
                        ? res.data.message
                        : 'Das hat nicht geklappt. Du kannst die Adresse auch auf der nächsten Seite eintragen.';
                }).catch(function () {
                    save.disabled = false;
                    save.textContent = 'E-Mail speichern und weiter';
                    hint.textContent = 'Keine Verbindung. Du kannst die Adresse auch auf der nächsten Seite eintragen.';
                });
            });
            row.appendChild(save);

            var manage = document.createElement('a');
            manage.className = 'rfat-ab-btn rfat-ab-ghost';
            manage.href = rfatManageUrl + '?code=' + encodeURIComponent(code);
            manage.textContent = 'Ohne E-Mail weiter zum Termin';
            row.appendChild(manage);

            var cal = document.createElement('a');
            cal.className = 'rfat-ab-btn rfat-ab-ghost';
            cal.href = rfatManageUrl + '?ics=' + encodeURIComponent(code);
            cal.textContent = 'Zum Kalender hinzufügen';
            row.appendChild(cal);

            box.appendChild(row);

            var again = ov.querySelector('#rc-again');
            if (again && again.parentNode) {
                again.parentNode.insertBefore(box, again);
            } else {
                (ov.firstElementChild || ov).appendChild(box);
            }
        }

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
 * BESTÄTIGEN PER LINK AUS DER MAIL
 *
 * Der Link muss ohne Anmeldung funktionieren — sonst wäre er auf dem Handy
 * nutzlos. Statt einer Anmeldung trägt er eine Signatur aus Post-ID,
 * Aktion und den WordPress-Salts. Ohne Kenntnis der Salts lässt sie sich
 * nicht erzeugen, und sie gilt nur für genau diese eine Buchung.
 *
 * Geklickt wird nicht sofort ausgeführt: Mailprogramme und Virenscanner
 * rufen Links in Nachrichten ungefragt auf. Der Link zeigt die Buchung und
 * fragt nach; bestätigt wird per Knopfdruck.
 * ========================================================================= */

/**
 * Signatur für einen Bestätigungslink.
 */
function rfat_action_token($post_id, $action) {
    return substr(wp_hash($action . '|' . $post_id . '|' . get_post_time('U', true, $post_id), 'nonce'), 0, 20);
}

function rfat_action_url($post_id, $action) {
    return add_query_arg([
        'rfat_do'    => $action,
        'rfat_id'    => $post_id,
        'rfat_token' => rfat_action_token($post_id, $action),
    ], home_url('/'));
}

add_action('template_redirect', function () {
    if (empty($_GET['rfat_do']) || empty($_GET['rfat_id']) || empty($_GET['rfat_token'])) {
        return;
    }
    $action  = sanitize_key(wp_unslash($_GET['rfat_do']));
    $post_id = (int) $_GET['rfat_id'];
    $token   = sanitize_text_field(wp_unslash($_GET['rfat_token']));

    if (!in_array($action, ['bestaetigen', 'absagen'], true)) {
        return;
    }
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'rc_booking') {
        wp_die('Diese Buchung gibt es nicht mehr.', 'Nicht gefunden', ['response' => 404]);
    }
    if (!hash_equals(rfat_action_token($post_id, $action), $token)) {
        wp_die('Dieser Link ist nicht gültig.', 'Ungültiger Link', ['response' => 403]);
    }

    $analysis = rfat_analyse_booking($post_id);
    $ts       = $analysis['datetime'] ? $analysis['datetime']->getTimestamp() : null;
    $code     = $analysis['code']['value'] ?? '';
    $done     = false;

    if (!empty($_POST['rfat_confirm_go'])
        && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rfat_do_' . $action . '_' . $post_id)) {
        if ($action === 'bestaetigen') {
            update_post_meta($post_id, RFAT_STATUS_META, 'bestaetigt');
            rfat_notify_customer($post_id, 'bestaetigt');
        } else {
            update_post_meta($post_id, RFAT_STATUS_META, 'storniert');
            rfat_notify_customer($post_id, 'abgesagt');
            wp_trash_post($post_id);
        }
        $done = true;
    }

    $titel  = $action === 'bestaetigen' ? 'Termin bestätigen' : 'Termin absagen';
    $status = rfat_get_status($post_id);

    $html  = '<p><strong>' . esc_html($ts ? wp_date('l, d.m.Y', $ts) . ' um ' . wp_date('H:i', $ts) . ' Uhr' : 'Termin unbekannt') . '</strong><br />';
    $html .= 'Code: ' . esc_html($code) . '</p>';

    if ($done) {
        $html .= '<p>Erledigt. Der Termin steht jetzt auf <strong>'
            . esc_html(rfat_status_label($action === 'bestaetigen' ? 'bestaetigt' : 'storniert'))
            . '</strong>.</p>';
        $html .= '<p><a href="' . esc_url(admin_url('edit.php?post_type=rc_booking&page=rfat-overview')) . '">Zur Übersicht</a></p>';
    } elseif (in_array($status, ['bestaetigt', 'storniert'], true)) {
        $html .= '<p>Dieser Termin steht bereits auf <strong>' . esc_html(rfat_status_label($status)) . '</strong>. Es ist nichts zu tun.</p>';
    } else {
        $html .= '<form method="post">' . wp_nonce_field('rfat_do_' . $action . '_' . $post_id, '_wpnonce', true, false)
            . '<input type="hidden" name="rfat_confirm_go" value="1" />'
            . '<p><button type="submit" style="font-size:16px;padding:12px 22px;border:0;border-radius:10px;background:'
            . ($action === 'bestaetigen' ? '#2f7d4f' : '#b3402f')
            . ';color:#fff;font-weight:700;cursor:pointer;">'
            . esc_html($titel) . '</button></p></form>';
    }

    wp_die($html, $titel, ['response' => 200, 'back_link' => false]);
});

/**
 * Den Gast informieren — nur wenn er freiwillig eine Adresse hinterlegt hat.
 */
function rfat_notify_customer($post_id, $was) {
    $to = (string) get_post_meta($post_id, RFAT_EMAIL_META, true);
    if ($to === '' || !is_email($to)) {
        return;
    }
    $analysis = rfat_analyse_booking($post_id);
    $ts       = $analysis['datetime'] ? $analysis['datetime']->getTimestamp() : null;
    $code     = rfat_normalize_code($analysis['code']['value'] ?? '');
    $when     = $ts ? wp_date('l, d.m.Y', $ts) . ' um ' . wp_date('H:i', $ts) . ' Uhr' : 'Termin unbekannt';
    $manage   = home_url('/termin-abrufen/?code=' . rawurlencode($code));

    if ($was === 'bestaetigt') {
        $subject = 'Dein Reparaturtermin ist bestätigt';
        $body    = "Guten Tag,\n\ndein Termin steht:\n\n" . $when . "\nCode: " . $code
            . "\n\nTermin ansehen, verschieben oder absagen:\n" . $manage;
    } else {
        $subject = 'Dein Reparaturtermin konnte nicht stattfinden';
        $body    = "Guten Tag,\n\nleider können wir deinen Termin nicht wahrnehmen:\n\n"
            . $when . "\nCode: " . $code
            . "\n\nDu kannst jederzeit einen neuen Termin buchen:\n" . home_url('/termin-buchen/');
    }

    $body .= "\n\nKeine Nachrichten mehr? Hier abmelden:\n"
        . home_url('/termin-abrufen/?abmelden=' . rawurlencode($code))
        . "\n\n-- \n" . get_bloginfo('name');

    wp_mail($to, $subject, $body);
}

/* =========================================================================
 * STATUSVERMERK IM SEITENFUSS
 *
 * Ein Punkt, der immer grün leuchtet, ist Dekoration und irgendwann eine
 * Lüge. Dieser prüft drei Dinge, die tatsächlich ausfallen können:
 *
 *   1. Ist die Buchung überhaupt da? (rc_booking des Kern-Plugins)
 *   2. Läuft der tägliche Auftrag, der E-Mail-Adressen löscht?
 *   3. Würde eine Benachrichtigung jemanden erreichen?
 *
 * Fällt eines aus, wird der Punkt gelb. Punkt 2 hat rechtliches Gewicht —
 * ohne den Auftrag bleiben Adressen liegen, die längst gelöscht sein
 * müssten; das darf nicht unbemerkt passieren.
 *
 * Ergebnis fünf Minuten im Transient, damit der Fuß keine Last erzeugt.
 * ========================================================================= */

/**
 * Zustand der Dienste ermitteln.
 *
 * @return array{ok:bool,checks:array<string,bool>}
 */
function rfat_service_status() {
    $cached = get_transient('rfat_status');
    if (is_array($cached)) {
        return $cached;
    }

    $checks = [
        'Buchungssystem'  => post_type_exists('rc_booking'),
        'Datenlöschung'  => (bool) wp_next_scheduled(RFAT_CLEANUP_HOOK),
        'Benachrichtigung' => (bool) rfat_notify_recipients(),
    ];

    $status = [
        'ok'     => !in_array(false, $checks, true),
        'checks' => $checks,
    ];

    set_transient('rfat_status', $status, 5 * MINUTE_IN_SECONDS);

    return $status;
}

/**
 * Version aus dem eigenen Plugin-Kopf lesen.
 */
function rfat_plugin_version() {
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $data = get_plugin_data(__FILE__, false, false);
    return $data['Version'] ?? '';
}

// Nach einer Änderung an den Empfängern soll der Fuß sofort stimmen.
add_action('update_option_' . RFAT_NOTIFY_OPTION, function () {
    delete_transient('rfat_status');
});

add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    $status  = rfat_service_status();
    $version = rfat_plugin_version();

    $failed = array_keys(array_filter($status['checks'], function ($v) { return !$v; }));
    $label  = $status['ok'] ? 'Alle Dienste laufen' : 'Eingeschränkt';

    // Nur Angemeldete sehen, WAS klemmt — Besucher geht das nichts an.
    $detail = '';
    if (!$status['ok'] && current_user_can('manage_options')) {
        $detail = ' (' . implode(', ', $failed) . ')';
    }
    ?>
    <div class="rfat-status" role="status">
        <span class="rfat-status-dot<?php echo $status['ok'] ? '' : ' is-warn'; ?>" aria-hidden="true"></span>
        <span><?php echo esc_html($label . $detail); ?></span>
        <?php if ($version !== ''): ?>
            <span class="rfat-status-sep" aria-hidden="true">&middot;</span>
            <span class="rfat-status-version">v<?php echo esc_html($version); ?></span>
        <?php endif; ?>
    </div>
    <?php
}, 1001);

/* =========================================================================
 * E-MAIL DIREKT IM BESTÄTIGUNGSSCHRITT
 *
 * Das Buchungsformular gehört dem Kern-Plugin. Statt es anzufassen,
 * nimmt dieser Endpunkt die Adresse entgegen, die unser eingehängter
 * Block abfragt. Der fremde Ablauf bleibt dadurch unberührt.
 *
 * Der Buchungscode ist der Ausweis — wie überall sonst hier auch. Wer ihn
 * hat, hat gerade gebucht.
 * ========================================================================= */
add_action('wp_ajax_rfat_save_email', 'rfat_ajax_save_email');
add_action('wp_ajax_nopriv_rfat_save_email', 'rfat_ajax_save_email');

function rfat_ajax_save_email() {
    check_ajax_referer('rfat_save_email', 'nonce');

    $code  = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $keep  = !empty($_POST['keep']);

    if ($code === '' || !is_email($email)) {
        wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);
    }

    $match = rfat_find_booking_by_code($code);
    if (!$match) {
        wp_send_json_error(['message' => 'Zu diesem Code wurde keine Buchung gefunden.']);
    }

    $post_id = $match['post']->ID;
    update_post_meta($post_id, RFAT_EMAIL_META, $email);
    if ($keep) {
        update_post_meta($post_id, RFAT_EMAIL_KEEP_META, '1');
    } else {
        delete_post_meta($post_id, RFAT_EMAIL_KEEP_META);
    }

    rfat_notify_email_added($post_id, $email);

    wp_send_json_success([
        'redirect' => home_url('/termin-abrufen/?code=' . rawurlencode(rfat_normalize_code($code))),
    ]);
}

/**
 * Nachtrag an die Werkstatt, wenn kurz nach der Anfrage eine Adresse
 * hinterlegt wird.
 *
 * Die Anfrage-Mail geht sofort raus, die Adresse tippt der Gast erst
 * Sekunden später - sie kann in jener Mail also nicht stehen. Den Versand
 * so lange aufzuschieben wäre die elegantere Lösung, hängt aber an
 * WordPress' Cron, der auf einer ruhigen Seite stundenlang schlafen kann.
 * Eine verspätete Anfrage-Mail wäre schlimmer als eine zweite kurze.
 *
 * Deshalb nur ein Nachtrag, und nur wenn die Anfrage frisch ist — wer
 * seine Adresse Tage später über die Abruf-Seite nachträgt, löst keine
 * Mail aus.
 */
function rfat_notify_email_added($post_id, $email) {
    $created = get_post_time('U', true, $post_id);
    if (!$created || (time() - $created) > 30 * MINUTE_IN_SECONDS) {
        return;
    }
    $to = rfat_notify_recipients();
    if (!$to) {
        return;
    }
    $body = "Zu einer gerade eingegangenen Anfrage wurde eine E-Mail-Adresse hinterlegt.\n\n"
          . rfat_booking_summary($post_id) . "\n"
          . "E-Mail:  " . $email . "\n\n"
          . "Zusagen:\n" . rfat_action_url($post_id, 'bestaetigen') . "\n\n"
          . "Absagen:\n" . rfat_action_url($post_id, 'absagen') . "\n\n"
          . "-- \nAutomatische Nachricht von " . get_bloginfo('name');

    rfat_send_logged($to, sprintf('[%s] Nachtrag: E-Mail zur Anfrage', get_bloginfo('name')), $body);
}

/* =========================================================================
 * KALENDEREINTRAG (.ics)
 *
 * Ausgeliefert als echter Download unter ?ics=RC-XXXXX statt als data:-Link:
 * iOS öffnet data:-URLs nicht im Kalender, eine ausgelieferte Datei mit
 * text/calendar dagegen schon.
 *
 * Die Datei enthält bewusst nur Termin und Bereich - keine Adresse, keinen
 * Namen. Was im Kalender landet, wandert oft in fremde Clouds.
 * ========================================================================= */

/**
 * Zeilen nach RFC 5545 falten (max. 75 Oktette) und Sonderzeichen schützen.
 */
function rfat_ics_line($name, $value) {
    $value = str_replace(["\\", "\n", ";", ","], ["\\\\", "\\n", "\\;", "\\,"], $value);
    $line  = $name . ':' . $value;

    $out = '';
    while (strlen($line) > 73) {
        $out .= substr($line, 0, 73) . "\r\n ";
        $line = substr($line, 73);
    }
    return $out . $line . "\r\n";
}

add_action('template_redirect', function () {
    if (empty($_GET['ics'])) {
        return;
    }
    $code  = sanitize_text_field(wp_unslash($_GET['ics']));
    $match = rfat_find_booking_by_code($code);
    if (!$match) {
        return; // Kein Treffer: Seite lädt normal weiter.
    }

    $analysis = $match['analysis'];
    $dt       = $analysis['datetime'];
    if (!$dt) {
        return;
    }

    $start = $dt->getTimestamp();
    $end   = $start + HOUR_IN_SECONDS;
    $norm  = rfat_normalize_code($analysis['code']['value']);

    $bereich = '';
    foreach ($analysis['other'] as $field) {
        if (stripos($field['key'], 'cat') !== false) {
            $bereich = rfat_friendly_category($field['value']);
            break;
        }
    }

    $title = $bereich !== ''
        ? 'Reparaturtermin - ' . $bereich
        : 'Reparaturtermin';

    $ics  = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//Repair FfM//Terminbuchung//DE\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= rfat_ics_line('UID', $norm . '@' . wp_parse_url(home_url(), PHP_URL_HOST));
    $ics .= rfat_ics_line('DTSTAMP', gmdate('Ymd\THis\Z'));
    $ics .= rfat_ics_line('DTSTART', gmdate('Ymd\THis\Z', $start));
    $ics .= rfat_ics_line('DTEND', gmdate('Ymd\THis\Z', $end));
    $ics .= rfat_ics_line('SUMMARY', $title);
    $ics .= rfat_ics_line('DESCRIPTION', 'Buchungscode ' . $norm . '. Termin ansehen, verschieben oder absagen: ' . home_url('/termin-abrufen/?code=' . rawurlencode($norm)));
    $ics .= rfat_ics_line('URL', home_url('/termin-abrufen/?code=' . rawurlencode($norm)));
    $ics .= "BEGIN:VALARM\r\n";
    $ics .= "TRIGGER:-PT2H\r\n";
    $ics .= "ACTION:DISPLAY\r\n";
    $ics .= rfat_ics_line('DESCRIPTION', 'Erinnerung: ' . $title);
    $ics .= "END:VALARM\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="reparaturtermin-' . $norm . '.ics"');
    header('Content-Length: ' . strlen($ics));
    echo $ics;
    exit;
});

/* =========================================================================
 * BENACHRICHTIGUNG BEI NEUER BUCHUNG
 *
 * Weil zu einer Buchung keine Kontaktdaten gehören, kann niemand von sich
 * aus nachfragen - die Werkstatt erfährt von einem Termin nur, wenn sie in
 * die Übersicht schaut. Diese Mail schließt die Lücke.
 *
 * Sie geht an die Administrations-Adresse der Seite. Eine andere lässt sich
 * über den Filter 'rfat_notify_recipient' setzen.
 * ========================================================================= */

/**
 * Kurzfassung einer Buchung für die Benachrichtigung.
 *
 * @param int $post_id
 * @return string
 */
function rfat_booking_summary($post_id) {
    $analysis = rfat_analyse_booking($post_id);
    $ts       = $analysis['datetime'] ? $analysis['datetime']->getTimestamp() : null;

    $lines = [];
    $lines[] = 'Termin:  ' . ($ts ? wp_date('l, d.m.Y', $ts) . ' um ' . wp_date('H:i', $ts) . ' Uhr' : 'unbekannt');

    foreach ($analysis['other'] as $field) {
        if (stripos($field['key'], 'cat') !== false) {
            $lines[] = 'Bereich: ' . rfat_friendly_category($field['value']);
            break;
        }
    }

    $lines[] = 'Code:    ' . ($analysis['code']['value'] ?? '-');

    return implode("\n", $lines);
}

/**
 * Empfänger der Benachrichtigung.
 *
 * Bewusst eine Einstellung und keine feste Adresse im Code: Wer die Mails
 * bekommt, ändert sich mit den Leuten im Verein, nicht mit dem Plugin.
 * Mehrere Adressen durch Komma trennen.
 *
 * @return string[] Geprüfte Adressen, möglicherweise leer.
 */
function rfat_notify_recipients() {
    $raw = get_option(RFAT_NOTIFY_OPTION, null);
    if ($raw === null || trim((string) $raw) === '') {
        $raw = RFAT_NOTIFY_DEFAULT;
    }

    $list = [];
    foreach (explode(',', (string) $raw) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && is_email($candidate)) {
            $list[] = $candidate;
        }
    }

    /**
     * Letzte Gelegenheit, die Empfänger zu verändern.
     *
     * @param string[] $list
     */
    $list = apply_filters('rfat_notify_recipient', $list);

    return array_values(array_unique(array_filter((array) $list, 'is_email')));
}

/**
 * Mail verschicken, sobald eine Buchung angelegt wurde.
 *
 * Zwei Auslöser statt einem. wp_after_insert_post ist der richtige Ort —
 * dort sind die Meta-Felder geschrieben. Ob das Kern-Plugin die Buchung
 * aber so anlegt, dass dieser Hook überhaupt feuert, wissen wir nicht;
 * seinen Quellcode haben wir nie gesehen. Deshalb zusätzlich save_post,
 * ausgeführt erst beim shutdown, damit die Meta-Felder auch dann stehen.
 *
 * Doppelte Mails sind ausgeschlossen: Der erste Durchlauf setzt einen
 * Vermerk am Post, der zweite bricht daran ab.
 */
function rfat_maybe_notify($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'rc_booking') {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!in_array($post->post_status, ['publish', 'draft', 'pending', 'private'], true)) {
        return;
    }
    if (get_post_meta($post_id, RFAT_NOTIFIED_META, true)) {
        return;
    }
    update_post_meta($post_id, RFAT_NOTIFIED_META, '1');

    // Eine neue Buchung ist eine Anfrage, keine Zusage.
    if (!get_post_meta($post_id, RFAT_STATUS_META, true)) {
        update_post_meta($post_id, RFAT_STATUS_META, 'angefragt');
    }

    $to = rfat_notify_recipients();
    if (!$to) {
        rfat_log_notify(false, [], 'Keine gültige Empfängeradresse hinterlegt.');
        return;
    }

    $overview = admin_url('edit.php?post_type=rc_booking&page=rfat-overview');
    $body = "Es wurde ein Termin ANGEFRAGT und wartet auf deine Zusage.\n\n"
          . rfat_booking_summary($post_id) . "\n\n"
          . "Zusagen:\n" . rfat_action_url($post_id, 'bestaetigen') . "\n\n"
          . "Absagen:\n" . rfat_action_url($post_id, 'absagen') . "\n\n"
          . "Übersicht öffnen:\n" . $overview . "\n\n"
          . "-- \nAutomatische Nachricht von " . get_bloginfo('name');

    rfat_send_logged($to, sprintf('[%s] Neue Terminanfrage', get_bloginfo('name')), $body);
}

add_action('wp_after_insert_post', function ($post_id, $post, $update) {
    if ($update) {
        return;
    }
    rfat_maybe_notify($post_id);
}, 20, 3);

/*
 * Rückfallweg: Feuert wp_after_insert_post nicht, greift save_post. Der
 * eigentliche Versand wartet bis zum shutdown, weil Meta-Felder oft erst
 * nach save_post geschrieben werden - sonst stünde in der Mail ein
 * leeres Gerüst ohne Termin und Code.
 */
add_action('save_post_rc_booking', function ($post_id) {
    if (get_post_meta($post_id, RFAT_NOTIFIED_META, true)) {
        return;
    }
    add_action('shutdown', function () use ($post_id) {
        rfat_maybe_notify($post_id);
    });
}, 9999);

/**
 * wp_mail aufrufen und das Ergebnis festhalten.
 *
 * Ohne Protokoll lässt sich "es kommt keine Mail an" nicht auflösen:
 * Man sieht nicht, ob der Auslöser nie lief oder der Server nicht
 * zustellt. Das sind völlig verschiedene Probleme.
 */
function rfat_send_logged($to, $subject, $body) {
    $error = '';
    $catch = function ($wp_error) use (&$error) {
        $error = $wp_error->get_error_message();
    };
    add_action('wp_mail_failed', $catch);

    $ok = wp_mail($to, $subject, $body);

    remove_action('wp_mail_failed', $catch);
    rfat_log_notify($ok, (array) $to, $error);

    return $ok;
}

/**
 * Letzten Versandversuch festhalten.
 */
function rfat_log_notify($ok, $to, $error = '') {
    update_option('rfat_notify_log', [
        'time'  => time(),
        'ok'    => (bool) $ok,
        'to'    => implode(', ', $to),
        'error' => (string) $error,
    ], false);
}

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

    if (is_wp_error($response)) {
        return rfat_release_failed($force, 'Netzwerkfehler: ' . $response->get_error_message());
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        /*
         * GitHub erlaubt ohne Anmeldung 60 Anfragen pro Stunde und ZAEHLT
         * PRO IP. Auf gemeinsam genutztem Hosting teilen sich viele Seiten
         * dieselbe Adresse, das Kontingent ist dann schnell leer - ohne
         * dass an dieser Seite irgendetwas falsch waere.
         */
        $remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
        if ($code === 403 && $remaining !== '' && (int) $remaining === 0) {
            $reset = (int) wp_remote_retrieve_header($response, 'x-ratelimit-reset');
            return rfat_release_failed($force, 'GitHub-Anfragelimit erreicht'
                . ($reset ? ' (frei ab ' . wp_date('H:i', $reset) . ' Uhr)' : '')
                . '. Das Limit gilt pro Server-IP, nicht pro Seite.');
        }
        return rfat_release_failed($force, 'GitHub antwortete mit HTTP ' . $code . '.');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['tag_name'])) {
        return rfat_release_failed($force, 'Antwort von GitHub ohne verwertbares Release.');
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
        return rfat_release_failed($force, 'Release ' . $body['tag_name']
            . ' hat keine hochgeladene .zip als Anhang.');
    }

    $release = [
        'version'   => ltrim((string) $body['tag_name'], 'vV'),
        'package'   => $package,
        'url'       => !empty($body['html_url']) ? $body['html_url'] : 'https://github.com/' . RFAT_GH_REPO,
        'published' => (string) ($body['published_at'] ?? ''),
    ];

    set_site_transient(RFAT_GH_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);
    rfat_log_release(true, 'Release ' . $body['tag_name'] . ' gefunden.');

    return $release;
}

/**
 * Fehlgeschlagenen Abruf festhalten und kurz sperren.
 *
 * Die Sperre war eine volle Stunde lang - eine einzelne Stoerung legte den
 * Updater damit fuer eine Stunde lahm. Fuenfzehn Minuten schonen das
 * Anfragelimit genauso, machen aber aus einem Aussetzer keine Sperre.
 * Bei einer ausdruecklichen Pruefung wird gar nicht gesperrt.
 */
function rfat_release_failed($force, $message) {
    if (!$force) {
        set_site_transient(RFAT_GH_CACHE_KEY, 'none', 15 * MINUTE_IN_SECONDS);
    }
    rfat_log_release(false, $message);
    return null;
}

/**
 * Letzten Abruf festhalten, damit "es kommt kein Update" eine Ursache hat.
 */
function rfat_log_release($ok, $message) {
    update_option('rfat_release_log', [
        'time'    => time(),
        'ok'      => (bool) $ok,
        'message' => (string) $message,
    ], false);
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
