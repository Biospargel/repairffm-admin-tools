<?php
/**
 * Plugin Name: RepairFFM – Buchungen Übersicht & Selbstverwaltung
 * Description: (1) Admin-Übersicht der Termin-Buchungen (rc_booking) mit einstellbaren Kategorien und Uhrzeiten – ansehen, bearbeiten, Status setzen, löschen. (2) Shortcode [rfat_manage_booking] für Besucher: eigenen Termin per Code ansehen, stornieren oder verschieben – ohne Konto, Kontakt (E-Mail, Signal-Benutzername oder Signal-Link) nur freiwillig. Rückfragen an den Gast, Antworten und eigene Notizen auf der Terminseite. Übersicht mit Zusagen/Ablehnen, Signal-Knopf und fertigem Nachrichtentext. (3) Sperre gegen Mehrfachbuchungen: Ein Anschluss kann nur eine begrenzte Zahl offener Termine halten – ohne die IP-Adresse zu speichern.
 * Version: 1.24.0
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
/*
 * Freiwilliger Signal-Benutzername (seit 1.19.0).
 *
 * Warum der Benutzername und nicht die Telefonnummer: Genau dafuer hat
 * Signal ihn eingeführt. Wer ihn hier einträgt, ist erreichbar, ohne
 * seine Nummer herauszugeben — für eine Seite, die mit Datensparsamkeit
 * wirbt, ist das der einzige vertretbare Weg zu einem schnellen Draht.
 *
 * Das Häkchen zur weiteren Speicherung ist dasselbe wie bei der E-Mail:
 * Es ist eine Entscheidung ueber die Kontaktdaten, nicht ueber den Kanal.
 */
define('RFAT_SIGNAL_META', '_rfat_signal');

/*
 * Rückfragen und Antworten zu einer Buchung (seit 1.22.0).
 *
 * Eine Liste von Einträgen ['von' => 'team'|'gast', 'text' => ..., 'zeit' => ...].
 * Warum eine Liste und nicht ein Feld „Frage" und eines „Antwort": Auf eine
 * Antwort folgt oft die nächste Frage. Zwei Felder müssten sich dann
 * gegenseitig überschreiben, und der Verlauf wäre weg — gerade der macht
 * aber vor dem Termin aus, dass beide Seiten wissen, was besprochen ist.
 */
define('RFAT_DIALOG_META', '_rfat_dialog');
define('RFAT_DIALOG_MAX', 20);
define('RFAT_CLEANUP_HOOK', 'rfat_cleanup_emails');
define('RFAT_NOTIFIED_META', '_rfat_notified');
define('RFAT_NOTIFY_OPTION', 'rfat_notify_to');
define('RFAT_NOTIFY_DEFAULT', 'repair.ffm@outlook.com');

// Internal WP core meta keys we never want to show/edit.
/**
 * CSS beim Ausliefern zusammenstreichen — nicht in der Quelldatei.
 *
 * Eine fremde Fassung (1.15.0) hatte das CSS direkt in der Datei
 * minifiziert: aus 593 Zeilen mit 23 erklaerenden Kommentaren wurden drei
 * Zeilen, eine davon 6768 Zeichen lang. Beim Besucher kommt so zwar
 * dasselbe an, aber die Begruendungen waren weg — und genau die haben in
 * diesem Plugin mehrfach verhindert, dass ein Fehler ein zweites Mal
 * gemacht wird (Versatz fuer die Adminleiste, der weggelassene Balken,
 * pointer-events auf der Leiste).
 *
 * Deshalb hier: Quelle bleibt lesbar, gestrichen wird erst beim Senden.
 * Beim Besucher kommt Zeichen fuer Zeichen dasselbe an.
 */
function rfat_minify_style_tags($html) {
    $muster = '#(' . '<style' . '[^>]*>)(.*?)(' . '</style' . '>)#s';
    return preg_replace_callback($muster, function ($m) {
        $css = preg_replace('#/\*.*?\*/#s', '', $m[2]);      // Kommentare
        $css = preg_replace('/\s+/', ' ', $css);              // Umbrueche
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
        $css = str_replace(';}', '}', $css);                  // letztes Semikolon
        return $m[1] . trim($css) . $m[3];
    }, $html);
}

function rfat_meta_blocklist() {
    return [
        '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date',
        '_wp_page_template', '_thumbnail_id', '_wp_desired_post_slug',
        RFAT_STATUS_META, RFAT_EMAIL_META, RFAT_EMAIL_KEEP_META,
        RFAT_SIGNAL_META,
        RFAT_DIALOG_META,
        RFAT_NOTIFIED_META,
        /*
         * Der Prüfwert der Buchungssperre. Muss hier stehen: Die Analyse
         * zeigt jedes unbekannte Feld an - auch auf der öffentlichen Seite
         * „Termin abrufen". Ein 32-stelliger Prüfwert unter „Dein Termin"
         * wäre bestenfalls verwirrend.
         */
        RFAT_GERAET_META,
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

    /*
     * Seit 21.08.2026 ist der Quellcode des Buchungs-Plugins bekannt (siehe
     * mu-plugins-referenz/). Die Feldnamen stehen damit fest und müssen
     * nicht mehr an ihrer Wertform erraten werden.
     *
     * Das Raten bleibt als Rückfallweg bestehen: Sollte das Kern-Plugin
     * einmal andere Namen verwenden, fällt hier nichts aus — es wird dann
     * eben wieder geschaut, was wie ein Datum oder ein Code aussieht.
     */
    $date_key = metadata_exists('post', $post_id, '_rc_date') ? '_rc_date' : null;
    $time_key = metadata_exists('post', $post_id, '_rc_time') ? '_rc_time' : null;
    $code_key = metadata_exists('post', $post_id, '_rc_code') ? '_rc_code' : null;
    $combined_key = null;
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

        // Bereits als Datum, Zeit oder Code vergeben? Dann nicht doppelt zeigen.
        if ($key === $date_key || $key === $time_key || $key === $code_key) {
            continue;
        }

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
        $raw = isset($all_meta[$combined_key][0]) ? $all_meta[$combined_key][0] : '';
        $raw = str_replace('T', ' ', $raw);
        $dt = date_create($raw, $tz) ?: null;
        $result['datetime_field'] = ['type' => 'combined', 'key' => $combined_key];
    } elseif ($date_key !== null) {
        $raw_date = isset($all_meta[$date_key][0]) ? $all_meta[$date_key][0] : '';
        $raw_time = ($time_key !== null && isset($all_meta[$time_key][0])) ? $all_meta[$time_key][0] : '00:00';
        $dt = date_create(trim($raw_date . ' ' . $raw_time), $tz) ?: null;
        $result['datetime_field'] = ['type' => 'split', 'date_key' => $date_key, 'time_key' => $time_key];
    }
    $result['datetime'] = $dt;

    if ($code_key !== null) {
        $result['code'] = ['key' => $code_key, 'value' => isset($all_meta[$code_key][0]) ? $all_meta[$code_key][0] : ''];
    }

    $result['other'] = $other;

    return $result;
}

/**
 * Ist dieser gespeicherte Signal-Wert ein Link — oder ein Benutzername?
 *
 * Nur der Link öffnet in Signal wirklich einen Chat; beim Benutzernamen
 * bleibt das Suchen von Hand. An zwei Stellen gebraucht, deshalb hier
 * einmal und nicht zweimal als Zeichenkettenvergleich.
 */
function rfat_signal_ist_link($wert) {
    return strpos((string) $wert, 'https://signal.me/#eu/') === 0;
}

/**
 * Eine eingetippte Signal-Angabe prüfen und vereinheitlichen — Benutzername
 * oder Signal-Link.
 *
 * **Warum beides.** Nur der Link (`https://signal.me/#eu/…`) öffnet auf
 * Knopfdruck einen Chat. Er enthält keinen Klartext-Namen, sondern eine
 * Kennung auf Signals Server samt Schlüssel, um den dort verschlüsselt
 * abgelegten Benutzernamen zu entziffern — aus einem Benutzernamen lässt
 * er sich deshalb **nicht** zusammenbauen. Wer nur den Namen einträgt,
 * ist trotzdem erreichbar: Dann muss man ihn in Signal in die Suche
 * eingeben.
 *
 * Benutzernamen bestehen aus einem Namen und einer angehängten Zahl
 * (`maxmuster.42`). Der Name ist 3–32 Zeichen lang, erlaubt sind
 * Kleinbuchstaben, Ziffern und Unterstriche, und er fängt nicht mit einer
 * Ziffer an; die Zahl dahinter hat mindestens zwei Stellen.
 *
 * Wer aus Signal kopiert, hat schnell ein `@` in der Zwischenablage —
 * das wird abgeräumt, statt es dem Besucher als Fehler vor die Füße zu
 * werfen.
 *
 * @return array{wert:string,art:string,fehler:string} art = 'link',
 *         'name' oder ''; wert = '' bei leerer Eingabe oder bei einem
 *         Fehler; fehler = fertige Meldung (HTML).
 */
function rfat_signal_pruefen($roh) {
    $wert = trim((string) $roh);
    if ($wert === '') {
        return ['wert' => '', 'art' => '', 'fehler' => ''];
    }

    /*
     * Zuerst der Link — und der bleibt, wie er ist. Sein hinterer Teil ist
     * Base64 und damit gross-/kleinschreibungsempfindlich; wer ihn hier
     * kleinschriebe, machte ihn unbrauchbar.
     *
     * Der Zeichenvorrat ist bewusst weit: Base64 gibt es in zwei Spielarten,
     * die eine mit `-` und `_`, die andere mit `+` und `/`. Welche Signal
     * verwendet, darf hier nicht darüber entscheiden, ob ein gültiger Link
     * angenommen wird — ein zu enger Vorrat wiese ihn wortreich als
     * „kein Signal-Link" ab.
     */
    if (preg_match('#^(?:https?://)?(?:www\.)?signal\.me/\#eu/([A-Za-z0-9_=+/.-]{20,300})$#', $wert, $treffer)) {
        return ['wert' => 'https://signal.me/#eu/' . $treffer[1], 'art' => 'link', 'fehler' => ''];
    }

    // Benutzername: hier darf das Beiwerk weg.
    $wert = preg_replace('#^(?:https?://)?(?:www\.)?signal\.me/?#i', '', $wert);
    $wert = ltrim($wert, "@#/ \t");
    $letzter = strrpos($wert, '/');
    if ($letzter !== false) {
        $wert = substr($wert, $letzter + 1);
    }
    $wert = trim($wert);

    /*
     * Telefonnummern bekommen eine eigene Meldung. Sonst probiert es
     * jemand dreimal mit derselben Nummer, ohne zu verstehen, warum sie
     * abgelehnt wird — und der Sinn des Benutzernamens (die Nummer bleibt
     * geheim) gehört an genau dieser Stelle gesagt.
     */
    if (preg_match('/^\+?[0-9][0-9 ()\/-]{5,}$/', $wert)) {
        return [
            'wert'   => '',
            'art'    => '',
            'fehler' => 'Das sieht nach einer Telefonnummer aus. Bitte trage deinen '
                      . '<strong>Signal-Benutzernamen</strong> oder deinen Signal-Link ein — dann '
                      . 'brauchen wir deine Nummer nicht. Beides findest du in Signal unter '
                      . '<em>Einstellungen &rarr; Profil &rarr; Benutzername</em>.',
        ];
    }

    $wert = strtolower($wert);
    if (!preg_match('/^[a-z_][a-z0-9_]{2,31}\.[0-9]{2,9}$/', $wert)) {
        return [
            'wert'   => '',
            'art'    => '',
            'fehler' => 'Das sieht weder nach einem Signal-Benutzernamen noch nach einem '
                      . 'Signal-Link aus. Der Name besteht aus einem Namen und einer angehängten '
                      . 'Zahl, etwa <code>maxmuster.42</code>; der Link beginnt mit '
                      . '<code>https://signal.me/#eu/</code>. Beides steht in Signal unter '
                      . '<em>Einstellungen &rarr; Profil &rarr; Benutzername</em>.',
        ];
    }

    return ['wert' => $wert, 'art' => 'name', 'fehler' => ''];
}

/**
 * Den Frage-und-Antwort-Verlauf einer Buchung lesen.
 *
 * @return array<int,array{von:string,text:string,zeit:int}>
 */
function rfat_dialog_lesen($post_id) {
    $roh = get_post_meta($post_id, RFAT_DIALOG_META, true);
    if (!is_array($roh)) {
        return [];
    }
    $sauber = [];
    foreach ($roh as $eintrag) {
        if (!is_array($eintrag) || empty($eintrag['text'])) {
            continue;
        }
        $sauber[] = [
            'von'  => ($eintrag['von'] ?? '') === 'gast' ? 'gast' : 'team',
            'text' => (string) $eintrag['text'],
            'zeit' => (int) ($eintrag['zeit'] ?? 0),
        ];
    }
    return $sauber;
}

/**
 * Einen Beitrag anhängen. Gibt zurück, ob etwas gespeichert wurde.
 *
 * Der Text wird gekürzt und der Verlauf gedeckelt: Das Feld steht in der
 * Datenbank an einer Buchung, nicht in einem Postfach, und ein Formular im
 * Netz ohne Obergrenze ist eine Einladung.
 */
function rfat_dialog_anhaengen($post_id, $von, $text) {
    $text = trim(sanitize_textarea_field($text));
    if ($text === '') {
        return false;
    }
    $verlauf   = rfat_dialog_lesen($post_id);
    $verlauf[] = [
        'von'  => $von === 'gast' ? 'gast' : 'team',
        'text' => mb_substr($text, 0, 1000),
        'zeit' => time(),
    ];
    if (count($verlauf) > RFAT_DIALOG_MAX) {
        $verlauf = array_slice($verlauf, -RFAT_DIALOG_MAX);
    }
    update_post_meta($post_id, RFAT_DIALOG_META, $verlauf);
    return true;
}

/**
 * Steht eine Frage des Teams offen — also ohne Antwort dahinter?
 *
 * Genau das entscheidet, ob auf der Terminseite ein Antwortfeld erscheint
 * und ob in der Übersicht „wartet auf Antwort" steht.
 *
 * @return array{von:string,text:string,zeit:int}|null
 */
function rfat_dialog_offene_frage($post_id) {
    $verlauf = rfat_dialog_lesen($post_id);
    if (!$verlauf) {
        return null;
    }
    $letzter = end($verlauf);
    return $letzter['von'] === 'team' ? $letzter : null;
}

function rfat_get_meta_or_empty($post_id) {
    $meta = get_post_meta($post_id);
    return is_array($meta) ? $meta : [];
}

function rfat_humanize_key($key) {
    /*
     * Die Namen des Kern-Plugins sind bekannt — also richtige Bezeichnungen
     * statt "Rc note". Vor allem die Problembeschreibung stand bisher als
     * kryptisches Rohfeld da, dabei ist sie das Einzige, was die Werkstatt
     * vor dem Termin wirklich wissen will.
     */
    $bekannt = [
        '_rc_note' => 'Problembeschreibung',
        '_rc_cat'  => 'Bereich',
        '_rc_slot' => 'Slot-Kennung',
        '_rc_date' => 'Datum',
        '_rc_time' => 'Uhrzeit',
        '_rc_code' => 'Buchungscode',
    ];
    if (isset($bekannt[$key])) {
        return $bekannt[$key];
    }

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

        /*
         * Signal. Dieselbe Prüfung wie im öffentlichen Formular — sonst
         * stünde hier irgendwann eine Telefonnummer im Feld für den
         * Benutzernamen. Hakt sie, bleibt das Gespeicherte unangetastet
         * und die Übersicht sagt, warum.
         */
        $signal_fehler = '';
        if (isset($_POST['rfat_signal'])) {
            $signal = rfat_signal_pruefen(sanitize_text_field(wp_unslash($_POST['rfat_signal'])));
            if ($signal['fehler'] !== '') {
                $signal_fehler = '1';
            } elseif ($signal['wert'] === '') {
                delete_post_meta($post_id, RFAT_SIGNAL_META);
            } else {
                update_post_meta($post_id, RFAT_SIGNAL_META, $signal['wert']);
            }
        }

        /*
         * Rückfrage. Wird angehängt, nicht ersetzt — und nur, wenn wirklich
         * etwas drinstand: Ein Speichern wegen einer Uhrzeitänderung darf
         * keine leere Frage in den Verlauf schreiben.
         */
        if (!empty($_POST['rfat_frage'])) {
            $frage = wp_unslash($_POST['rfat_frage']);
            if (rfat_dialog_anhaengen($post_id, 'team', $frage)) {
                rfat_notify_frage($post_id, trim(sanitize_textarea_field($frage)));
            }
        }

        $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=rc_booking&page=rfat-overview');
        $redirect = add_query_arg('rfat_saved', $post_id, $redirect);
        if ($signal_fehler !== '') {
            $redirect = add_query_arg('rfat_signal_fehler', '1', $redirect);
        }
        wp_safe_redirect($redirect);
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

    if (isset($_POST['rfat_action']) && $_POST['rfat_action'] === 'save_limit') {
        if (!current_user_can('manage_options')
            || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rfat_save_limit')) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }
        $roh = (int) ($_POST['rfat_buchung_limit'] ?? RFAT_LIMIT_DEFAULT);
        // 0 heißt aus; sonst gilt die Untergrenze aus rfat_buchung_limit().
        // Autoload an: rfat_buchung_limit() wird bei jeder Buchungsseite
        // gelesen, eine eigene Abfrage dafür wäre unnötig.
        update_option(RFAT_LIMIT_OPTION, $roh <= 0 ? 0 : max(2, min(20, $roh)));
        wp_safe_redirect(add_query_arg('rfat_limit_saved', '1', wp_get_referer() ?: admin_url()));
        exit;
    }

    if (isset($_POST['rfat_action']) && $_POST['rfat_action'] === 'save_kategorien') {
        if (!current_user_can('manage_options')
            || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rfat_save_kategorien')) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        $roh   = isset($_POST['rfat_kat']) && is_array($_POST['rfat_kat']) ? wp_unslash($_POST['rfat_kat']) : [];
        $neu   = [];
        foreach ($roh as $zeile) {
            if (!is_array($zeile)) {
                continue;
            }
            $name = trim(sanitize_text_field($zeile['name'] ?? ''));
            if ($name === '') {
                // Name geleert heißt: Zeile weg. So wird eine Kategorie gelöscht.
                continue;
            }

            /*
             * Der Schlüssel entsteht einmal aus dem Namen und bleibt dann.
             * Er steckt in jeder Slot-Kennung und an jeder Buchung; wer ihn
             * später mitzöge, würde bestehende Buchungen von ihrer
             * Kategorie abschneiden.
             */
            $slug = sanitize_key($zeile['slug'] ?? '');
            if ($slug === '') {
                $slug = sanitize_key(sanitize_title($name));
            }
            if ($slug === '') {
                $slug = 'kat';
            }
            $basis = $slug;
            $n = 2;
            while (isset($neu[$slug])) {
                $slug = $basis . '-' . $n++;
            }

            $neu[$slug] = [
                'name'    => $name,
                'zeiten'  => rfat_zeiten_saeubern($zeile['zeiten'] ?? ''),
                'buchbar' => !empty($zeile['buchbar']),
            ];
        }

        $ziel = wp_get_referer() ?: admin_url('edit.php?post_type=rc_booking&page=rfat-overview');
        if (!$neu) {
            // Ohne Kategorie gäbe es keine Buchung mehr — das ist kein
            // Zustand, in den man versehentlich rutschen soll.
            wp_safe_redirect(add_query_arg('rfat_kat_leer', '1', $ziel));
            exit;
        }

        update_option(RFAT_KATEGORIEN_OPTION, $neu);
        delete_transient('rfat_booked_slots');
        wp_safe_redirect(add_query_arg('rfat_kat_saved', '1', $ziel));
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

    /*
     * no_found_rows spart die Zaehl-Abfrage, der Term-Cache ist bei
     * Buchungen ohne Kategorien ohnehin leer. Der Meta-Cache bleibt an -
     * gleich darauf werden die Meta-Felder gebraucht.
     */
    $posts = get_posts([
        'post_type'      => 'rc_booking',
        'post_status'    => ['publish', 'draft', 'pending', 'future'],
        'numberposts'    => -1,
        'no_found_rows'          => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
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
        <?php if (isset($_GET['rfat_signal_fehler'])): ?>
            <div class="notice notice-warning is-dismissible"><p>
                <strong>Die Signal-Angabe wurde nicht übernommen</strong> — alles andere schon.
                Erwartet wird entweder der Link (<code>https://signal.me/#eu/…</code>, in Signal
                unter <em>Einstellungen &rarr; Profil &rarr; Benutzername &rarr; Link kopieren</em>)
                oder der Benutzername in der Form <code>maxmuster.42</code>.
            </p></div>
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
        <?php if (isset($_GET['rfat_limit_saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>Buchungsgrenze gespeichert.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['rfat_kat_saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>Kategorien gespeichert.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['rfat_kat_leer'])): ?>
            <div class="notice notice-error is-dismissible"><p>
                <strong>Nicht gespeichert:</strong> Es muss mindestens eine Kategorie mit Namen geben —
                sonst liesse sich gar nichts mehr buchen.
            </p></div>
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
                                <?php $hint = rfat_explain_mail_error($log['error']); ?>
                                <?php if ($hint !== ''): ?>
                                    <br /><strong>Was das heißt:</strong> <?php echo esc_html($hint); ?>
                                <?php endif; ?>
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

            <?php
            $limit    = rfat_buchung_limit();
            $erkannt  = rfat_besucher_ip();
            $limitlog = get_option(RFAT_LIMIT_LOG);
            ?>
            <form method="post" style="margin:10px 0 0;">
                <?php wp_nonce_field('rfat_save_limit'); ?>
                <input type="hidden" name="rfat_action" value="save_limit" />
                <label for="rfat_buchung_limit" style="font-weight:600;">Offene Termine je Anschluss</label>
                <input type="number" id="rfat_buchung_limit" name="rfat_buchung_limit" min="0" max="20" step="1"
                       style="width:70px;" value="<?php echo esc_attr($limit); ?>" />
                <button type="submit" class="button">Speichern</button>
                <span class="description" style="margin-left:8px;">
                    <?php if ($limit === 0): ?>
                        <strong style="color:#b3402f;">Die Sperre ist aus.</strong>
                        Ein einzelnes Gerät kann alle freien Termine wegbuchen.
                    <?php else: ?>
                        Mehr als <strong><?php echo (int) $limit; ?></strong> gleichzeitig offene Termine
                        kann ein Anschluss nicht buchen. Storniert jemand, wird sein Platz sofort wieder frei.
                    <?php endif; ?>
                    <strong>0</strong> schaltet die Sperre ab, weniger als 2 geht nicht
                    (beim Verschieben existieren kurz zwei Buchungen nebeneinander).
                </span>
                <p class="description" style="margin:8px 0 0;">
                    <?php if ($erkannt['ip'] === ''): ?>
                        <strong style="color:#b3402f;">Die Sperre greift derzeit nicht:</strong>
                        Der Server sieht keine öffentliche Adresse des Aufrufers
                        <?php if ($erkannt['quelle'] !== ''): ?>(nur die interne aus <code><?php echo esc_html($erkannt['quelle']); ?></code>)<?php endif; ?>.
                        Dann sähen alle Besucher gleich aus, deshalb wird nichts gesperrt — sonst träfe es alle.
                        Abhilfe: Der Reverse-Proxy vor WordPress muss <code>X-Forwarded-For</code>
                        bzw. <code>CF-Connecting-IP</code> durchreichen.
                    <?php else: ?>
                        Erkannt wird gerade <code><?php echo esc_html($erkannt['ip']); ?></code>
                        über <code><?php echo esc_html($erkannt['quelle']); ?></code> — das ist deine eigene Adresse.
                        Gespeichert wird sie nirgends: An der Buchung steht nur ein Prüfwert daraus.
                    <?php endif; ?>
                    <?php if (is_array($limitlog) && !empty($limitlog['anzahl'])): ?>
                        <br />Bisher abgewiesen: <strong><?php echo (int) $limitlog['anzahl']; ?></strong>
                        <?php if (!empty($limitlog['zeit'])): ?>
                            (zuletzt <?php echo esc_html(wp_date('d.m.Y H:i', (int) $limitlog['zeit'])); ?><?php
                                echo $limitlog['grund'] === 'zuschnell' ? ', zu schnell hintereinander' : ', Kontingent voll'; ?>).
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </form>

            <?php
            /*
             * Kategorien. Bis 1.23.0 standen „IT" und „E-Bike" fest im
             * Code — jede weitere hätte ein Release gebraucht.
             *
             * In <details>, weil man Kategorien einmal einrichtet und
             * danach selten anfasst; aufgeklappt bleibt es, solange etwas
             * schiefging oder gerade gespeichert wurde.
             */
            $kategorien = rfat_kategorien();
            $kat_offen  = isset($_GET['rfat_kat_saved']) || isset($_GET['rfat_kat_leer']);
            ?>
            <details style="margin:14px 0 0;" <?php echo $kat_offen ? 'open' : ''; ?>>
                <summary style="cursor:pointer;font-weight:600;">Kategorien und Uhrzeiten (<?php echo count($kategorien); ?>)</summary>
                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field('rfat_save_kategorien'); ?>
                    <input type="hidden" name="rfat_action" value="save_kategorien" />
                    <table class="widefat striped" style="max-width:820px;">
                        <thead>
                            <tr>
                                <th style="width:34%;">Name</th>
                                <th>Uhrzeiten</th>
                                <th style="width:90px;">buchbar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $zeilen = $kategorien;
                            // Eine leere Zeile zum Anlegen — immer genau eine.
                            $zeilen['__neu'] = ['name' => '', 'zeiten' => [], 'buchbar' => true, 'neu' => true];
                            $nr = 0;
                            foreach ($zeilen as $slug => $eintrag):
                                $ist_neu = !empty($eintrag['neu']);
                                $nr++;
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$ist_neu): ?>
                                            <input type="hidden" name="rfat_kat[<?php echo (int) $nr; ?>][slug]" value="<?php echo esc_attr($slug); ?>" />
                                        <?php endif; ?>
                                        <input type="text" style="width:100%;"
                                               name="rfat_kat[<?php echo (int) $nr; ?>][name]"
                                               value="<?php echo esc_attr($eintrag['name']); ?>"
                                               placeholder="<?php echo $ist_neu ? 'Neue Kategorie, z. B. Nähmaschinen' : ''; ?>" />
                                        <?php if (!$ist_neu): ?>
                                            <span class="description"><code><?php echo esc_html($slug); ?></code></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="text" style="width:100%;"
                                               name="rfat_kat[<?php echo (int) $nr; ?>][zeiten]"
                                               value="<?php echo esc_attr(implode(', ', $eintrag['zeiten'])); ?>"
                                               placeholder="14:00, 15:00, 16:00" />
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" value="1"
                                               name="rfat_kat[<?php echo (int) $nr; ?>][buchbar]"
                                               <?php checked(!empty($eintrag['buchbar'])); ?> />
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin:10px 0 0;">
                        <button type="submit" class="button button-primary">Kategorien speichern</button>
                        <span class="description" style="margin-left:8px;">
                            Uhrzeiten durch Komma trennen. Der Haken <strong>buchbar</strong> entscheidet, ob die
                            Kategorie im Ablauf erscheint — ohne ihn bleibt sie erhalten, bestehende Buchungen
                            behalten also ihren Klartextnamen.
                            <br />
                            <strong>Name leeren und speichern löscht die Zeile.</strong> Bestehende Buchungen dazu
                            bleiben bestehen, zeigen dann aber nur noch den Kurznamen
                            (<code>naehmaschinen</code> statt <em>Nähmaschinen</em>) — zum Stilllegen ist der Haken
                            der bessere Weg.
                            <br />
                            Der Kurzname entsteht einmal aus dem Namen und bleibt dann: Er steckt in jeder Buchung.
                            Umbenennen ändert nur, was Besucher lesen.
                        </span>
                    </p>
                </form>
            </details>
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
                            $mail   = (string) get_post_meta($p->ID, RFAT_EMAIL_META, true);
                            $signal = (string) get_post_meta($p->ID, RFAT_SIGNAL_META, true);
                            if ($mail !== '' || $signal !== '') :
                                $keep = get_post_meta($p->ID, RFAT_EMAIL_KEEP_META, true) === '1';
                                ?>
                                <div style="margin-top:6px;font-size:12px;line-height:1.5;">
                                    <?php if ($mail !== ''): ?>
                                        <a href="mailto:<?php echo esc_attr($mail); ?>"><?php echo esc_html($mail); ?></a><br />
                                    <?php endif; ?>
                                    <?php if ($signal !== ''): ?>
                                        <?php if (rfat_signal_ist_link($signal)): ?>
                                            <?php
                                            /*
                                             * Der Signal-Link öffnet den Chat wirklich —
                                             * er trägt eine Kennung auf Signals Server samt
                                             * Schlüssel für den dort verschlüsselt
                                             * abgelegten Benutzernamen.
                                             */
                                            ?>
                                            <a class="button button-small" style="margin:2px 0;"
                                               href="<?php echo esc_url($signal); ?>"
                                               target="_blank" rel="noopener">In Signal öffnen</a><br />
                                        <?php else: ?>
                                            <?php
                                            /*
                                             * Nur der Benutzername: Daraus lässt sich kein
                                             * Link bauen (siehe rfat_signal_pruefen), also
                                             * kein Knopf, der ins Leere führt. Stattdessen
                                             * den Namen in die Zwischenablage — in Signal
                                             * oben in die Suche einfügen, das findet die Person.
                                             */
                                            ?>
                                            Signal: <code><?php echo esc_html($signal); ?></code>
                                            <button type="button" class="button button-small" style="margin-left:4px;"
                                                    onclick="if(navigator.clipboard){navigator.clipboard.writeText('<?php echo esc_js($signal); ?>');this.textContent='Kopiert \u2713';}">Namen kopieren</button>
                                            <br />
                                            <span style="color:#8a6d1f;">Nur ein Benutzername — in Signal in die Suche einfügen.
                                                Für „In Signal öffnen" bräuchten wir den Signal-Link
                                                (unter <em>Bearbeiten</em> nachtragbar).</span><br />
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <span style="color:<?php echo $keep ? '#5b6b62' : '#8a6d1f'; ?>;">
                                        <?php echo $keep
                                            ? 'darf gespeichert bleiben'
                                            : 'wird nach dem Termin gelöscht'; ?>
                                    </span>

                                    <?php
                                    /*
                                     * Fertige Nachricht zum Mitnehmen.
                                     *
                                     * Signal-Links können keinen Text vorbelegen — es gibt
                                     * kein Gegenstück zu WhatsApps ?text=. Näher als
                                     * „kopieren und Chat öffnen" kommt man nicht heran.
                                     *
                                     * In <details>, damit die Tabelle nicht zuwächst: Die
                                     * Nachricht braucht man beim Schreiben, nicht beim
                                     * Überfliegen der Liste.
                                     */
                                    $msg_id = 'rfat-msg-' . (int) $p->ID;
                                    ?>
                                    <details style="margin-top:6px;">
                                        <summary style="cursor:pointer;">Nachricht vorbereiten</summary>
                                        <textarea id="<?php echo esc_attr($msg_id); ?>" rows="6"
                                                  style="width:100%;max-width:420px;margin-top:6px;font-family:inherit;"><?php
                                            echo esc_textarea(rfat_signal_nachricht($p->ID));
                                        ?></textarea>
                                        <div>
                                            <?php if ($signal !== '' && rfat_signal_ist_link($signal)): ?>
                                                <button type="button" class="button button-primary button-small"
                                                        onclick="var t=document.getElementById('<?php echo esc_js($msg_id); ?>');t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value);}this.textContent='Kopiert \u2713 — in Signal einfügen';window.open('<?php echo esc_js($signal); ?>','_blank','noopener');">Text kopieren &amp; Signal öffnen</button>
                                            <?php else: ?>
                                                <button type="button" class="button button-small"
                                                        onclick="var t=document.getElementById('<?php echo esc_js($msg_id); ?>');t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value);}this.textContent='Kopiert \u2713';">Nachricht kopieren</button>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?php echo esc_attr(rfat_status_color($status)); ?>;color:#fff;font-weight:600;font-size:12px;">
                                <?php echo esc_html(rfat_status_label($status)); ?>
                            </span>
                            <?php
                            /*
                             * Ob eine Rückfrage offen ist oder eine Antwort wartet,
                             * gehoert in die Liste — im Bearbeiten-Bereich sieht es
                             * sonst nur, wer ihn ohnehin aufklappt.
                             */
                            $verlauf_kurz = rfat_dialog_lesen($p->ID);
                            if ($verlauf_kurz):
                                $letzter = end($verlauf_kurz);
                                $vorletzter = count($verlauf_kurz) > 1 ? $verlauf_kurz[count($verlauf_kurz) - 2] : null;
                                if ($letzter['von'] !== 'gast') {
                                    $hinweis = 'wartet auf Antwort';
                                } elseif ($vorletzter && $vorletzter['von'] === 'team') {
                                    $hinweis = 'Antwort da';
                                } else {
                                    // Von sich aus geschrieben, ohne dass wir gefragt hätten.
                                    $hinweis = 'Notiz vom Gast';
                                }
                                ?>
                                <div style="margin-top:6px;font-size:12px;font-weight:600;color:<?php echo $letzter['von'] === 'gast' ? '#1f5a38' : '#8a6d1f'; ?>;">
                                    <?php echo esc_html($hinweis); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (in_array($status, ['angefragt', 'offen'], true)): ?>
                                <a class="button button-primary" style="margin-bottom:4px;"
                                   href="<?php echo esc_url(rfat_action_url($p->ID, 'bestaetigen')); ?>">Zusagen</a>
                                <?php
                                /*
                                 * Das Gegenstück zum Zusagen. Der Weg dahinter gibt es
                                 * schon länger — er steckte bisher nur im Link der
                                 * Benachrichtigungsmail, in der Übersicht blieb als
                                 * Absage der Papierkorb, der niemanden benachrichtigt.
                                 *
                                 * Kein Formular mit confirm(), sondern derselbe Link
                                 * wie beim Zusagen: Die Seite dahinter fragt ohnehin
                                 * noch einmal nach, und dort steht dann Termin und
                                 * Code dabei — mehr als ein confirm()-Kasten zeigt.
                                 */
                                ?>
                                <a class="button" style="margin-bottom:4px;color:#b3402f;border-color:#b3402f;"
                                   href="<?php echo esc_url(rfat_action_url($p->ID, 'absagen')); ?>">Ablehnen</a>
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

                                <?php
                                /*
                                 * Signal von Hand eintragen dürfen.
                                 *
                                 * Kontaktdaten gehören dem Gast, deshalb steht die
                                 * E-Mail hier bewusst nicht — sie trägt er selbst ein
                                 * und löscht sie selbst. Beim Signal-Link ist es aber
                                 * anders herum praktisch: Wer ihn am Telefon oder am
                                 * Tresen durchgibt, kommt sonst nie zu dem Knopf, der
                                 * den Chat wirklich öffnet. Leeres Feld heisst löschen.
                                 */
                                ?>
                                <p>
                                    <label style="display:block;font-weight:600;margin-bottom:2px;" for="rfat-signal-<?php echo esc_attr($p->ID); ?>">Signal-Link oder Benutzername</label>
                                    <input type="text" id="rfat-signal-<?php echo esc_attr($p->ID); ?>" name="rfat_signal"
                                           value="<?php echo esc_attr((string) get_post_meta($p->ID, RFAT_SIGNAL_META, true)); ?>"
                                           placeholder="https://signal.me/#eu/… oder maxmuster.42"
                                           style="width:100%;max-width:480px;" />
                                    <span class="description" style="display:block;margin-top:2px;">
                                        Nur der <strong>Link</strong> ergibt den Knopf „In Signal öffnen".
                                        Beim blossen Benutzernamen bleibt es beim Kopieren und Suchen —
                                        aus ihm lässt sich kein Link bauen. Leeren und speichern löscht die Angabe.
                                    </span>
                                </p>

                                <?php
                                /*
                                 * Rückfragen. Der Verlauf steht dabei, sonst fragt man
                                 * zum dritten Mal dasselbe — und der Gast sieht dieselbe
                                 * Liste auf seiner Terminseite.
                                 */
                                $verlauf = rfat_dialog_lesen($p->ID);
                                ?>
                                <?php if ($verlauf): ?>
                                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px 10px;max-width:480px;margin-bottom:10px;">
                                        <?php foreach ($verlauf as $eintrag): ?>
                                            <p style="margin:4px 0;font-size:13px;line-height:1.5;">
                                                <strong><?php echo $eintrag['von'] === 'gast' ? 'Gast' : 'Wir'; ?></strong>
                                                <span style="color:#787c82;"><?php echo esc_html(wp_date('d.m. H:i', $eintrag['zeit'])); ?></span><br />
                                                <?php echo nl2br(esc_html($eintrag['text'])); ?>
                                            </p>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <p>
                                    <label style="display:block;font-weight:600;margin-bottom:2px;" for="rfat-frage-<?php echo esc_attr($p->ID); ?>">Rückfrage an den Gast</label>
                                    <textarea id="rfat-frage-<?php echo esc_attr($p->ID); ?>" name="rfat_frage" rows="2"
                                              style="width:100%;max-width:480px;" placeholder="z. B. Bringst du das Ladegerät mit?"></textarea>
                                    <span class="description" style="display:block;margin-top:2px;">
                                        Erscheint auf der Terminseite des Gasts, sobald er sie öffnet — und geht
                                        als Mail raus, wenn er eine Adresse hinterlassen hat. Bei Signal steht
                                        die Frage im vorbereiteten Nachrichtentext. Leer lassen ändert nichts.
                                    </span>
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

    /*
     * Die Selbstverwaltungs-Seite schlaegt denselben Code beim Laden,
     * Stornieren und E-Mail-Speichern mehrfach nach. Gecacht wird nur die
     * Zuordnung Code -> Post-ID; Beitrag und Analyse werden jedes Mal
     * frisch geholt, damit Aenderungen sofort sichtbar sind.
     */
    $cache_key = 'rfat_lookup_' . md5($code);
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['post_id'])) {
        $post = get_post($cached['post_id']);
        if ($post && $post->post_type === 'rc_booking' && $post->post_status !== 'trash') {
            return [
                'post'     => $post,
                'analysis' => rfat_analyse_booking($post->ID),
            ];
        }
        delete_transient($cache_key);   // Buchung ist weg
    }
    /*
     * Direkt ueber _rc_code suchen statt jede Buchung zu laden und ihre
     * Felder zu untersuchen. Bei einer Handvoll Terminen fällt das nicht
     * auf, ab ein paar hundert schon — und die Datenbank kann das besser.
     */
    $posts = get_posts([
        'post_type'   => 'rc_booking',
        'numberposts' => 1,
        'post_status' => ['publish', 'draft', 'pending', 'future'],
        'meta_key'    => '_rc_code',
        'meta_value'  => $code,
    ]);
    if ($posts) {
        set_transient($cache_key, ['post_id' => $posts[0]->ID], MINUTE_IN_SECONDS);
        return ['post' => $posts[0], 'analysis' => rfat_analyse_booking($posts[0]->ID)];
    }

    /*
     * Rückfall für Buchungen ohne _rc_code — etwa aus einer Zeit vor dem
     * Kern-Plugin, oder falls es den Namen einmal ändert.
     */
    $alle = get_posts([
        'post_type'   => 'rc_booking',
        'numberposts' => -1,
        'post_status' => ['publish', 'draft', 'pending', 'future'],
    ]);
    foreach ($alle as $p) {
        $analysis = rfat_analyse_booking($p->ID);
        $found_code = $analysis['code']['value'] ?? '';
        if ($found_code !== '' && rfat_normalize_code($found_code) === $code) {
            set_transient($cache_key, ['post_id' => $p->ID], MINUTE_IN_SECONDS);
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
/*
 * Kategorien und ihre Uhrzeiten (seit 1.24.0 einstellbar).
 *
 * Bis dahin standen „IT" und „E-Bike" fest im Code — jede weitere
 * Kategorie hätte ein Release gebraucht. Jetzt stehen sie in einer Option
 * und lassen sich in der Übersicht pflegen.
 *
 * Der Schlüssel (Slug) bleibt dabei, was er ist: Er steckt in jeder
 * Slot-Kennung (`it_2026-08-29_1430`) und an jeder Buchung (`_rc_cat`).
 * Umbenennen darf man deshalb nur den Namen, nie den Schlüssel — sonst
 * fänden bestehende Buchungen ihre Kategorie nicht mehr.
 */
define('RFAT_KATEGORIEN_OPTION', 'rfat_kategorien');

/**
 * Die Voreinstellung — genau die beiden Kategorien, die bis 1.23.0 fest
 * im Code standen.
 */
function rfat_kategorien_standard() {
    return [
        'it' => [
            'name'    => 'IT & Elektronik (Handy · Laptop · IT)',
            'zeiten'  => ['14:00', '14:30', '15:00', '15:30', '16:00', '16:30'],
            'buchbar' => true,
        ],
        'ebike' => [
            'name'    => 'E-Bike-Service',
            'zeiten'  => ['14:00', '15:00', '16:00'],
            'buchbar' => true,
        ],
    ];
}

/**
 * Alle Kategorien, wie sie eingestellt sind.
 *
 * @param bool $nur_buchbare Nur die, die im Buchungsablauf erscheinen
 *                           sollen. Für die Anzeige alter Buchungen
 *                           braucht es auch die stillgelegten.
 */
function rfat_kategorien($nur_buchbare = false) {
    $roh = get_option(RFAT_KATEGORIEN_OPTION, null);
    $liste = is_array($roh) && $roh ? $roh : rfat_kategorien_standard();

    $sauber = [];
    foreach ($liste as $slug => $eintrag) {
        $slug = sanitize_key((string) $slug);
        if ($slug === '' || !is_array($eintrag)) {
            continue;
        }
        $name = trim((string) ($eintrag['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $buchbar = !isset($eintrag['buchbar']) || (bool) $eintrag['buchbar'];
        if ($nur_buchbare && !$buchbar) {
            continue;
        }
        $sauber[$slug] = [
            'name'    => $name,
            'zeiten'  => rfat_zeiten_saeubern($eintrag['zeiten'] ?? []),
            'buchbar' => $buchbar,
        ];
    }

    return $sauber;
}

/**
 * Uhrzeiten in Ordnung bringen: nur HH:MM, ohne Dopplungen, aufsteigend.
 *
 * Eine leere Liste ist erlaubt und heißt „für diese Kategorie gibt es
 * derzeit keine Termine" — nicht dasselbe wie „nicht buchbar", aber in
 * der Wirkung nah dran.
 */
function rfat_zeiten_saeubern($roh) {
    if (is_string($roh)) {
        $roh = preg_split('/[\s,;]+/', $roh);
    }
    if (!is_array($roh)) {
        return [];
    }
    $zeiten = [];
    foreach ($roh as $wert) {
        $wert = trim((string) $wert);
        if (preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $wert, $t)
            && (int) $t[1] < 24 && (int) $t[2] < 60) {
            $zeiten[] = sprintf('%02d:%02d', (int) $t[1], (int) $t[2]);
        }
    }
    $zeiten = array_values(array_unique($zeiten));
    sort($zeiten);
    return array_slice($zeiten, 0, 40);
}

function rfat_friendly_category($raw) {
    $key = strtolower(trim((string) $raw));

    /*
     * Auch die stillgelegten Kategorien: Eine Buchung von vor der
     * Umstellung soll ihren Klartextnamen behalten, selbst wenn die
     * Kategorie längst nicht mehr buchbar ist.
     */
    $kategorien = rfat_kategorien();
    if (isset($kategorien[$key])) {
        return $kategorien[$key]['name'];
    }

    // Ganz gelöscht und trotzdem noch an einer Buchung: dann der Slug,
    // lesbar gemacht.
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

/**
 * Die beiden Caches verwerfen, sobald sich an einer Buchung etwas aendert.
 *
 * An den Ereignissen statt an den Aufrufstellen: Storniert wird an vier
 * Stellen (Selbstverwaltung, Verschieben, Mail-Link, Papierkorb im
 * Backend), und die fuenfte kommt bestimmt. Was am Hook haengt, kann beim
 * naechsten Mal nicht vergessen werden.
 */
function rfat_caches_verwerfen($post_id, $post = null) {
    $typ = $post instanceof WP_Post ? $post->post_type : get_post_type($post_id);
    if ($typ !== 'rc_booking') {
        return;
    }
    delete_transient('rfat_booked_slots');

    $code = get_post_meta($post_id, '_rc_code', true);
    if ($code !== '') {
        delete_transient('rfat_lookup_' . md5(rfat_normalize_code($code)));
    }
}
add_action('save_post_rc_booking', 'rfat_caches_verwerfen', 10, 2);
add_action('trashed_post',       'rfat_caches_verwerfen', 10, 2);
add_action('untrashed_post',     'rfat_caches_verwerfen', 10, 2);
// Vor dem Loeschen, solange die Meta-Felder noch lesbar sind.
add_action('before_delete_post', 'rfat_caches_verwerfen', 10, 2);

/**
 * Sicherstellen, dass es /termin-abrufen/ gibt.
 *
 * Die Seite wurde bisher nirgends angelegt — sie existierte nur, weil sie
 * einmal von Hand erstellt worden war. Verlinkt wird sie an einem Dutzend
 * Stellen (Bestaetigungsmails, Abmeldelink, Kalendereintrag), und seit die
 * Buchung direkt dorthin fuehrt, haengt der ganze Ablauf daran. Eine
 * versehentlich geloeschte Seite wuerde ihn abreissen.
 *
 * Laeuft einmalig; die Option verhindert eine Abfrage bei jedem Aufruf.
 */
add_action('init', function () {
    if (get_option('rfat_abrufseite') === '1') {
        return;
    }

    $seite = get_page_by_path('termin-abrufen');

    if (!$seite) {
        wp_insert_post([
            'post_title'   => 'Termin abrufen',
            'post_name'    => 'termin-abrufen',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => "<p>Gib deinen Buchungscode ein, um deinen Termin anzusehen, "
                            . "eine E-Mail zu hinterlegen, ihn zu verschieben oder abzusagen.</p>\n"
                            . "[rfat_manage_booking]",
        ]);
    } else {
        $aenderung = ['ID' => $seite->ID];
        if ($seite->post_status !== 'publish') {
            $aenderung['post_status'] = 'publish';
        }
        // Fehlt der Shortcode, anhaengen statt den Text zu ueberschreiben —
        // was dort steht, gehoert dem Betreiber.
        if (!has_shortcode($seite->post_content, 'rfat_manage_booking')) {
            $aenderung['post_content'] = $seite->post_content . "\n[rfat_manage_booking]";
        }
        if (count($aenderung) > 1) {
            wp_update_post($aenderung);
        }
    }

    update_option('rfat_abrufseite', '1');
}, 25);

add_shortcode('rfat_manage_booking', function ($atts) {
    if (!post_type_exists('rc_booking')) {
        return '<p>Die Terminverwaltung ist gerade nicht verfügbar.</p>';
    }

    $notice = '';

    /*
     * Direkt aus der Buchung hierher gesprungen. Die Zwischenseite mit
     * "Termin vorgemerkt" entfaellt dadurch — der Hinweis, der dort stand,
     * darf aber nicht mit verschwinden: Der Code ist das Einzige, womit
     * man spaeter wieder an den Termin kommt.
     */
    if (!empty($_GET['neu'])) {
        $notice = '<div class="rfat-pub-notice rfat-pub-success">'
                . '<strong>Termin vorgemerkt.</strong> Wir sehen ihn uns an und melden uns.</div>';
    }
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
                    delete_post_meta($match['post']->ID, RFAT_SIGNAL_META);
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
                /*
                 * Der Link steht in E-Mails und meint auch nur die E-Mail.
                 * Ein hinterlegter Signal-Name bleibt deshalb — und mit ihm
                 * das Häkchen, das ihn vor dem Löschen nach dem Termin
                 * bewahrt. Ohne diese Bedingung nähme die Abmeldung von der
                 * einen Sache stillschweigend die Entscheidung zur anderen mit.
                 */
                if ((string) get_post_meta($um['post']->ID, RFAT_SIGNAL_META, true) === '') {
                    delete_post_meta($um['post']->ID, RFAT_EMAIL_KEEP_META);
                }
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
                $signal_roh = isset($_POST['rfat_pub_signal'])
                    ? sanitize_text_field(wp_unslash($_POST['rfat_pub_signal'])) : '';
                $keep  = !empty($_POST['rfat_pub_email_keep']);

                /*
                 * Beide Kontaktwege stehen in einem Formular und werden
                 * deshalb zusammen geprüft. Hakt einer, wird gar nichts
                 * gespeichert: Sonst landet die E-Mail im Kasten, der
                 * Signal-Name nicht, und im Formular steht beides — man
                 * hielte sich für fertig, obwohl die Hälfte fehlt.
                 */
                $signal  = rfat_signal_pruefen($signal_roh);
                $fehler  = [];
                if ($email !== '' && !is_email($email)) {
                    $fehler[] = 'Das sieht nicht nach einer gültigen E-Mail-Adresse aus.';
                }
                if ($signal['fehler'] !== '') {
                    $fehler[] = $signal['fehler'];
                }

                if ($fehler) {
                    $notice = '<div class="rfat-pub-notice rfat-pub-error">'
                        . implode('<br />', $fehler)
                        . '<br /><strong>Es wurde nichts gespeichert.</strong></div>';
                } else {
                    // Leeres Feld heißt: wieder löschen.
                    if ($email === '') {
                        delete_post_meta($pid, RFAT_EMAIL_META);
                    } else {
                        update_post_meta($pid, RFAT_EMAIL_META, $email);
                    }
                    if ($signal['wert'] === '') {
                        delete_post_meta($pid, RFAT_SIGNAL_META);
                    } else {
                        update_post_meta($pid, RFAT_SIGNAL_META, $signal['wert']);
                    }

                    // Das Häkchen gilt den Kontaktdaten; ohne Kontaktdaten
                    // hat es nichts zu bewachen.
                    if ($keep && ($email !== '' || $signal['wert'] !== '')) {
                        update_post_meta($pid, RFAT_EMAIL_KEEP_META, '1');
                    } else {
                        delete_post_meta($pid, RFAT_EMAIL_KEEP_META);
                    }

                    if ($email === '' && $signal['wert'] === '') {
                        $notice = '<div class="rfat-pub-notice rfat-pub-success">'
                            . 'Zu deinem Termin ist jetzt kein Kontakt mehr gespeichert. '
                            . 'Dein Termin bleibt bestehen.</div>';
                    } else {
                        $signal_wort = $signal['art'] === 'link' ? 'Signal-Link' : 'Signal-Benutzername';
                        $was = ($email !== '' && $signal['wert'] !== '')
                            ? 'E-Mail-Adresse und ' . $signal_wort
                            : ($email !== '' ? 'E-Mail-Adresse' : $signal_wort);
                        $notice = '<div class="rfat-pub-notice rfat-pub-success">Gespeichert: '
                            . $was . '. '
                            . ($keep
                                ? 'Die Angaben bleiben gespeichert, bis du sie hier wieder löschst.'
                                : 'Die Angaben werden nach dem Termin automatisch gelöscht.')
                            . '</div>';
                    }
                }
            }
        }
    }

    /*
     * Der Gast schreibt uns: Antwort auf eine Rückfrage — oder von sich
     * aus eine Notiz.
     *
     * Beides derselbe Weg, weil es beim Speichern dasselbe ist: ein
     * Beitrag des Gasts im Verlauf. Nur die Rückmeldung unterscheidet sich,
     * und dafür genügt der Blick, ob vorher eine Frage offen stand.
     *
     * Der Code bleibt danach stehen, damit die Seite den Termin gleich
     * weiter anzeigt — wer gerade geschrieben hat, will nicht auf einem
     * leeren Eingabefeld landen.
     */
    if (!empty($_POST['rfat_pub_action']) && $_POST['rfat_pub_action'] === 'antwort'
        && !empty($_POST['rfat_pub_code']) && !empty($_POST['_wpnonce'])) {
        $code_value = sanitize_text_field(wp_unslash($_POST['rfat_pub_code']));
        if (!wp_verify_nonce($_POST['_wpnonce'], 'rfat_pub_antwort_' . rfat_normalize_code($code_value))) {
            $notice = '<div class="rfat-pub-notice rfat-pub-error">Sicherheitsprüfung fehlgeschlagen, bitte erneut versuchen.</div>';
        } else {
            $match = rfat_find_booking_by_code($code_value);
            $text  = isset($_POST['rfat_pub_antwort']) ? wp_unslash($_POST['rfat_pub_antwort']) : '';
            if (!$match) {
                $notice = '<div class="rfat-pub-notice rfat-pub-error">Dieser Code wurde nicht gefunden.</div>';
            } elseif (trim((string) $text) === '') {
                $notice = '<div class="rfat-pub-notice rfat-pub-error">Da stand noch nichts drin.</div>';
            } else {
                // Vor dem Anhängen fragen — danach ist keine Frage mehr offen.
                $war_frage = rfat_dialog_offene_frage($match['post']->ID) !== null;
                if (rfat_dialog_anhaengen($match['post']->ID, 'gast', $text)) {
                    rfat_notify_antwort($match['post']->ID, $war_frage);
                    $notice = '<div class="rfat-pub-notice rfat-pub-success">'
                            . ($war_frage
                                ? 'Danke! Deine Antwort ist bei uns.'
                                : 'Notiert — wir haben es zu deinem Termin geschrieben.')
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
        <?php ob_start(); ?>
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
            .rfat-pub-mail-label { display: block; margin: 0; font-weight: 700; font-size: 15px; color: #1c2a22; }
            .rfat-pub-optional {
                display: inline-block; margin-left: 6px; padding: 2px 9px;
                border-radius: 999px; background: #e8f1eb; color: #1f5a38;
                font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
                vertical-align: middle;
            }
            .rfat-pub-mail-intro { margin: 8px 0 12px; font-size: 13px; color: #5b6b62; line-height: 1.5; }
            .rfat-pub-mail-input { max-width: 100%; }
            /* Zwei Felder untereinander brauchen je eine eigene Beschriftung —
               ohne sie raet man beim zweiten Kasten, was hineingehoert. */
            .rfat-pub-feld-label { display: block; font-weight: 600; font-size: 13px; color: #1c2a22; margin: 0 0 4px; }
            .rfat-pub-mail-input + .rfat-pub-feld-label { margin-top: 12px; }
            .rfat-pub-feldhinweis { margin: 6px 0 0; font-size: 12.5px; color: #5b6b62; line-height: 1.5; }

            /* Beschriftung nur fuer Screenreader — das Theme bringt dafuer
               nichts mit, also hier. clip-path statt display:none: Verstecktes
               liest kein Screenreader vor. */
            .rfat-sr {
                position: absolute; width: 1px; height: 1px; overflow: hidden;
                clip-path: inset(50%); white-space: nowrap;
            }

            /* Rueckfrage: der auffaelligste Kasten der Seite. Wer hier
               landet, weil wir etwas wissen wollen, soll die Frage sehen
               und nicht suchen. */
            .rfat-pub-frage {
                background: #fbf2e2; border-left: 4px solid #c08a1e; border-radius: 12px;
                padding: 16px 18px; margin: 0 0 18px;
            }
            .rfat-pub-frage-text { margin: 4px 0 12px; font-size: 17px; line-height: 1.5; color: #1c2a22; }
            .rfat-pub-frage textarea {
                width: 100%; box-sizing: border-box; border: 1px solid #cfd8d2; border-radius: 10px;
                padding: 10px; font: inherit; margin-bottom: 12px;
            }
            .rfat-pub-verlauf {
                background: #f4f6f4; border-radius: 12px; padding: 14px 16px; margin: 0 0 18px;
            }
            .rfat-pub-verlauf-zeile { margin: 6px 0 0; font-size: 14px; line-height: 1.5; color: #3d4a43; }
            .rfat-pub-verlauf-zeile.is-gast { color: #1f5a38; }

            /* Zugeklappt eine Zeile, aufgeklappt ein Formular. */
            .rfat-pub-notiz { margin: 0 0 18px; }
            .rfat-pub-notiz > summary {
                cursor: pointer; font-weight: 600; color: #1f5a38; padding: 6px 0;
            }
            .rfat-pub-notiz-hilfe { margin: 6px 0 10px; font-size: 13px; color: #5b6b62; line-height: 1.5; }
            .rfat-pub-notiz textarea {
                width: 100%; box-sizing: border-box; border: 1px solid #cfd8d2; border-radius: 10px;
                padding: 10px; font: inherit; margin-bottom: 12px;
            }

            /* Ein Knopf, der wie ein Link aussieht: „nicht merken" ist eine
               Nebensache und darf nicht wie ein Hauptknopf wirken. */
            .rfat-pub-linkknopf {
                background: none; border: 0; padding: 0; font: inherit; font-size: inherit;
                color: #1f5a38; text-decoration: underline; cursor: pointer;
            }
            #rfat-meine-termine { margin: 0 0 22px; }
            /* Auf `hidden` allein ist kein Verlass: Manche Themes setzen für
               Absätze und Container ein eigenes `display` und stechen die
               Regel des Browsers damit aus. */
            .rfat-pub-share-hint[hidden], #rfat-meine-termine[hidden] { display: none; }
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
        <?php echo rfat_minify_style_tags(ob_get_clean()); ?>

        <?php echo $notice; ?>

        <?php
        /*
         * Verschieben heißt: erst unten neu buchen, dann den alten Termin
         * absagen - die Buchung läuft also mitten auf dieser Seite. Schlägt
         * sie fehl (Termin inzwischen weg, Kontingent voll), landet die
         * Begründung als ?fehler=... hier. Ohne diese Zeilen fiele sie
         * stillschweigend unter den Tisch, und der Knopf sähe kaputt aus.
         */
        if (!empty($_GET['fehler']) && function_exists('rc_booking_error_html')) {
            echo rc_booking_error_html();
        }
        ?>

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
            <?php
            /*
             * Was dieses Gerät sich gemerkt hat — gefüllt vom Skript unten.
             *
             * Der Auslöser dafür stand im Wohnzimmer: Eine Testbucherin hat
             * den Code nicht notiert und kam nie wieder an ihren Termin. Der
             * Code ist das einzige Credential, wir speichern ja weder Namen
             * noch Konto. Also merkt sich ihn jetzt der Browser, auf dem
             * gebucht wurde — nur dort, und nur für die Person selbst.
             */
            ?>
            <div id="rfat-meine-termine" hidden></div>

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
            /*
             * Rückfragen zuerst — vor Link, Kontakt und allem anderen.
             * Wer hier landet, weil wir etwas wissen wollen, soll die Frage
             * sehen und nicht suchen müssen.
             */
            $offene_frage = rfat_dialog_offene_frage($post->ID);
            $verlauf      = rfat_dialog_lesen($post->ID);
            ?>
            <?php if ($offene_frage): ?>
                <div class="rfat-pub-frage">
                    <p class="rfat-pub-eyebrow">Frage an dich</p>
                    <p class="rfat-pub-frage-text"><?php echo nl2br(esc_html($offene_frage['text'])); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('rfat_pub_antwort_' . $norm_code); ?>
                        <input type="hidden" name="rfat_pub_action" value="antwort" />
                        <input type="hidden" name="rfat_pub_code" value="<?php echo esc_attr($norm_code); ?>" />
                        <label class="rfat-sr" for="rfat-antwort-<?php echo esc_attr($post->ID); ?>">Deine Antwort</label>
                        <textarea id="rfat-antwort-<?php echo esc_attr($post->ID); ?>" name="rfat_pub_antwort"
                                  rows="3" maxlength="1000" placeholder="Deine Antwort"></textarea>
                        <button type="submit" class="btn">Antwort senden</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($verlauf && !$offene_frage): ?>
                <div class="rfat-pub-verlauf">
                    <p class="rfat-pub-eyebrow">Bisher besprochen</p>
                    <?php foreach ($verlauf as $eintrag): ?>
                        <p class="rfat-pub-verlauf-zeile <?php echo $eintrag['von'] === 'gast' ? 'is-gast' : ''; ?>">
                            <strong><?php echo $eintrag['von'] === 'gast' ? 'Du' : 'Wir'; ?>:</strong>
                            <?php echo nl2br(esc_html($eintrag['text'])); ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$offene_frage): ?>
                <?php
                /*
                 * Von sich aus etwas nachtragen — „das Ladegerät bringe ich
                 * mit", „es ist doch das andere Rad". Beim Buchen weiss man
                 * das oft noch nicht, und bisher gab es dafür keinen Weg
                 * ausser einer Mail an uns.
                 *
                 * Zugeklappt: Eine Zeile statt eines weiteren Kastens. Der
                 * Ablauf war schon zu textlastig, das war der Anlass für
                 * 1.22.0 — ein Formular, das die meisten nicht brauchen,
                 * darf hier nicht aufgeschlagen liegen.
                 *
                 * Steht eine Frage offen, fehlt das hier: Dann ist das
                 * Antwortfeld oben der Platz zum Schreiben, und zwei
                 * Eingabefelder nebeneinander verwirren nur.
                 */
                ?>
                <details class="rfat-pub-notiz">
                    <summary>Etwas nachtragen?</summary>
                    <p class="rfat-pub-notiz-hilfe">
                        Zum Beispiel, was genau kaputt ist oder was du mitbringst.
                        Wir sehen es vor deinem Termin.
                    </p>
                    <form method="post">
                        <?php wp_nonce_field('rfat_pub_antwort_' . $norm_code); ?>
                        <input type="hidden" name="rfat_pub_action" value="antwort" />
                        <input type="hidden" name="rfat_pub_code" value="<?php echo esc_attr($norm_code); ?>" />
                        <label class="rfat-sr" for="rfat-notiz-<?php echo esc_attr($post->ID); ?>">Deine Notiz</label>
                        <textarea id="rfat-notiz-<?php echo esc_attr($post->ID); ?>" name="rfat_pub_antwort"
                                  rows="3" maxlength="1000" placeholder="Deine Notiz"></textarea>
                        <button type="submit" class="btn">Absenden</button>
                    </form>
                </details>
            <?php endif; ?>

            <?php
            // Direktlink auf genau diesen Termin — zum Speichern statt Abtippen.
            $share_url = add_query_arg('code', $norm_code, get_permalink());
            ?>
            <div class="rfat-pub-share" data-rfat-code="<?php echo esc_attr($norm_code); ?>">
                <label class="rfat-pub-share-label" for="rfat-share-<?php echo esc_attr($post->ID); ?>">
                    Dein Code <strong><?php echo esc_html($norm_code); ?></strong> — hier ist er als Link:
                </label>
                <div class="rfat-pub-share-row">
                    <input class="rfat-pub-share-url" type="text" readonly
                           id="rfat-share-<?php echo esc_attr($post->ID); ?>"
                           value="<?php echo esc_url($share_url); ?>"
                           onfocus="this.select();" />
                    <button type="button" class="btn rfat-pub-copy"
                            data-target="rfat-share-<?php echo esc_attr($post->ID); ?>">Kopieren</button>
                </div>
                <?php
                /*
                 * Der Hinweis kommt aus dem Skript, nicht von hier: Er stimmt
                 * nur, wenn der Browser den Code auch wirklich behalten hat.
                 * Im privaten Fenster oder mit gesperrtem Speicher tut er das
                 * nicht — dann soll hier auch nichts stehen.
                 */
                ?>
                <p class="rfat-pub-share-hint" id="rfat-gemerkt-<?php echo esc_attr($post->ID); ?>" hidden></p>
            </div>

            <?php if (rfat_get_status($post->ID) === 'angefragt'): ?>
                <div class="rfat-pub-notice" style="background:#fbf2e2;color:#7a5510;">
                    <strong>Noch nicht bestätigt.</strong> Wir sehen uns deine Anfrage an und melden uns.
                </div>
            <?php endif; ?>

            <?php
            $cur_email  = (string) get_post_meta($post->ID, RFAT_EMAIL_META, true);
            $cur_signal = (string) get_post_meta($post->ID, RFAT_SIGNAL_META, true);
            $cur_keep   = get_post_meta($post->ID, RFAT_EMAIL_KEEP_META, true) === '1';
            $hat_kontakt = ($cur_email !== '' || $cur_signal !== '');
            ?>
            <form method="post" class="rfat-pub-mail">
                <?php wp_nonce_field('rfat_pub_email_' . $norm_code); ?>
                <input type="hidden" name="rfat_pub_action" value="email" />
                <input type="hidden" name="rfat_pub_code" value="<?php echo esc_attr($norm_code); ?>" />

                <?php
                /*
                 * Überschrift, kein <label> mehr: Sie steht jetzt über zwei
                 * Feldern. Als Beschriftung zeigte sie auf das E-Mail-Feld
                 * und sagte Screenreadern damit das Falsche — beide Felder
                 * haben ihre eigene.
                 */
                ?>
                <p class="rfat-pub-mail-label">
                    Wie wir dich erreichen <span class="rfat-pub-optional">freiwillig</span>
                </p>
                <p class="rfat-pub-mail-intro">
                    Freiwillig – ohne Angabe ändert sich nichts. Damit melden wir uns,
                    wenn es etwas zu klären gibt oder dein Termin bestätigt ist.
                </p>

                <label class="rfat-pub-feld-label" for="rfat-mail-<?php echo esc_attr($post->ID); ?>">E-Mail</label>
                <input class="rfat-pub-code-input rfat-pub-mail-input" type="email"
                       id="rfat-mail-<?php echo esc_attr($post->ID); ?>"
                       name="rfat_pub_email" placeholder="dein@beispiel.de"
                       value="<?php echo esc_attr($cur_email); ?>" />

                <label class="rfat-pub-feld-label" for="rfat-signal-<?php echo esc_attr($post->ID); ?>">Signal-Link oder Benutzername</label>
                <input class="rfat-pub-code-input rfat-pub-mail-input" type="text"
                       id="rfat-signal-<?php echo esc_attr($post->ID); ?>"
                       name="rfat_pub_signal" placeholder="https://signal.me/#eu/… oder maxmuster.42"
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       value="<?php echo esc_attr($cur_signal); ?>" />
                <p class="rfat-pub-feldhinweis">
                    Am besten der <strong>Signal-Link</strong> (in Signal:
                    <em>Einstellungen &rarr; Profil &rarr; Benutzername &rarr; Link kopieren</em>).
                    Der Benutzername geht auch. Deine <strong>Telefonnummer brauchen wir nicht</strong>.
                </p>

                <label class="rfat-pub-keep">
                    <input type="checkbox" name="rfat_pub_email_keep" value="1"
                           <?php checked($cur_keep); ?> />
                    <span>
                        Auch <strong>nach dem Termin</strong> gespeichert lassen.
                        <em>Ohne Haken werden die Angaben danach gelöscht.</em>
                    </span>
                </label>

                <div class="rfat-pub-mail-actions">
                    <button type="submit" class="btn"><?php echo $hat_kontakt ? 'Änderung speichern' : 'Kontakt speichern'; ?></button>
                    <?php if ($hat_kontakt): ?>
                        <span class="rfat-pub-mail-state">
                            Gespeichert:
                            <?php if ($cur_email !== ''): ?><strong><?php echo esc_html($cur_email); ?></strong><?php endif; ?>
                            <?php if ($cur_email !== '' && $cur_signal !== ''): ?>und<?php endif; ?>
                            <?php if ($cur_signal !== ''): ?><strong>Signal: <?php
                                // Der Link ist 70 Zeichen lang und sagt nichts — sein
                                // Vorhandensein ist die ganze Auskunft, die hier zaehlt.
                                echo rfat_signal_ist_link($cur_signal) ? 'dein Link' : esc_html($cur_signal);
                            ?></strong><?php endif; ?>
                            —
                            <?php echo $cur_keep
                                ? 'bleibt gespeichert, bis du es löschst'
                                : 'wird nach dem Termin gelöscht'; ?>.
                            Zum Löschen einfach das jeweilige Feld leeren und speichern.
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
        /*
         * Dieses Gerät merkt sich die Buchungscodes, die hier angezeigt
         * wurden — nur im Browser, nichts davon geht an den Server.
         *
         * Grund: Der Code ist das einzige Credential. Wer ihn nicht
         * notiert, kommt nie wieder an seinen Termin. Genau das ist beim
         * Ausprobieren passiert.
         *
         * Alles in try/catch: Im privaten Fenster und bei gesperrtem
         * Speicher wirft schon der Zugriff. Dann bleibt es eben beim
         * Abtippen des Codes — kaputt gehen darf die Seite davon nicht.
         */
        (function () {
            var SCHLUESSEL = 'rfat_codes';
            var MAX = 5;

            function lesen() {
                try {
                    var roh = window.localStorage.getItem(SCHLUESSEL);
                    var liste = roh ? JSON.parse(roh) : [];
                    return Array.isArray(liste) ? liste.filter(function (c) {
                        return typeof c === 'string' && /^RC-[A-Z0-9]{3,10}$/.test(c);
                    }) : [];
                } catch (e) { return []; }
            }

            function schreiben(liste) {
                try {
                    window.localStorage.setItem(SCHLUESSEL, JSON.stringify(liste.slice(0, MAX)));
                    return true;
                } catch (e) { return false; }
            }

            function merken(code) {
                var liste = lesen().filter(function (c) { return c !== code; });
                liste.unshift(code);
                return schreiben(liste);
            }

            function vergessen() {
                try { window.localStorage.removeItem(SCHLUESSEL); } catch (e) { /* dann eben nicht */ }
            }

            /* Angezeigter Termin: merken und sagen, dass gemerkt wurde. */
            var karte = document.querySelector('.rfat-pub-share[data-rfat-code]');
            if (karte) {
                var code = karte.getAttribute('data-rfat-code');
                var hinweis = karte.querySelector('.rfat-pub-share-hint');
                if (code && merken(code) && hinweis) {
                    hinweis.textContent = 'Dieses Gerät merkt sich den Termin — beim nächsten Besuch steht er hier oben. ';
                    var weg = document.createElement('button');
                    weg.type = 'button';
                    weg.className = 'rfat-pub-linkknopf';
                    weg.textContent = 'nicht merken';
                    weg.addEventListener('click', function () {
                        vergessen();
                        hinweis.textContent = 'Gut, dieses Gerät merkt sich nichts mehr. Bewahre deinen Code auf.';
                    });
                    hinweis.appendChild(weg);
                    hinweis.hidden = false;
                }
            }

            /* Kein Termin angezeigt: anbieten, was gemerkt ist. */
            var kasten = document.getElementById('rfat-meine-termine');
            if (kasten) {
                var gemerkt = lesen();
                if (gemerkt.length) {
                    var titel = document.createElement('p');
                    titel.className = 'rfat-pub-eyebrow';
                    titel.textContent = gemerkt.length > 1 ? 'Deine Termine auf diesem Gerät' : 'Dein Termin auf diesem Gerät';
                    kasten.appendChild(titel);

                    gemerkt.forEach(function (c) {
                        var a = document.createElement('a');
                        a.className = 'btn';
                        a.style.marginRight = '8px';
                        a.href = '?code=' + encodeURIComponent(c);
                        a.textContent = c + ' anzeigen';
                        kasten.appendChild(a);
                    });

                    var oder = document.createElement('p');
                    oder.className = 'rfat-pub-share-hint';
                    oder.textContent = 'Oder gib unten einen anderen Code ein. ';
                    var weg2 = document.createElement('button');
                    weg2.type = 'button';
                    weg2.className = 'rfat-pub-linkknopf';
                    weg2.textContent = 'gemerkte Termine löschen';
                    weg2.addEventListener('click', function () {
                        vergessen();
                        kasten.hidden = true;
                    });
                    oder.appendChild(weg2);
                    kasten.appendChild(oder);
                    kasten.hidden = false;
                }
            }
        })();

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
    <?php ob_start(); ?>
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

        /* ============ Eigenständiges Handy-Menü (siehe wp_footer unten) ============
         *
         * Der Knopf sitzt in einer eigenen Leiste am oberen Rand. Beim
         * Herunterscrollen schrumpft sie: oben auf der Seite ist Platz für
         * eine große Trefferfläche, weiter unten soll sie möglichst wenig
         * vom Inhalt verdecken.
         */
        /*
         * Kein Balken hinter dem Knopf.
         *
         * Ein Zwischenstand hatte hier eine weisse, halbdurchsichtige
         * Leiste. Beim Scrollen zog sie eine harte Kante quer durch den
         * Text — Zeilen wurden mittendrin abgeschnitten, darueber lief der
         * Inhalt weiter. Das sah nach Fehler aus, und es war auch einer.
         *
         * Der Knopf traegt seinen eigenen Schatten und steht damit auf
         * jedem Untergrund fuer sich. Ein Balken bringt nichts ausser der
         * Kante.
         */
        .rfat-topbar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 99998;
            justify-content: flex-end;
            align-items: center;
            padding: 12px max(12px, env(safe-area-inset-right))
                     12px max(12px, env(safe-area-inset-left));
            /* Die Leiste selbst faengt keine Klicks ab - nur ihr Knopf. */
            pointer-events: none;
            transition: padding .2s ease, top .2s ease;
        }
        .rfat-topbar.is-small {
            padding-top: 8px;
            padding-bottom: 8px;
        }
        /*
         * Der Knopf war weiss mit dünnem Rand — auf dem ohnehin weissen
         * Seitenkopf ging er darin unter. Jetzt im Grün der Seite, damit
         * er als das erkennbar ist, was er ist: die einzige Navigation.
         */
        .rfat-nav-open {
            display: flex;
            pointer-events: auto;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            padding: 0;
            border: 0;
            border-radius: 19px;
            background: #2f7d4f;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(31, 90, 56, .30);
            transition: width .2s ease, height .2s ease, border-radius .2s ease,
                        box-shadow .2s ease, transform .12s ease, background-color .2s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .rfat-nav-open:active { transform: scale(.93); }
        .rfat-nav-open:focus-visible {
            outline: 3px solid #1f5a38;
            outline-offset: 3px;
        }
        .rfat-topbar.is-small .rfat-nav-open {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(31, 90, 56, .26);
        }

        /*
         * Drei einzelne Striche statt eines SVG-Pfades: nur so lassen sie
         * sich beim Öffnen zum Kreuz zusammenlegen. Der mittlere ist etwas
         * kürzer — das nimmt dem Symbol die Strenge.
         */
        .rfat-burger {
            position: relative;
            display: block;
            width: 26px;
            height: 18px;
            transition: width .2s ease, height .2s ease;
        }
        .rfat-topbar.is-small .rfat-burger { width: 19px; height: 13px; }
        .rfat-burger span {
            position: absolute;
            left: 0;
            height: 2.5px;
            width: 100%;
            border-radius: 2px;
            background: #fff;
            transition: transform .26s cubic-bezier(.2, .7, .3, 1),
                        opacity .16s ease, width .2s ease;
        }
        .rfat-burger span:nth-child(1) { top: 0; }
        .rfat-burger span:nth-child(2) { top: 50%; margin-top: -1.25px; width: 68%; }
        .rfat-burger span:nth-child(3) { bottom: 0; }
        /* Beim Öffnen zum Kreuz — sichtbar, solange das Menü einblendet. */
        .rfat-nav-open[aria-expanded="true"] .rfat-burger span:nth-child(1) {
            transform: translateY(7.75px) rotate(45deg);
        }
        .rfat-nav-open[aria-expanded="true"] .rfat-burger span:nth-child(2) {
            opacity: 0;
            width: 100%;
        }
        .rfat-nav-open[aria-expanded="true"] .rfat-burger span:nth-child(3) {
            transform: translateY(-7.75px) rotate(-45deg);
        }
        @media (prefers-reduced-motion: reduce) {
            .rfat-nav-open, .rfat-burger, .rfat-burger span, .rfat-topbar {
                transition: none;
            }
        }
        /*
         * Die Adminleiste sehen nur eingeloggte Team-Mitglieder, und sie
         * verhaelt sich je nach Fenster anders: Am Rechner ist sie fest
         * verankert (32px), auf dem Handy scrollt sie mit weg. Ein fester
         * Versatz von 46px liess deshalb beim Scrollen oben eine Luecke
         * offen, durch die der Seiteninhalt lief.
         *
         * Also: auf dem Handy nur solange ausweichen, wie noch nicht
         * gescrollt wurde.
         */
        body.admin-bar .rfat-topbar {
            top: 46px;
        }
        body.admin-bar .rfat-topbar.is-small {
            top: 0;
        }
        @media screen and (min-width: 783px) {
            body.admin-bar .rfat-topbar,
            body.admin-bar .rfat-topbar.is-small {
                top: 32px;
            }
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

        /*
         * Auf dem Handy soll NUR der Hamburger stehen.
         *
         * Das Theme legt seine Menüpunkte darüber hinaus als Liste in den
         * Kopf — untereinander, weil sie nebeneinander nicht passen. Das
         * frisst den halben ersten Bildschirm und doppelt unser Menü.
         *
         * Ausgeblendet wird nur, wenn unser Menü tatsächlich läuft: Die
         * Klasse rfat-nav-fallback setzt das Skript erst, nachdem es
         * festgestellt hat, dass das Theme kein eigenes Overlay mitbringt.
         * Ohne diese Bedingung stünde jemand ohne JavaScript vor einer
         * Seite völlig ohne Navigation.
         */
        /*
         * Die Leiste ist die Navigation der Seite — auf jedem Fenster,
         * nicht nur auf dem Handy. Das Menü des Themes wird beim Rendern
         * entfernt, also gibt es sonst keins mehr.
         */
        .rfat-topbar:not([hidden]) {
            display: flex;
        }

        /* ============ Handy: Inhalt vom Rand lösen ============
         *
         * Block-Themes steuern den Seitenrand über zwei CSS-Variablen aus
         * theme.json. Dieses Theme setzt beide auf 0 — am Rechner fällt das
         * nicht auf, weil der Inhalt ohnehin auf 820px zentriert steht; auf
         * dem Handy klebt dagegen jede Zeile an der Kante.
         *
         * Über die Variablen und nicht über eigene Regeln: Nur so bleiben
         * ganzflächige Hintergründe randlos (`alignfull`, hier der Verlauf
         * hinter dem Hero), während der Text einrückt. Das ist der
         * offizielle Mechanismus der Block-Themes.
         *
         * Diese Regeln standen schon einmal hier und sind in 1.14.0
         * versehentlich mit dem Theme-Menü-CSS mitgegangen („Theme-Menue
         * wirklich entfernen statt verstecken"). Seitdem klebte der Text.
         * Deshalb der Hinweis: Sie haben mit der Navigation nichts zu tun
         * und gehören beim nächsten Aufräumen dort nicht dazu.
         */
        @media (max-width: 782px) {
            :root,
            body {
                --wp--style--root--padding-left: 20px !important;
                --wp--style--root--padding-right: 20px !important;
            }

            /*
             * Bewusst nur die Variablen und kein zusaetzliches Polster fuer
             * einzelne Container: Gemessen an allen sieben Seiten reichen
             * sie. Ein breites Auffangnetz (`.entry-content`, Layout-Kinder)
             * legte sich auf den Seiten, die ohnehin am Global-Padding
             * haengen, obendrauf — der Text stand dann 40 statt 20 Pixel vom
             * Rand, die Karten der Startseite sichtbar schmaler.
             */

            /* Kein doppelter Abstand, wo wir schon selbst polstern */
            .rfat-pub-wrap {
                padding-left: 0;
                padding-right: 0;
            }

            /* Überschriften schlanker — der Hero kann h1 oder h2 sein */
            h1 { font-size: clamp(28px, 8.5vw, 36px); line-height: 1.12; }
            h2 { font-size: clamp(24px, 7vw, 30px); line-height: 1.15; }

            /*
             * Lange Komposita umbrechen lassen. „Verbraucherstreitbeilegung"
             * im Impressum ist als h2 auf einem 390-Pixel-Display 427 Pixel
             * breit — das Wort lief bisher rechts aus dem Bild, unsichtbar,
             * weil `overflow-x: hidden` auf dem body den Rest abschneidet.
             * `hyphens` trennt nach den Regeln der Seitensprache (lang="de"),
             * `overflow-wrap` ist die Notbremse fuer alles ohne Trennstelle.
             */
            h1, h2, h3 {
                overflow-wrap: break-word;
                hyphens: auto;
            }
        }

        @media (max-width: 600px) {

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

        /* Eingeloggt verdeckt die Adminleiste sonst den oberen Rand */

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
    <?php echo rfat_minify_style_tags(ob_get_clean()); ?>
    <?php
}, 999);

/*

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
 * Die fertige Nachricht an den Gast — zum Kopieren und in Signal einfügen.
 *
 * Signal-Links können **keinen** Text vorbelegen; ein Gegenstück zu
 * WhatsApps `?text=` gibt es nicht. Näher als „Text kopieren und Chat
 * öffnen" kommt man deshalb nicht heran: einmal drücken, in Signal
 * einfügen, senden.
 *
 * Der Link auf die Terminseite steht bewusst drin. Damit hat der Gast
 * seinen Zugang in der Hand, auch wenn er den Code längst verlegt hat —
 * genau der Fall, der das hier ausgelöst hat.
 */
function rfat_signal_nachricht($post_id) {
    $analysis = rfat_analyse_booking($post_id);
    $ts       = $analysis['datetime'] ? $analysis['datetime']->getTimestamp() : null;
    $code     = rfat_normalize_code($analysis['code']['value'] ?? '');
    $link     = home_url('/termin-abrufen/?code=' . rawurlencode($code));
    $status   = rfat_get_status($post_id);

    $zeilen = ['Hallo! Hier ist ' . get_bloginfo('name') . '.'];

    if ($ts) {
        $zeilen[] = ($status === 'bestaetigt' ? 'Dein Termin steht: ' : 'Es geht um deinen Termin am ')
                  . wp_date('l, d.m.Y', $ts) . ' um ' . wp_date('H:i', $ts) . ' Uhr.';
    }

    $frage = rfat_dialog_offene_frage($post_id);
    if ($frage) {
        $zeilen[] = '';
        $zeilen[] = $frage['text'];
    }

    $zeilen[] = '';
    $zeilen[] = 'Deinen Termin ansehen, verschieben oder absagen (Code ' . $code . '):';
    $zeilen[] = $link;

    return implode("\n", $zeilen);
}

/**
 * Das Team informieren, wenn der Gast geschrieben hat.
 *
 * Ohne diese Mail bliebe es in der Übersicht liegen, bis jemand von sich
 * aus nachsieht — und wer eine Frage stellt, sieht nicht dauernd nach.
 * Bei einer Notiz ohne vorherige Frage wüsste es sonst überhaupt niemand.
 *
 * @param bool $war_frage Ob eine Rückfrage offen war. Entscheidet nur über
 *                        den Wortlaut: „Antwort" oder „Notiz".
 */
function rfat_notify_antwort($post_id, $war_frage = true) {
    $to = rfat_notify_recipients();
    if (!$to) {
        return;
    }
    $verlauf = rfat_dialog_lesen($post_id);
    $letzter = $verlauf ? end($verlauf) : null;
    if (!$letzter || $letzter['von'] !== 'gast') {
        return;
    }

    $wort = $war_frage ? 'Antwort' : 'Notiz';
    $body = 'Zu einer Buchung ist eine ' . ($war_frage ? 'Antwort' : 'Notiz') . " eingegangen.\n\n"
          . rfat_booking_summary($post_id) . "\n\n"
          . $wort . ":\n  " . str_replace("\n", "\n  ", $letzter['text']) . "\n\n"
          . "In der Übersicht ansehen:\n"
          . admin_url('edit.php?post_type=rc_booking&page=rfat-overview') . "\n\n"
          . "-- \nAutomatische Nachricht von " . get_bloginfo('name');

    rfat_send_logged($to, sprintf('[%s] %s zu einer Buchung', get_bloginfo('name'), $wort), $body);
}

/**
 * Den Gast über eine neue Rückfrage informieren — wieder nur, wenn er
 * freiwillig eine Adresse hinterlassen hat.
 *
 * Hat er stattdessen Signal hinterlegt, schreibt ihn das Team dort an;
 * dafür gibt es in der Übersicht den fertigen Nachrichtentext.
 */
function rfat_notify_frage($post_id, $frage) {
    $to = (string) get_post_meta($post_id, RFAT_EMAIL_META, true);
    if ($to === '' || !is_email($to)) {
        return;
    }
    $analysis = rfat_analyse_booking($post_id);
    $code     = rfat_normalize_code($analysis['code']['value'] ?? '');
    $ts       = $analysis['datetime'] ? $analysis['datetime']->getTimestamp() : null;
    $manage   = home_url('/termin-abrufen/?code=' . rawurlencode($code));

    $body = "Guten Tag,\n\nwir haben eine Frage zu deinem Termin"
          . ($ts ? ' am ' . wp_date('d.m.Y', $ts) . ' um ' . wp_date('H:i', $ts) . ' Uhr' : '')
          . ":\n\n  " . str_replace("\n", "\n  ", $frage)
          . "\n\nAntworten kannst du hier — dort steht auch dein Termin:\n" . $manage;

    $body .= "\n\nKeine Nachrichten mehr? Hier abmelden:\n"
          . home_url('/termin-abrufen/?abmelden=' . rawurlencode($code));

    rfat_send_logged($to, 'Kurze Frage zu deinem Reparaturtermin', $body);
}

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
            . "\n\nTermin ansehen, etwas nachtragen, verschieben oder absagen:\n" . $manage;
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
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    /*
     * Bewusst NICHT get_plugin_data(): Das laedt wp-admin/includes/plugin.php
     * ins Frontend und liest anschliessend die ganze Datei - inzwischen weit
     * ueber 100 KB - fuer eine einzige Zeile. Der Statusvermerk im Seitenfuss
     * steht auf jeder Seite, also lief das bei jedem Aufruf.
     *
     * Der Plugin-Kopf steht in den ersten Zeilen. Mehr als 8 KB zu lesen ist
     * nie noetig; genau diese Grenze zieht WordPress intern auch.
     */
    $handle = @fopen(__FILE__, 'r');
    if (!$handle) {
        return $version = '';
    }
    $head = fread($handle, 8192);
    fclose($handle);

    $version = preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', (string) $head, $m)
        ? trim($m[1])
        : '';

    return $version;
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

/*
 * Hier stand das E-Mail-Feld fuer die Zwischenseite nach der Buchung.
 * Seit die Buchung direkt auf /termin-abrufen/ fuehrt, gibt es die
 * Zwischenseite nicht mehr — und dort steht dasselbe Feld ohnehin, mit
 * Kalender-Knopf, Verschieben und Absagen daneben. Zwei Formulare fuer
 * dieselbe Sache waren einmal zu viel.
 */

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

    /*
     * Die freiwillige Problembeschreibung. Bis 1.10.0 landete sie nur als
     * namenloses Rohfeld in der Übersicht — dabei ist sie das Einzige, was
     * vor dem Termin verrät, was überhaupt mitgebracht wird.
     */
    $note = trim((string) get_post_meta($post_id, '_rc_note', true));
    if ($note !== '') {
        $lines[] = '';
        $lines[] = 'Problem:';
        $lines[] = '  ' . str_replace("\n", "\n  ", $note);
    }

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
 * Eine Fehlermeldung von wp_mail in Klartext übersetzen.
 *
 * "Die E-Mail-Funktion konnte nicht instanziiert werden" ist PHPMailers
 * "Could not instantiate mail function" — für Nichtentwickler unlesbar,
 * dabei ist die Aussage eindeutig und die Lösung immer dieselbe. Wer die
 * Meldung nicht deuten kann, sucht sonst tagelang beim falschen Verdächtigen.
 *
 * @return string Erklärung, oder leer wenn wir die Meldung nicht kennen.
 */
function rfat_explain_mail_error($message) {
    $m = strtolower((string) $message);

    if (strpos($m, 'instanziiert') !== false || strpos($m, 'instantiate') !== false) {
        return 'Der Server hat den Versand gar nicht erst versucht: Die PHP-Funktion '
             . 'mail() ist bei deinem Hoster abgeschaltet oder nicht eingerichtet. '
             . 'Das ist bei günstigem Hosting die Regel. Daran lässt sich vom '
             . 'Plugin aus nichts ändern — nötig ist ein SMTP-Versand, also ein '
             . 'echtes Postfach, über das WordPress verschickt.';
    }
    if (strpos($m, 'smtp') !== false && (strpos($m, 'auth') !== false || strpos($m, 'password') !== false)) {
        return 'Der SMTP-Zugang wurde abgelehnt. Benutzername oder Passwort stimmen '
             . 'nicht, oder der Anbieter verlangt ein eigenes App-Passwort statt des '
             . 'normalen Kennworts.';
    }
    if (strpos($m, 'connect') !== false || strpos($m, 'verbindung') !== false) {
        return 'Der Mailserver war nicht erreichbar. Oft blockiert der Hoster den '
             . 'ausgehenden Port; Port 587 statt 465 hilft in vielen Fällen.';
    }
    return '';
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
 * Kontaktdaten entfernen, deren Termin vorbei ist und für die keine
 * weitere Speicherung erlaubt wurde — E-Mail wie Signal-Benutzername.
 *
 * Der Name bleibt, obwohl seit 1.19.0 mehr als E-Mails darunterfallen:
 * Er ist zugleich der Name des eingeplanten Cron-Hakens (siehe
 * RFAT_CLEANUP_HOOK) und steckt damit in der Datenbank. Ein Umbenennen
 * würde den bestehenden Termin ins Leere laufen lassen.
 *
 * @return int Anzahl der Buchungen, die aufgeräumt wurden.
 */
function rfat_cleanup_emails() {
    $posts = get_posts([
        'post_type'        => 'rc_booking',
        'posts_per_page'   => 200,
        'post_status'      => 'any',
        'fields'           => 'ids',
        'meta_query'       => [
            'relation' => 'OR',
            [
                'key'     => RFAT_EMAIL_META,
                'compare' => 'EXISTS',
            ],
            /*
             * Seit 1.19.0 darf auch ein Signal-Benutzername hinterlegt
             * sein — ohne diesen zweiten Zweig bliebe er liegen, sobald
             * jemand nur ihn und keine E-Mail eingetragen hat.
             */
            [
                'key'     => RFAT_SIGNAL_META,
                'compare' => 'EXISTS',
            ],
            [
                'key'     => RFAT_DIALOG_META,
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
        $bleiben = get_post_meta($post_id, RFAT_EMAIL_KEEP_META, true) === '1';

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

        $etwas = false;

        /*
         * Der Frage-und-Antwort-Verlauf geht immer — auch wenn die
         * Kontaktdaten bleiben dürfen. Das Häkchen ist eine Entscheidung
         * über die Erreichbarkeit, nicht darüber, wie lange Notizen zu
         * einem längst vergangenen Termin herumliegen.
         */
        if (delete_post_meta($post_id, RFAT_DIALOG_META)) {
            $etwas = true;
        }

        // Kontaktdaten nur, wenn nicht ausdrücklich erlaubt.
        if (!$bleiben) {
            delete_post_meta($post_id, RFAT_EMAIL_META);
            delete_post_meta($post_id, RFAT_SIGNAL_META);
            delete_post_meta($post_id, RFAT_EMAIL_KEEP_META);
            $etwas = true;
        }

        if ($etwas) {
            $removed++;
        }
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
/* =========================================================================
 * DAS MENÜ DES THEMES SCHON BEIM AUSLIEFERN MARKIEREN
 *
 * Bis 1.13.1 hat das ein Skript im Seitenfuß erledigt. Das funktionierte,
 * war aber sichtbar: Die vier Knöpfe standen kurz da und verschwanden dann
 * — das HTML war längst gezeichnet, bevor das Skript überhaupt lief.
 *
 * Hier passiert es beim Rendern, also bevor irgendetwas beim Besucher
 * ankommt. Erkannt wird wie im Skript über Adresse UND Beschriftung der
 * Menüpunkte, nicht über geratene Klassennamen.
 *
 * Der Block wird ersatzlos entfernt, nicht bloß ausgeblendet: Markup
 * auszuliefern, das anschließend versteckt wird, ist doppelte Arbeit für
 * jeden Besucher — er lädt es, der Browser baut es auf, und dann fällt es
 * weg. Weil damit auch am Rechner keine Menüleiste mehr käme, ist unser
 * Menü nicht länger auf schmale Fenster beschränkt: Es ist ab jetzt die
 * Navigation, überall.
 * ========================================================================= */

/**
 * Pfadteil einer Adresse, ohne Schrägstrich am Ende — wie im Skript.
 */
function rfat_url_pfad($url) {
    $pfad = wp_parse_url((string) $url, PHP_URL_PATH);
    if (!is_string($pfad)) {
        return null;
    }
    $pfad = rtrim($pfad, '/');
    return $pfad === '' ? '/' : $pfad;
}

function rfat_text_normalisieren($text) {
    $text = wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8'));
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)));
}

/**
 * Pfad => Liste der Beschriftungen, unter denen dieser Punkt im Menü steht.
 */
function rfat_menu_ziele() {
    static $ziele = null;
    if ($ziele !== null) {
        return $ziele;
    }
    $ziele = [];
    foreach (rfat_get_menu_items() as $item) {
        $pfad = rfat_url_pfad($item['url']);
        if ($pfad === null) {
            continue;
        }
        $ziele[$pfad][] = rfat_text_normalisieren($item['label']);
    }
    return $ziele;
}

add_filter('render_block', function ($content, $block) {
    if (is_admin() || is_feed() || !empty($GLOBALS['rfat_nav_markiert'])) {
        return $content;
    }
    // Billiger Vorabtest, damit die Masse der Blöcke sofort durch ist.
    if ($content === '' || strpos($content, '<a ') === false) {
        return $content;
    }

    $ziele = rfat_menu_ziele();
    if (count($ziele) < 2) {
        return $content;
    }

    if (!preg_match_all('/<a\b[^>]*href=([\"\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $treffer, PREG_SET_ORDER)) {
        return $content;
    }

    $passend = 0;
    $linktext = 0;
    foreach ($treffer as $t) {
        // Bildlinks sind nie Menüpunkte — das ist das Logo.
        if (preg_match('/<(img|svg|picture)\b/i', $t[3])) {
            continue;
        }
        $pfad = rfat_url_pfad($t[2]);
        $text = rfat_text_normalisieren($t[3]);
        if ($pfad !== null && isset($ziele[$pfad]) && in_array($text, $ziele[$pfad], true)) {
            $passend++;
            $linktext += mb_strlen($text);
        }
    }

    // Unter zwei Treffern ist die Sache nicht eindeutig genug.
    if ($passend < 2) {
        return $content;
    }

    /*
     * Dieselbe Sicherung wie im Skript: Steht im Block merklich mehr als
     * die Menüpunkte, steckt vermutlich der Seitentitel mit drin. Dann
     * lieber nichts anfassen und dem Skript im Fuß den Vortritt lassen —
     * das kann einzelne Links ausblenden, ein Block nicht.
     */
    $gesamt = mb_strlen(rfat_text_normalisieren($content));
    if ($gesamt > $linktext * 2 + 40) {
        return $content;
    }

    $GLOBALS['rfat_nav_markiert'] = true;
    return '';
}, 10, 2);

/*
 * Hier standen zwei Filter, die das Menü des Themes aufgehübscht haben:
 * einer erzwang dessen Hamburger-Modus, der andere hängte "Termin abrufen"
 * hinten an. Beides ist gegenstandslos, seit das Menü beim Rendern
 * entfernt wird — es wäre Arbeit an etwas, das gleich darauf verschwindet.
 * "Termin abrufen" steht ohnehin in unserem eigenen Menü.
 */

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
    <div class="rfat-topbar" id="rfat-topbar" hidden>
        <button type="button" class="rfat-nav-open" id="rfat-nav-open"
                aria-label="Menü öffnen" aria-expanded="false" aria-controls="rfat-nav-overlay">
            <span class="rfat-burger" aria-hidden="true"><span></span><span></span><span></span></span>
        </button>
    </div>

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
        var topbar = document.getElementById('rfat-topbar');
        var overlay = document.getElementById('rfat-nav-overlay');
        var closeBtn = document.getElementById('rfat-nav-close');
        if (!openBtn || !topbar || !overlay || !closeBtn) { return; }

        /*
         * Bringt das Theme schon ein eigenes Handy-Menü mit? Dann unseres
         * restlos entfernen, damit nicht zwei Hamburger übereinanderliegen.
         */
        /*
         * Nur wenn das Theme wirklich einen sichtbaren eigenen Hamburger
         * zeigt. Auf "vorhanden" allein zu prüfen genügt nicht mehr, seit
         * unsere Leiste in jedem Fenster steht: Themes verstecken ihren
         * Knopf am Rechner per CSS, und wir hätten unser Menü dann auch
         * dort weggeräumt — die Seite stünde ohne jede Navigation da.
         */
        var themeToggle = document.querySelector('.wp-block-navigation__responsive-container-open');
        if (themeToggle && themeToggle.offsetParent !== null) {
            document.documentElement.classList.add('rfat-nav-core');
            topbar.parentNode.removeChild(topbar);
            overlay.parentNode.removeChild(overlay);
            return;   // .rfat-nav-core blendet das Theme-Menü wieder ein
        }
        document.documentElement.classList.add('rfat-nav-fallback');
        topbar.hidden = false;

<?php /* Nur ausliefern, wenn beim Rendern nichts entfernt wurde. */
       if (empty($GLOBALS['rfat_nav_markiert'])) : ?>
        /*
         * ---- Auffangnetz: Menüpunkte des Themes entfernen ----
         *
         * Zweimal stand hier ein geratener CSS-Selektor
         * (`header nav.wp-block-navigation`), zweimal ohne Wirkung: Das
         * Markup des Themes kennen wir nicht, und von hier aus lässt es
         * sich nicht nachsehen. Was wir sicher kennen, sind die Adressen
         * der Menüpunkte — sie stehen in unserem eigenen Menü daneben.
         *
         * Also über die Adressen suchen statt über Klassennamen.
         */
        function pfad(href) {
            if (!href) { return null; }
            /* location.href als Bezug, nicht location.origin: bei manchen
             * Kontexten ist origin "null", und dann wirft der Konstruktor -
             * die Erkennung fiele still aus. */
            try { return new URL(href, location.href).pathname.replace(/\/+$/, '') || '/'; }
            catch (e) { return null; }
        }

        function beschriftung(el) {
            return (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }

        function themeMenuEntfernen() {
            /*
             * Adresse UND Beschriftung muessen passen.
             *
             * Die Adresse allein reicht nicht: Auf "/" zeigt in fast jedem
             * Theme auch das Logo und der Seitentitel. Nach Adresse allein
             * gesucht, verschwand im Test der komplette Seitenkopf - Logo
             * und Titel mit. Die Beschriftung unterscheidet die beiden
             * sauber: Der Menuepunkt heisst "Start", das Logo traegt den
             * Seitennamen oder gar keinen Text.
             */
            var gesucht = {};
            var eigene = overlay.querySelectorAll('.rfat-nav-link');
            for (var i = 0; i < eigene.length; i++) {
                var p = pfad(eigene[i].getAttribute('href'));
                if (!p) { continue; }
                if (!gesucht[p]) { gesucht[p] = []; }
                gesucht[p].push(beschriftung(eigene[i]));
            }

            // Nur oberhalb des Inhalts suchen - Links im Text sollen bleiben.
            var inhalt = document.querySelector('main, .wp-site-blocks > main, #content, #main');
            var treffer = [];
            var alle = document.querySelectorAll('a[href]');
            for (var j = 0; j < alle.length; j++) {
                var a = alle[j];
                if (topbar.contains(a) || overlay.contains(a)) { continue; }
                if (a.closest('#wpadminbar')) { continue; }
                if (inhalt && (inhalt.contains(a) ||
                    (inhalt.compareDocumentPosition(a) & Node.DOCUMENT_POSITION_FOLLOWING))) { continue; }
                // Bildlinks sind nie Menuepunkte - das ist das Logo.
                if (a.querySelector('img, svg, picture')) { continue; }
                var pf = pfad(a.getAttribute('href'));
                if (pf && gesucht[pf] && gesucht[pf].indexOf(beschriftung(a)) !== -1) {
                    treffer.push(a);
                }
            }

            // Unter zwei Treffern ist die Sache nicht eindeutig genug.
            if (treffer.length < 2) { return { anzahl: treffer.length, art: 'zu wenige' }; }

            // Kleinster gemeinsamer Vorfahr aller Treffer.
            var huelle = treffer[0].parentNode;
            for (var k = 1; k < treffer.length; k++) {
                while (huelle && !huelle.contains(treffer[k])) { huelle = huelle.parentNode; }
            }
            if (!huelle || huelle === document.body ||
                huelle === document.documentElement) {
                return entferneEinzeln(treffer, 'kein brauchbarer Container');
            }

            /*
             * Sicherung: Der Container darf im Wesentlichen nur die
             * Menüpunkte enthalten. Steckt der Seitentitel mit drin, wäre
             * er sonst gleich mit weg - dann lieber nur die Links selbst.
             */
            var summe = 0;
            for (var m = 0; m < treffer.length; m++) {
                summe += (treffer[m].textContent || '').trim().length;
            }
            var gesamt = (huelle.textContent || '').trim().length;
            if (gesamt > summe * 2 + 40) {
                return entferneEinzeln(treffer, 'Container enthielt mehr als das Menü');
            }

            huelle.parentNode.removeChild(huelle);
            return {
                anzahl: treffer.length,
                art: 'Container',
                knoten: huelle.tagName.toLowerCase() +
                        (huelle.className ? '.' + String(huelle.className).trim().split(/\s+/).join('.') : '')
            };
        }

        function entferneEinzeln(treffer, grund) {
            for (var i = 0; i < treffer.length; i++) {
                var el = treffer[i];
                // Bis zum Listenpunkt hoch, falls es einer ist.
                var li = el.closest('li');
                var weg = li || el;
                weg.parentNode.removeChild(weg);
            }
            return { anzahl: treffer.length, art: 'einzeln', grund: grund };
        }

        /*
         * Dieser ganze Abschnitt steht nur dann in der Seite, wenn der
         * Server beim Rendern nichts gefunden hat — sonst waere er
         * Ballast, den jeder Besucher mitlaedt, ohne dass er etwas tut.
         */
        var versteckt = themeMenuEntfernen();

        /*
         * Nur für uns, und nur auf ausdrücklichen Wunsch: Wenn oben doch
         * noch etwas stehen bleibt, sagt eine Zeile mit ?rfat_diag=1 in der
         * Adresse, was gefunden wurde. Das erspart die Raterei von vorher.
         */
        if (<?php echo (current_user_can('manage_options') && !empty($_GET['rfat_diag'])) ? 'true' : 'false'; ?>) {
            var d = document.createElement('div');
            d.style.cssText = 'position:fixed;left:8px;bottom:8px;z-index:99999;max-width:92vw;' +
                'background:#1c2a22;color:#fff;font:12px/1.45 monospace;padding:9px 11px;' +
                'border-radius:9px;white-space:pre-wrap;';
            d.textContent = 'Menue des Themes: ' + JSON.stringify(versteckt, null, 1);
            document.body.appendChild(d);
        }

<?php endif; ?>
        /*
         * Beim Herunterscrollen schrumpft die Leiste. Der Schwellwert liegt
         * bewusst nicht bei 0, sonst flackert sie bei jedem Wackeln.
         */
        var klein = false;
        function scrollPruefen() {
            var soll = (window.pageYOffset || document.documentElement.scrollTop) > 40;
            if (soll !== klein) {
                klein = soll;
                topbar.classList.toggle('is-small', klein);
            }
        }
        scrollPruefen();
        window.addEventListener('scroll', scrollPruefen, { passive: true });

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

/* =========================================================================
 * KENNWORTSPERRE ENTFERNEN
 *
 * Die geschlossene Phase ist vorbei — die Seite soll ohne Kennwort
 * erreichbar sein.
 *
 * Die Sperre steckt nicht in dieser Datei, sondern in einem eigenen
 * Must-Use-Plugin auf dem Server:
 * `/wp-content/mu-plugins/repairffm-gate.php` („Repair FFM –
 * Kennwortschutz"). Must-Use-Plugins lassen sich weder im Plugin-Editor
 * bearbeiten noch unter „Plugins" abschalten, und die Datei liegt
 * absichtlich nicht im Repository (sie enthält den Kennwort-Hash). Von
 * außen war an sie deshalb nie heranzukommen.
 *
 * Von hier aus schon: Dieses Plugin läuft auf demselben Server, im selben
 * PHP-Prozess, und wird nach den mu-Plugins geladen. Es tut zweierlei:
 *
 *   1. Es benennt die Gate-Datei einmalig in `*.php.deaktiviert` um.
 *      WordPress lädt aus dem mu-plugins-Verzeichnis nur `*.php` —
 *      ab dem nächsten Seitenaufruf ist die Sperre damit weg.
 *      Umbenannt und nicht gelöscht: Wer die geschlossene Phase
 *      zurückholen will, benennt die Datei zurück, fertig.
 *
 *   2. Für den laufenden Aufruf kommt das zu spät — da ist die Datei
 *      längst geladen und ihre Haken hängen. Deshalb werden zusätzlich
 *      alle Haken entfernt, deren Rückruf aus dieser Datei stammt.
 *      Wie die heißen, muss niemand wissen: Reflection verrät zu jedem
 *      Rückruf, in welcher Datei er steht.
 *
 * Erkannt wird die Sperre nicht am Dateinamen, sondern am Verhalten: Als
 * Gate gilt eine Datei, die den Zugangs-Cookie `rc_access` aus `$_COOKIE`
 * liest — oder die sich im Plugin-Kopf „Kennwortschutz" nennt. Die
 * Buchung nennt denselben Cookie zwar im Datenschutztext, liest ihn aber
 * nicht; sie wird an ihrer Funktion `rc_build_slots` erkannt und in jedem
 * Fall in Ruhe gelassen.
 *
 * Schlägt das Umbenennen fehl (Dateisystem schreibgeschützt), bleibt es
 * beim Aushängen der Haken — die Sperre ist dann trotzdem weg, aber bei
 * jedem Aufruf neu. Ein Hinweis im Verwaltungsbereich nennt in dem Fall
 * den Befehl für den Server.
 * ========================================================================= */

define('RFAT_GATE_LOG',    'rfat_gate_entfernt');
define('RFAT_GATE_NOTICE', 'rfat_gate_hinweis');
define('RFAT_GATE_TEXT',   'rfat_gate_datenschutz');

/*
 * Der Cookie-Absatz der Datenschutzerklaerung — an genau einer Stelle.
 * Er steht sowohl in der Einrichtung der Seiten (Abschnitt TERMINBUCHUNG,
 * Punkt 6) als auch in der Richtigstellung weiter unten; zwei Fassungen
 * davon waeren zwei Fassungen zu viel.
 */
define('RFAT_COOKIE_ABSATZ', '<p>Diese Website setzt für Besucher:innen keine eigenen Cookies. Für Team-Mitglieder setzt WordPress beim Login in den Verwaltungsbereich technisch notwendige Cookies. Zum technisch notwendigen Cookie des vorgelagerten Dienstes siehe den Abschnitt „Auslieferung über Cloudflare (CDN)". Rechtsgrundlage: § 25 Abs. 2 TDDDG i. V. m. Art. 6 Abs. 1 lit. f DSGVO.</p><p>Ein Cookie ist es nicht, gehört aber hierher: Damit du wieder an deinen Termin kommst, legt dein Browser deinen <strong>Buchungscode auf deinem Gerät</strong> ab (localStorage). Er wird <strong>nicht an uns übertragen</strong>, ist für andere Websites nicht lesbar und dient allein dazu, dir den Termin beim nächsten Besuch wieder anzubieten. Das ist für die von dir gewünschte Funktion erforderlich (§ 25 Abs. 2 Nr. 2 TDDDG). Du kannst ihn auf der Seite <a href="/termin-abrufen/">Termin abrufen</a> jederzeit mit einem Klick entfernen („nicht merken" bzw. „gemerkte Termine löschen") oder die Websitedaten in deinem Browser löschen.</p>');


/*
 * Absatz zur Buchungssperre (Abschnitt MISSBRAUCHSSCHUTZ BEI DER BUCHUNG).
 * Aus einer Hand wie der Cookie-Absatz: einmal hier, eingesetzt sowohl in
 * die frisch angelegte Datenschutzseite als auch nachträglich in eine
 * bestehende.
 */
define('RFAT_MISSBRAUCH_ABSATZ', '<h2>Schutz vor Mehrfachbuchungen</h2><p>Damit von einem einzelnen Gerät nicht beliebig viele Termine belegt werden können, prüfen wir beim Abschicken einer Buchung, wie viele <em>offene</em> Termine bereits von derselben Internet-Verbindung gebucht sind. Deine IP-Adresse wird dafür <strong>nicht gespeichert</strong>: Sie wird sofort in einen nicht umkehrbaren Prüfwert umgerechnet (HMAC-SHA-256 mit einem geheimen, nur auf dem Server vorhandenen Schlüssel); allein dieser Wert wird an der Buchung hinterlegt. Auf deine Adresse lässt er sich nicht zurückrechnen, und mit ihm ist auch kein Wiedererkennen über andere Seiten hinweg möglich. Der Prüfwert wird nach deinem Termin automatisch gelöscht, bei einer Stornierung sofort. Rechtsgrundlage ist unser berechtigtes Interesse daran, die knappen ehrenamtlichen Termine für alle nutzbar zu halten (Art. 6 Abs. 1 lit. f DSGVO).</p>');



/*
 * Absatz zu Rückfragen (seit 1.22.0) — dieselbe Machart wie die
 * Absätze darunter: einmal hier, eingesetzt in die frische wie in die
 * bestehende Datenschutzseite.
 */
define('RFAT_FRAGEN_ABSATZ', '<h2>Rückfragen zu deinem Termin</h2><p>Manchmal müssen wir vor dem Termin etwas wissen – ob du das Ladegerät mitbringst zum Beispiel. Solche Fragen und deine Antworten stehen bei deiner Buchung und sind über deinen Buchungscode sichtbar. Schreib dort bitte <strong>nur, was zur Reparatur gehört</strong>; personenbezogene Angaben sind nicht nötig. Rechtsgrundlage ist unser berechtigtes Interesse an der Vorbereitung des Termins (Art. 6 Abs. 1 lit. f DSGVO), bei freiwilligen Angaben deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Der Verlauf wird nach deinem Termin automatisch gelöscht, bei einer Stornierung mit der Buchung.</p>');

/*
 * Absatz zum freiwilligen Signal-Kontakt (seit 1.19.0). Aus einer Hand
 * wie die beiden Absätze darüber: einmal hier, eingesetzt sowohl in die
 * frisch angelegte Datenschutzseite als auch nachträglich in eine
 * bestehende.
 */
define('RFAT_SIGNAL_ABSATZ', '<h2>Freiwilliger Kontakt über Signal</h2><p>Statt einer E-Mail-Adresse – oder zusätzlich dazu – kannst du freiwillig deinen <strong>Signal-Benutzernamen</strong> oder deinen <strong>Signal-Link</strong> hinterlegen, damit wir dich zu deinem Termin erreichen können. Auch diese Angabe ist <strong>nicht erforderlich</strong>; ohne sie ändert sich nichts. Gespeichert wird ausschließlich, was du selbst einträgst – der Benutzername oder der Link, den Signal für dich erzeugt. <strong>Deine Telefonnummer bekommen wir dabei nicht zu sehen</strong>; genau dafür gibt es Benutzername und Link bei Signal. Der Link enthält keinen Klartext-Namen, sondern eine Kennung, mit der Signal deinen dort verschlüsselt abgelegten Benutzernamen findet.</p><p><strong>Rechtsgrundlage:</strong> deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).</p><p><strong>Speicherdauer:</strong> wie bei der E-Mail-Adresse. Nach deinem Termin wird die Angabe automatisch gelöscht, bei einer Stornierung sofort. Mit dem Häkchen „Meine Angaben dürfen auch nach dem Termin gespeichert bleiben" bleibt sie, bis du sie selbst wieder löschst.</p><p><strong>Widerruf:</strong> Rufe deinen Termin unter <a href="/termin-abrufen/">Termin abrufen</a> mit deinem Buchungscode auf, leere das Signal-Feld und speichere.</p><p><strong>Empfänger:</strong> Schreiben wir dir über Signal, läuft die Nachricht über den Dienst der Signal Messenger, LLC; deren Bedingungen und deren Ende-zu-Ende-Verschlüsselung gelten dann zusätzlich. Deine Angabe geben wir nicht an Dritte weiter und verwenden sie nicht für Werbung.</p>');

/**
 * Die Datei(en) der Kennwortsperre im mu-plugins-Verzeichnis finden.
 *
 * @return string[] Absolute Pfade.
 */
function rfat_gate_dateien() {
    static $gefunden = null;
    if (is_array($gefunden)) {
        return $gefunden;
    }
    $gefunden = [];

    if (!defined('WPMU_PLUGIN_DIR') || !is_dir(WPMU_PLUGIN_DIR)) {
        return $gefunden;
    }

    foreach ((array) glob(WPMU_PLUGIN_DIR . '/*.php') as $datei) {
        $inhalt = @file_get_contents($datei, false, null, 0, 256 * 1024);
        if (!is_string($inhalt) || $inhalt === '') {
            continue;
        }
        // Die Buchung bleibt unangetastet, auch wenn sie rc_access nennt.
        if (strpos($inhalt, 'rc_build_slots') !== false) {
            continue;
        }
        $liest_cookie = strpos($inhalt, 'rc_access') !== false && strpos($inhalt, '_COOKIE') !== false;
        $heisst_so    = (bool) preg_match('/^[ \t\/*#@]*Plugin Name:.*Kennwortschutz/mi', $inhalt);
        if (!$liest_cookie && !$heisst_so) {
            continue;
        }
        $pfad = realpath($datei);
        $gefunden[] = $pfad ? $pfad : $datei;
    }

    return $gefunden;
}

/**
 * In welcher Datei steht dieser Rückruf?
 *
 * @param mixed $rueckruf Callback aus $wp_filter.
 * @return string Absoluter Pfad oder '' (unbekannt, z. B. interne Funktion).
 */
function rfat_gate_rueckruf_datei($rueckruf) {
    try {
        if (is_string($rueckruf) && strpos($rueckruf, '::') !== false) {
            $rueckruf = explode('::', $rueckruf);
        }

        if ($rueckruf instanceof Closure || (is_string($rueckruf) && function_exists($rueckruf))) {
            $spiegel = new ReflectionFunction($rueckruf);
        } elseif (is_array($rueckruf) && count($rueckruf) === 2) {
            $spiegel = new ReflectionMethod($rueckruf[0], $rueckruf[1]);
        } elseif (is_object($rueckruf) && method_exists($rueckruf, '__invoke')) {
            $spiegel = new ReflectionMethod($rueckruf, '__invoke');
        } else {
            return '';
        }
    } catch (Throwable $e) {
        return '';
    }

    $datei = $spiegel->getFileName();
    if (!$datei) {
        return '';
    }
    $echt = realpath($datei);

    return $echt ? $echt : $datei;
}

/**
 * Alle Haken abhängen, die aus den übergebenen Dateien stammen.
 *
 * Nur die Haken, an denen eine Zugangssperre überhaupt hängen kann —
 * einmal durch den kompletten $wp_filter zu laufen wäre auf jeder Seite
 * spürbar und für nichts.
 *
 * @param string[] $dateien
 * @return int Anzahl entfernter Haken.
 */
function rfat_gate_haken_loesen(array $dateien) {
    if (!$dateien || empty($GLOBALS['wp_filter'])) {
        return 0;
    }

    $haken = [
        'muplugins_loaded', 'plugins_loaded', 'setup_theme', 'after_setup_theme',
        'init', 'wp_loaded', 'parse_request', 'send_headers', 'wp',
        'template_redirect', 'template_include', 'get_header', 'wp_head',
        'posts_results', 'the_content', 'login_init', 'admin_init',
    ];

    $entfernt = 0;

    foreach ($haken as $name) {
        if (!isset($GLOBALS['wp_filter'][$name])) {
            continue;
        }
        $hook = $GLOBALS['wp_filter'][$name];
        if (!($hook instanceof WP_Hook)) {
            continue;
        }

        // Erst sammeln, dann entfernen — sonst wird die Liste unter der
        // laufenden Schleife verändert.
        $weg = [];
        foreach ($hook->callbacks as $prio => $eintraege) {
            foreach ($eintraege as $eintrag) {
                if (!isset($eintrag['function'])) {
                    continue;
                }
                $datei = rfat_gate_rueckruf_datei($eintrag['function']);
                if ($datei !== '' && in_array($datei, $dateien, true)) {
                    $weg[] = [$prio, $eintrag['function']];
                }
            }
        }

        foreach ($weg as $treffer) {
            remove_filter($name, $treffer[1], $treffer[0]);
            $entfernt++;
        }
    }

    return $entfernt;
}

/**
 * Kennwortsperre abschalten: Haken lösen, Datei stilllegen.
 *
 * Läuft vor `init` und `template_redirect` — dort greift eine Sperre
 * üblicherweise zu.
 */
function rfat_kennwortsperre_entfernen() {
    $log = get_option(RFAT_GATE_LOG);

    /*
     * Erledigt ist erledigt: kein glob(), kein Lesen, nichts. Nur wenn
     * eine Datei liegen geblieben ist (Umbenennen ging nicht), wird bei
     * jedem Aufruf weitergearbeitet.
     */
    if (is_array($log) && empty($log['offen'])) {
        return;
    }

    $dateien = rfat_gate_dateien();
    if (!$dateien) {
        if (!is_array($log)) {
            update_option(RFAT_GATE_LOG, ['zeit' => time(), 'erledigt' => [], 'offen' => []], true);
        }
        return;
    }

    rfat_gate_haken_loesen($dateien);

    $erledigt = (is_array($log) && !empty($log['erledigt'])) ? (array) $log['erledigt'] : [];
    $offen    = [];

    foreach ($dateien as $datei) {
        $ziel = $datei . '.deaktiviert';
        if (file_exists($ziel)) {
            $ziel = $datei . '.deaktiviert-' . gmdate('Ymd-His');
        }
        if (@rename($datei, $ziel)) {
            $erledigt[] = $ziel;
        } else {
            $offen[] = $datei;
        }
    }

    update_option(RFAT_GATE_LOG, [
        'zeit'     => time(),
        'erledigt' => $erledigt,
        'offen'    => $offen,
    ], true);

    if ($erledigt && !$offen) {
        update_option(RFAT_GATE_NOTICE, '1', false);
    }
}
add_action('plugins_loaded', 'rfat_kennwortsperre_entfernen', 0);

/**
 * Der Cookie-Absatz der Datenschutzerklärung stimmt ohne Sperre nicht mehr.
 *
 * Ersetzt wird genau dieser eine Absatz, nicht die ganze Seite: Für die
 * übrigen Texte gilt weiterhin, dass eigene Änderungen im Seiteneditor
 * nicht überschrieben werden (anders als beim Hochzählen von
 * `rc_setup_version`, das alle fünf Seiten neu schreibt).
 */
function rfat_datenschutz_cookie_absatz() {
    $seite = get_page_by_path('datenschutz');
    if (!$seite) {
        return false;
    }

    $alt = (string) $seite->post_content;
    $pos = strpos($alt, 'geschlossenen Phase');
    if ($pos === false) {
        return false;
    }

    $start = strrpos(substr($alt, 0, $pos), '<p>');
    $ende  = strpos($alt, '</p>', $pos);
    if ($start === false || $ende === false) {
        return false;
    }

    $neu = substr($alt, 0, $start) . RFAT_COOKIE_ABSATZ . substr($alt, $ende + 4);
    wp_update_post(['ID' => $seite->ID, 'post_content' => $neu]);

    return true;
}

add_action('init', function () {
    if (get_option(RFAT_GATE_TEXT) === '1') {
        return;
    }
    $log = get_option(RFAT_GATE_LOG);
    if (!is_array($log) || empty($log['erledigt'])) {
        return;
    }
    rfat_datenschutz_cookie_absatz();
    update_option(RFAT_GATE_TEXT, '1', false);
}, 30);

/**
 * Den Absatz zur Buchungssperre einmalig in die bestehende
 * Datenschutzseite einsetzen.
 *
 * Dieselbe Zurückhaltung wie beim Cookie-Absatz: Es wird ein Abschnitt
 * eingefügt und sonst nichts angefasst. Das Hochzählen von
 * `rc_setup_version` schriebe alle fünf Seiten neu und würde eigene
 * Änderungen im Seiteneditor überschreiben.
 *
 * Gesagt werden muss es: Die Sperre verarbeitet die IP-Adresse - auch wenn
 * sie sie nicht speichert. Eine Erklärung, in der das fehlt, ist unvollständig.
 *
 * @return bool Ob der Absatz jetzt (oder schon vorher) drinsteht.
 */
function rfat_datenschutz_missbrauch_absatz() {
    $seite = get_page_by_path('datenschutz');
    if (!$seite) {
        return false;
    }

    $alt = (string) $seite->post_content;
    if (strpos($alt, 'Schutz vor Mehrfachbuchungen') !== false) {
        return true;
    }

    // Vor den Cookies, sonst vor den Server-Protokollen: In beiden Fällen
    // steht der Absatz damit bei der Buchung und nicht am Ende der Seite.
    $vor = '<h2>Cookies</h2>';
    $pos = strpos($alt, $vor);
    if ($pos === false) {
        $vor = '<h2>Server-Protokolle</h2>';
        $pos = strpos($alt, $vor);
    }

    $neu = $pos === false
        ? $alt . "\n" . RFAT_MISSBRAUCH_ABSATZ
        : substr($alt, 0, $pos) . RFAT_MISSBRAUCH_ABSATZ . "\n" . substr($alt, $pos);

    wp_update_post(['ID' => $seite->ID, 'post_content' => $neu]);

    return true;
}

add_action('init', function () {
    if (get_option('rfat_ds_missbrauch') === '1') {
        return;
    }
    if (rfat_datenschutz_missbrauch_absatz()) {
        update_option('rfat_ds_missbrauch', '1');
    }
}, 31);

/**
 * Die Datenschutzseite auf den Stand des Plugins bringen: Abschnitte zu
 * Signal, zu Rückfragen und zum lokal gemerkten Buchungscode einsetzen
 * oder ältere Fassungen davon ersetzen, und die zitierte Beschriftung des
 * Häkchens nachziehen.
 *
 * Angefasst werden nur diese Abschnitte — alles andere auf der Seite
 * gehört dem Betreiber. Das Hochzählen von `rc_setup_version` schriebe
 * dagegen alle fünf Seiten neu.
 *
 * Die Textarbeit steckt in rfat_datenschutz_text_pflegen(): ohne
 * Datenbank, damit sie sich prüfen lässt.
 *
 * Gleiche Zurückhaltung wie bei den beiden Absätzen davor: Es wird ein
 * Abschnitt eingefügt und ein Satz berichtigt, sonst nichts angefasst.
 *
 * @return bool Ob der Absatz jetzt (oder schon vorher) drinsteht.
 */
function rfat_datenschutz_text_pflegen($alt) {
    $neu = str_replace(
        '„Meine Adresse darf auch nach dem Termin gespeichert bleiben"',
        '„Meine Angaben dürfen auch nach dem Termin gespeichert bleiben"',
        (string) $alt
    );

    $neu = rfat_absatz_setzen($neu, '<h2>Freiwilliger Kontakt über Signal</h2>', RFAT_SIGNAL_ABSATZ,
        ['<h2>Rückfragen zu deinem Termin</h2>', '<h2>Schutz vor Mehrfachbuchungen</h2>', '<h2>Cookies</h2>', '<h2>Server-Protokolle</h2>']);

    $neu = rfat_absatz_setzen($neu, '<h2>Rückfragen zu deinem Termin</h2>', RFAT_FRAGEN_ABSATZ,
        ['<h2>Schutz vor Mehrfachbuchungen</h2>', '<h2>Cookies</h2>', '<h2>Server-Protokolle</h2>']);

    /*
     * Der Cookie-Abschnitt sagte „keine eigenen Cookies" und schwieg zum
     * lokal gemerkten Buchungscode. Das stimmt so nicht mehr, also wird er
     * mitgezogen — als ganzer Abschnitt, wie die anderen auch.
     */
    $neu = rfat_absatz_setzen($neu, '<h2>Cookies</h2>', '<h2>Cookies</h2>' . RFAT_COOKIE_ABSATZ,
        ['<h2>Server-Protokolle</h2>']);

    return $neu;
}

/**
 * Einen Abschnitt einsetzen oder eine ältere Fassung davon ersetzen.
 *
 * Ein Abschnitt reicht von seiner Überschrift bis zur nächsten `<h2>`.
 * Steht er noch nicht da, kommt er vor die erste Marke, die sich finden
 * lässt; findet sich keine, ans Ende.
 *
 * Warum ersetzen statt nur prüfen, ob schon etwas da ist: Sonst bliebe auf
 * bestehenden Seiten für immer der Text stehen, mit dem der Abschnitt
 * einmal eingesetzt wurde — samt allem, was inzwischen nicht mehr stimmt.
 */
function rfat_absatz_setzen($inhalt, $kopf, $absatz, array $marken) {
    $stelle = strpos($inhalt, $kopf);

    if ($stelle === false) {
        foreach ($marken as $marke) {
            $pos = strpos($inhalt, $marke);
            if ($pos !== false) {
                return substr($inhalt, 0, $pos) . $absatz . "\n" . substr($inhalt, $pos);
            }
        }
        return $inhalt . "\n" . $absatz;
    }

    $ende = strpos($inhalt, '<h2>', $stelle + strlen($kopf));
    return $ende === false
        ? substr($inhalt, 0, $stelle) . $absatz
        : substr($inhalt, 0, $stelle) . $absatz . "\n" . substr($inhalt, $ende);
}

function rfat_datenschutz_signal_absatz() {
    $seite = get_page_by_path('datenschutz');
    if (!$seite) {
        return false;
    }

    $alt = (string) $seite->post_content;
    $neu = rfat_datenschutz_text_pflegen($alt);

    if ($neu !== $alt) {
        wp_update_post(['ID' => $seite->ID, 'post_content' => $neu]);
    }

    return true;
}

/*
 * Fassung des Signal-Absatzes. Hochzählen, wenn sich der Text ändert —
 * dann zieht die Routine ihn auf bestehenden Seiten nach.
 */
define('RFAT_DS_SIGNAL_FASSUNG', '3');

add_action('init', function () {
    if (get_option('rfat_ds_signal') === RFAT_DS_SIGNAL_FASSUNG) {
        return;
    }
    if (rfat_datenschutz_signal_absatz()) {
        update_option('rfat_ds_signal', RFAT_DS_SIGNAL_FASSUNG);
    }
}, 32);

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $log = get_option(RFAT_GATE_LOG);

    // Datei liegt noch da und ließ sich nicht umbenennen: Das gehört gesagt,
    // sonst hängt die Sperre bei jedem Aufruf neu am seidenen Faden.
    if (is_array($log) && !empty($log['offen'])) {
        echo '<div class="notice notice-warning"><p><strong>Kennwortsperre:</strong> '
            . 'Die Sperre ist abgeschaltet, die Datei liegt aber noch auf dem Server und '
            . 'ließ sich nicht umbenennen (Schreibrechte). Auf dem Server erledigt das:</p>'
            . '<p><code>mv ' . esc_html($log['offen'][0]) . ' ' . esc_html($log['offen'][0]) . '.deaktiviert</code></p></div>';
        return;
    }

    if (get_option(RFAT_GATE_NOTICE) !== '1') {
        return;
    }
    delete_option(RFAT_GATE_NOTICE);

    $datei = (is_array($log) && !empty($log['erledigt'])) ? (string) end($log['erledigt']) : '';

    echo '<div class="notice notice-success is-dismissible"><p><strong>Kennwortsperre entfernt.</strong> '
        . 'Die Seite ist ohne Kennwort erreichbar.'
        . ($datei !== '' ? ' Die Sperr-Datei liegt als <code>' . esc_html($datei) . '</code> daneben — '
            . 'zum Zurückholen der geschlossenen Phase auf den alten Namen zurückbenennen.' : '')
        . '</p><p>Vor dem Bekanntmachen der Adresse noch prüfen: '
        . '<em>Einstellungen → Lesen</em> — „Suchmaschinen davon abhalten, diese Website zu indexieren" '
        . (get_option('blog_public') ? 'ist aus. ' : '<strong>ist noch gesetzt.</strong> ')
        . 'Impressum, Datenschutz und Startseite auf Aktualität ansehen.</p></div>';
});

/* =========================================================================
 * MISSBRAUCHSSCHUTZ BEI DER BUCHUNG
 *
 * Die Buchung kommt ohne Konto und ohne Namen aus — genau das ist die
 * Zusage der Seite. Der Preis dafür: Nichts hindert ein einzelnes Gerät
 * daran, in einer Minute alle freien Termine wegzubuchen. Vier Samstage
 * mit je neun Plätzen sind schnell voll, und sie fehlen dann allen
 * anderen.
 *
 * Gezählt werden deshalb die *offenen* Termine derselben Verbindung.
 * Offen heißt: veröffentlicht (also nicht storniert) und noch nicht
 * vorbei. Wer absagt, bekommt seinen Platz im Kontingent sofort zurück —
 * sonst wäre das Verschieben eines Termins (erst neu buchen, dann alt
 * stornieren, siehe [rfat_manage_booking]) nach wenigen Malen gesperrt.
 * Genau deshalb ist das Kontingent kein Tageszähler: Ein Zähler, der nur
 * hochgeht, bestraft das Verschieben und lässt den Vielbucher nach
 * Mitternacht trotzdem weitermachen.
 *
 * Datensparsam bleibt es trotzdem. Die IP-Adresse selbst wird nirgends
 * gespeichert: Aus ihr entsteht sofort ein HMAC-Prüfwert mit dem
 * geheimen Schlüssel der Installation. Nur dieser Wert steht an der
 * Buchung — zurückrechnen lässt er sich nicht — und auch er verschwindet
 * beim Stornieren und nach dem Termin.
 * ========================================================================= */

define('RFAT_LIMIT_OPTION', 'rfat_buchung_limit');   // 0 = aus, sonst 2..20
define('RFAT_LIMIT_DEFAULT', 3);
define('RFAT_LIMIT_PAUSE', 30);                      // Sekunden zwischen zwei Buchungen
define('RFAT_GERAET_META', '_rfat_geraet');
define('RFAT_LIMIT_LOG', 'rfat_limit_log');

/**
 * Ist das eine Adresse, die einen Besucher draußen im Netz bezeichnet?
 *
 * Private Bereiche (10.x, 192.168.x, fc00::/7) und reservierte Adressen
 * (127.0.0.1, ::1) sind hier ein *Ausschluss*kriterium: Steht so eine
 * Adresse dran, sitzt in Wahrheit ein Proxy davor und alle Besucher sähen
 * gleich aus.
 */
function rfat_ip_oeffentlich($ip) {
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/**
 * Die Adresse des Besuchers samt der Quelle, aus der sie stammt.
 *
 * Die Reihenfolge ist kein Geschmack, sondern Sicherheit: Kopfzeilen wie
 * X-Forwarded-For darf jeder Aufrufer frei erfinden. Steht in REMOTE_ADDR
 * bereits eine öffentliche Adresse, gibt es keinen Proxy davor — dann
 * zählt allein sie, und erfundene Kopfzeilen bleiben wirkungslos.
 *
 * Erst wenn REMOTE_ADDR privat ist (bei dieser Seite der Regelfall: der
 * Server läuft in Docker hinter einem Reverse-Proxy, davor Cloudflare),
 * müssen die Kopfzeilen ran. CF-Connecting-IP zuerst, denn Cloudflare
 * überschreibt diese Zeile mit der echten Adresse; X-Forwarded-For steht
 * hinten an, weil dort mehrere Stationen hintereinander stehen können.
 *
 * @return array{ip:string,quelle:string} ip = '' heißt: nicht bestimmbar.
 */
function rfat_besucher_ip() {
    $direkt = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

    if (rfat_ip_oeffentlich($direkt)) {
        return ['ip' => $direkt, 'quelle' => 'REMOTE_ADDR'];
    }

    $kopfzeilen = [
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
        'HTTP_X_REAL_IP'        => 'X-Real-IP',
        'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
    ];
    foreach ($kopfzeilen as $schluessel => $name) {
        if (empty($_SERVER[$schluessel])) {
            continue;
        }
        foreach (explode(',', (string) $_SERVER[$schluessel]) as $teil) {
            $teil = trim($teil);
            if (rfat_ip_oeffentlich($teil)) {
                return ['ip' => $teil, 'quelle' => $name];
            }
        }
    }

    return ['ip' => '', 'quelle' => $direkt !== '' ? 'REMOTE_ADDR' : ''];
}

/**
 * Bei IPv6 auf das /64-Präfix kürzen.
 *
 * Ein Anschluss bekommt dort nicht eine Adresse, sondern ein ganzes Netz.
 * Wer die letzte Stelle hochzählt, hätte sonst beliebig viele „Geräte" —
 * die Sperre wäre mit einem Handgriff umgangen. Das Präfix bleibt.
 * IPv4-Adressen gehen unverändert durch.
 */
function rfat_ip_gruppe($ip) {
    if (strpos($ip, ':') === false) {
        return $ip;
    }
    $roh = @inet_pton($ip);
    if ($roh === false || strlen($roh) !== 16) {
        return $ip;
    }
    $gekuerzt = @inet_ntop(substr($roh, 0, 8) . str_repeat("\0", 8));
    return $gekuerzt === false ? $ip : $gekuerzt;
}

/**
 * Der Prüfwert, unter dem ein Gerät gezählt wird — oder '' , wenn sich
 * keine Adresse bestimmen lässt.
 *
 * HMAC mit dem Schlüssel der Installation, nicht bloß ein Hash: Ein
 * blanker SHA-256 über eine IP-Adresse ist in Minuten durchprobiert (es
 * gibt nur gut vier Milliarden IPv4-Adressen). Mit dem geheimen Schlüssel
 * geht das nicht, und der Wert bleibt trotzdem für dieselbe Adresse immer
 * derselbe.
 */
function rfat_geraete_kennung() {
    $erkannt = rfat_besucher_ip();
    if ($erkannt['ip'] === '') {
        return '';
    }
    return substr(hash_hmac('sha256', rfat_ip_gruppe($erkannt['ip']), wp_salt('rfat_geraet')), 0, 32);
}

/**
 * Wie viele offene Termine ein Gerät gleichzeitig haben darf.
 *
 * Unter 2 wird nicht gestellt, solange die Sperre an ist: Beim
 * Verschieben eines Termins existieren kurz zwei Buchungen nebeneinander
 * (erst die neue buchen, dann die alte stornieren). Ein Kontingent von 1
 * würde genau diesen Weg unmöglich machen.
 */
function rfat_buchung_limit() {
    $wert = get_option(RFAT_LIMIT_OPTION, null);
    if ($wert === null || $wert === '') {
        return RFAT_LIMIT_DEFAULT;
    }
    $wert = (int) $wert;
    if ($wert <= 0) {
        return 0;
    }
    return max(2, min(20, $wert));
}

/**
 * Offene Buchungen dieses Geräts zählen.
 *
 * Nur `publish` — Stornierungen landen im Papierkorb und zählen damit von
 * selbst nicht mehr mit. Vergangene Termine ebenfalls nicht: Sonst wäre
 * das Kontingent nach ein paar Monaten dauerhaft aufgebraucht, obwohl
 * kein einziger Termin mehr aussteht.
 */
function rfat_offene_buchungen($kennung) {
    if ($kennung === '') {
        return 0;
    }
    $ids = get_posts([
        'post_type'              => 'rc_booking',
        'post_status'            => 'publish',
        'numberposts'            => 50,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_key'               => RFAT_GERAET_META,
        'meta_value'             => $kennung,
    ]);
    if (!$ids) {
        return 0;
    }

    $heute = current_time('Y-m-d');
    $offen = 0;
    foreach ($ids as $pid) {
        $datum = (string) get_post_meta($pid, '_rc_date', true);
        // Ohne erkennbares Datum im Zweifel mitzählen.
        if ($datum === '' || $datum >= $heute) {
            $offen++;
        }
    }
    return $offen;
}

/**
 * Darf dieses Gerät gerade buchen? Gibt den Fehlerschlüssel zurück, unter
 * dem rc_booking_errors() die Begründung führt — oder '' für "ja".
 */
function rfat_buchung_gesperrt($kennung) {
    $limit = rfat_buchung_limit();
    if ($limit === 0) {
        return '';
    }

    /*
     * Keine bestimmbare Adresse heißt: durchlassen, nicht sperren.
     *
     * Andersherum wäre es fatal. Reicht der Proxy die Adresse einmal nicht
     * mehr durch, sähen alle Besucher gleich aus — die Sperre würde nach
     * drei Buchungen die ganze Seite dichtmachen, für alle, und niemand
     * verstünde warum. Ein zu großzügiger Schutz ist der kleinere Schaden.
     * Der Hinweis in der Übersicht sagt, wenn es so weit ist.
     */
    if ($kennung === '') {
        return '';
    }

    $letzte = (int) get_transient('rfat_letzte_' . $kennung);
    if ($letzte > 0 && (time() - $letzte) < RFAT_LIMIT_PAUSE) {
        return 'zuschnell';
    }

    if (rfat_offene_buchungen($kennung) >= $limit) {
        return 'zuviel';
    }

    return '';
}

/**
 * Nach erfolgreicher Buchung: Prüfwert an die Buchung, Zeitpunkt für die
 * kurze Pause merken.
 */
function rfat_buchung_vermerken($post_id, $kennung) {
    if ($kennung === '') {
        return;
    }
    update_post_meta($post_id, RFAT_GERAET_META, $kennung);
    set_transient('rfat_letzte_' . $kennung, time(), max(RFAT_LIMIT_PAUSE, MINUTE_IN_SECONDS));
}

/**
 * Abweisungen mitzählen — ohne Adresse, ohne Prüfwert, nur Anzahl und
 * Zeitpunkt. Sonst lässt sich in der Übersicht nicht unterscheiden, ob die
 * Sperre nie greift oder ob sie ständig zuschlägt (dann ist sie zu eng).
 */
function rfat_limit_vermerken($grund) {
    $log = get_option(RFAT_LIMIT_LOG);
    if (!is_array($log)) {
        $log = ['anzahl' => 0, 'zeit' => 0, 'grund' => ''];
    }
    $log['anzahl'] = (int) $log['anzahl'] + 1;
    $log['zeit']   = time();
    $log['grund']  = $grund;
    update_option(RFAT_LIMIT_LOG, $log, false);
}

/*
 * Storniert heißt weg: Mit dem Termin entfällt der Zweck, für den der
 * Prüfwert erhoben wurde. Fürs Zählen wäre das nicht nötig (Papierkorb
 * ist nicht `publish`), für die Zusage in der Datenschutzerklärung schon.
 */
add_action('trashed_post', function ($post_id) {
    if (get_post_type($post_id) === 'rc_booking') {
        delete_post_meta($post_id, RFAT_GERAET_META);
    }
});

/**
 * Prüfwerte abgelaufener Termine entfernen.
 *
 * Läuft am selben Haken wie das Löschen der E-Mail-Adressen, aber als
 * eigene Funktion: Die dortige Schleife holt nur Buchungen mit
 * hinterlegter Adresse — die allermeisten Buchungen haben keine.
 *
 * @return int Anzahl der gelöschten Prüfwerte.
 */
function rfat_cleanup_geraetekennungen() {
    $posts = get_posts([
        'post_type'              => 'rc_booking',
        'posts_per_page'         => 200,
        'post_status'            => 'any',
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_key'               => RFAT_GERAET_META,
        'meta_compare'           => 'EXISTS',
    ]);
    if (!$posts) {
        return 0;
    }

    // time() statt current_time('timestamp') — siehe rfat_cleanup_emails().
    $cutoff   = time() - RFAT_EMAIL_GRACE;
    $entfernt = 0;

    foreach ($posts as $post_id) {
        $analysis = rfat_analyse_booking($post_id);
        $dt       = $analysis['datetime'];

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

        delete_post_meta($post_id, RFAT_GERAET_META);
        $entfernt++;
    }

    return $entfernt;
}
add_action(RFAT_CLEANUP_HOOK, 'rfat_cleanup_geraetekennungen');

/* =========================================================================
 * TERMINBUCHUNG
 *
 * Diese Funktionen lagen bis 1.11.0 in einem mu-Plugin
 * (/wp-content/mu-plugins/repairffm-core.php). mu-Plugins kennt der
 * WordPress-Updater nicht: sie haben keine Version, tauchen unter
 * "Plugins" nicht auf und lassen sich nicht aktualisieren. Jede
 * Aenderung musste deshalb von Hand auf den Server kopiert werden -
 * mit dem Ergebnis, dass die beiden Haelften auseinanderliefen und
 * einmal ein Feld verschwand, weil die eine Haelfte aktuell war und
 * die andere nicht.
 *
 * Hier drin faehrt die Buchung im selben Zug wie alles andere ueber
 * GitHub mit.
 *
 * Der Schalter unten ist die Bruecke fuer die Umstellung: Liegt das
 * alte mu-Plugin noch auf dem Server, hat es Vorrang und dieser Block
 * bleibt still - sonst gaebe es einen Fatal Error durch doppelt
 * deklarierte Funktionen. Erst wenn die Datei geloescht ist,
 * uebernimmt dieser Teil. Die Reihenfolge spielt dadurch keine Rolle.
 * ========================================================================= */
if (!function_exists('rc_build_slots')) {

    /* ---------------------------------------------------------------
     * 1) Booking custom post type (admin-sichtbar, nicht öffentlich)
     * ------------------------------------------------------------- */
    add_action('init', function () {
        register_post_type('rc_booking', array(
            'labels' => array(
                'name' => 'Buchungen',
                'singular_name' => 'Buchung',
                'menu_name' => 'Buchungen',
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => array('title'),
            'capability_type' => 'post',
        ));
    });

    /* ---------------------------------------------------------------
     * 2) Slot-Generierung: die naechsten 4 Samstage, 14–17 Uhr
     *    (Termine hier zentral aenderbar)
     * ------------------------------------------------------------- */
    /*
     * Buchbare Kategorien und ihre Uhrzeiten kommen seit 1.24.0 aus der
     * Einstellung (siehe rfat_kategorien()). Die beiden Funktionen bleiben
     * als Namen bestehen: Sie stehen an einem Dutzend Stellen im
     * Buchungsablauf, und der Ablauf soll nicht wissen müssen, woher die
     * Liste kommt.
     */
    function rc_categories() {
        $namen = array();
        foreach (rfat_kategorien(true) as $slug => $eintrag) {
            $namen[$slug] = $eintrag['name'];
        }
        return $namen;
    }
    function rc_slot_times($cat) {
        $kategorien = rfat_kategorien(true);
        return isset($kategorien[$cat]) ? $kategorien[$cat]['zeiten'] : array();
    }
    function rc_weekday_de($w) {
        $d = array('Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag');
        return $d[(int)$w];
    }
    function rc_build_slots() {
        $cats = rc_categories();
        $slots = array();
        $d = new DateTime('today');
        $found = 0;
        $guard = 0;
        while ($found < 4 && $guard < 60) {
            if ((int)$d->format('N') === 6) { // Samstag
                $found++;
                $dateStr = $d->format('Y-m-d');
                $dateLabel = rc_weekday_de($d->format('w')) . ', ' . $d->format('d.m.Y');
                foreach ($cats as $ck => $cl) {
                    foreach (rc_slot_times($ck) as $t) {
                        $id = $ck . '_' . $dateStr . '_' . str_replace(':','',$t);
                        $slots[] = array(
                            'id' => $id,
                            'cat' => $ck,
                            'date' => $dateStr,
                            'dateLabel' => $dateLabel,
                            'time' => $t,
                        );
                    }
                }
            }
            $d->modify('+1 day');
            $guard++;
        }
        return $slots;
    }
    function rc_booked_slot_ids() {
        /*
         * Welche Termine schon weg sind. Steht auf jeder Slot-Seite an,
         * deshalb kurz gecacht. Zwei Minuten sind unkritisch: Der Cache
         * wird beim Buchen und Stornieren sofort verworfen, und beim
         * Anlegen prueft rc_create_booking ohnehin noch einmal direkt in
         * der Datenbank - eine Doppelbuchung kann daraus also nicht
         * entstehen, hoechstens ein kurz veralteter Anblick.
         */
        $cached = get_transient('rfat_booked_slots');
        if (is_array($cached)) {
            return $cached;
        }
        $ids = array();
        $q = get_posts(array(
            'post_type'   => 'rc_booking',
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields'      => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_key'     => '_rc_slot',
            'meta_compare' => 'EXISTS',
        ));
        foreach ($q as $pid) {
            $s = get_post_meta($pid, '_rc_slot', true);
            if ($s) $ids[$s] = true;
        }
        set_transient('rfat_booked_slots', $ids, 2 * MINUTE_IN_SECONDS);
        return $ids;
    }

    /* ---------------------------------------------------------------
     * 3) Terminbuchung als echte Seite
     *
     * Frueher war die Buchung ein Popup: ein Overlay, das per JavaScript
     * ueber die Seite gelegt wurde, mit allen vier Schritten im selben
     * Dokument. Drei Nachteile davon haben sich in der Praxis gezeigt --
     * der Schliessen-Knopf war schwer zu treffen, der Zurueck-Knopf des
     * Browsers verliess die ganze Seite statt einen Schritt zurueckzugehen,
     * und ohne JavaScript ging gar nichts.
     *
     * Jetzt ist jeder Schritt eine echte Adresse. Schritt 1 und 2 sind
     * Links, Schritt 3 ist ein gewoehnliches Formular, danach wird
     * weitergeleitet (Post/Redirect/Get - so fuehrt Neuladen nicht zur
     * Doppelbuchung). Zurueck, Neuladen, Lesezeichen und Weiterschicken
     * funktionieren dadurch von selbst, und die Buchung laeuft auch ohne
     * JavaScript.
     *
     *   /termin-buchen/                          Kategorie waehlen
     *   /termin-buchen/?was=it                   freien Termin waehlen
     *   /termin-buchen/?was=it&wann=<slot>       bestaetigen
     *   /termin-buchen/?code=RC-XXXXX            fertig
     * ------------------------------------------------------------- */

    /**
     * Adresse der Buchungsseite mit den uebergebenen Schritt-Parametern.
     * Baut auf der aktuellen Seite auf, damit der Shortcode auch dann
     * funktioniert, wenn er einmal auf einer anders benannten Seite steht.
     */
    function rc_booking_page_url($args = array()) {
        $url = get_permalink(get_queried_object_id());
        if (!$url) $url = home_url('/termin-buchen/');
        foreach ($args as $k => $v) {
            if ($v === null || $v === '') continue;
            $url = add_query_arg($k, $v, $url);
        }
        return $url;
    }

    function rc_slot_by_id($id) {
        foreach (rc_build_slots() as $s) {
            if ($s['id'] === $id) return $s;
        }
        return null;
    }


    /**
     * Fehlermeldungen als feste Schluessel statt als Text in der Adresse:
     * sonst koennte ueber einen praeparierten Link ein beliebiger Satz auf
     * der eigenen Seite erscheinen.
     */
    function rc_booking_errors() {
        // Bei abgeschalteter Sperre steht die Meldung nur zur Vollständigkeit
        // hier; dann darf keine Null im Satz landen.
        $limit = rfat_buchung_limit();
        if ($limit < 2) $limit = RFAT_LIMIT_DEFAULT;
        return array(
            'nonce'     => 'Die Sicherheitsprüfung ist fehlgeschlagen. Das passiert meist, wenn die Seite lange offen lag. Bitte den Termin noch einmal auswählen.',
            'weg'       => 'Diesen Termin gibt es nicht mehr. Bitte einen anderen wählen.',
            'belegt'    => 'Dieser Termin ist gerade vergeben worden. Bitte einen anderen wählen.',
            'speichern' => 'Das Speichern hat nicht geklappt. Bitte noch einmal versuchen.',
            /*
             * Die Sperre erklärt sich selbst und sagt den Ausweg dazu. Ein
             * blosses "nicht möglich" liest sich wie ein Fehler der Seite -
             * dann versucht man es dreimal und schreibt uns dann verärgert.
             */
            'zuviel'    => 'Von diesem Anschluss sind bereits ' . $limit . ' Termine'
                           . ' gebucht - mehr geht gleichzeitig nicht, damit für alle etwas übrig bleibt. '
                           . 'Sagst du einen davon unter „Termin abrufen" ab, ist sofort wieder ein Platz frei. '
                           . 'Brauchst du wirklich mehr Termine, schreib uns kurz eine E-Mail.',
            'zuschnell' => 'Das ging sehr schnell hintereinander. Bitte warte einen Moment und schick die Anfrage dann noch einmal ab.',
        );
    }

    /**
     * Legt die Buchung an. Gibt array('ok'=>true,'code'=>...) zurueck oder
     * array('ok'=>false,'err'=>'<schluessel>').
     */
    function rc_create_booking($slot_id, $note) {
        $slot = rc_slot_by_id($slot_id);
        if (!$slot) return array('ok' => false, 'err' => 'weg');

        /*
         * Vor dem Belegtsein prüfen, nicht danach: Wer sein Kontingent
         * ausgeschöpft hat, soll das erfahren und nicht erst durch alle
         * freien Termine klicken. Warum überhaupt gedeckelt wird, steht
         * im Abschnitt MISSBRAUCHSSCHUTZ BEI DER BUCHUNG.
         */
        $kennung = rfat_geraete_kennung();
        $sperre  = rfat_buchung_gesperrt($kennung);
        if ($sperre !== '') {
            rfat_limit_vermerken($sperre);
            return array('ok' => false, 'err' => $sperre);
        }

        $existing = get_posts(array(
            'post_type' => 'rc_booking', 'numberposts' => 1, 'post_status' => 'publish',
            'fields' => 'ids', 'meta_key' => '_rc_slot', 'meta_value' => $slot_id,
        ));
        if (!empty($existing)) return array('ok' => false, 'err' => 'belegt');

        $cats  = rc_categories();
        $code  = 'RC-' . strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5));
        $title = $slot['dateLabel'] . ' ' . $slot['time'] . ' – ' . $cats[$slot['cat']] . ' [' . $code . ']';

        $pid = wp_insert_post(array(
            'post_type' => 'rc_booking', 'post_status' => 'publish', 'post_title' => $title,
        ));
        if (!$pid || is_wp_error($pid)) return array('ok' => false, 'err' => 'speichern');

        update_post_meta($pid, '_rc_slot', $slot_id);
        update_post_meta($pid, '_rc_code', $code);
        update_post_meta($pid, '_rc_cat',  $slot['cat']);
        update_post_meta($pid, '_rc_date', $slot['date']);
        update_post_meta($pid, '_rc_time', $slot['time']);
        if ($note !== '') update_post_meta($pid, '_rc_note', $note);
        rfat_buchung_vermerken($pid, $kennung);

        /*
         * Zusaetzlich zum Hook oben: save_post feuert beim Anlegen, da ist
         * _rc_slot aber noch nicht geschrieben. Zwischen beiden koennte ein
         * anderer Aufruf den Cache ohne den neuen Termin neu aufbauen.
         */
        delete_transient('rfat_booked_slots');

        return array('ok' => true, 'code' => $code);
    }

    /**
     * Das abgeschickte Formular aus Schritt 3 entgegennehmen, bevor
     * irgendetwas ausgegeben wurde - danach ist keine Weiterleitung mehr
     * moeglich.
     */
    add_action('template_redirect', function () {
        if (empty($_POST['rc_book_submit'])) return;

        $slot_id = isset($_POST['slot']) ? sanitize_text_field(wp_unslash($_POST['slot'])) : '';
        $note    = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        $slot    = rc_slot_by_id($slot_id);
        $back    = rc_booking_page_url(array(
            'was'  => $slot ? $slot['cat'] : '',
            'wann' => $slot_id,
        ));

        if (!isset($_POST['rc_nonce']) || !wp_verify_nonce(wp_unslash($_POST['rc_nonce']), 'rc_book')) {
            wp_safe_redirect(add_query_arg('fehler', 'nonce', $back));
            exit;
        }

        $res = rc_create_booking($slot_id, mb_substr($note, 0, 300));
        if (!$res['ok']) {
            $target = ($res['err'] === 'weg') ? rc_booking_page_url() : $back;
            wp_safe_redirect(add_query_arg('fehler', $res['err'], $target));
            exit;
        }

        /*
         * Direkt in die Verwaltung statt auf eine Zwischenseite. Dort steht
         * alles, was die Zwischenseite auch zeigte - Termin, Code, das
         * freiwillige E-Mail-Feld, der Kalender-Knopf - und zusaetzlich
         * laesst sich der Termin gleich verschieben oder absagen. Ein Klick
         * weniger, und nichts geht verloren.
         */
        wp_safe_redirect(home_url('/termin-abrufen/?code=' . rawurlencode($res['code']) . '&neu=1'));
        exit;
    }, 5);

    /**
     * Die Seite zeigt, welche Termine frei sind - eine zwischengespeicherte
     * Fassung wuerde vergebene Termine als frei anbieten.
     */
    add_action('template_redirect', function () {
        if (!is_singular()) return;
        $content = (string) get_post_field('post_content', get_queried_object_id());
        if (!has_shortcode($content, 'repairffm_booking')) return;

        /*
         * Aeltere Lesezeichen und der Zurueck-Knopf zeigen noch auf
         * /termin-buchen/?code=... Ein Termin wird nur noch an einer
         * Stelle angezeigt, also dorthin weiterleiten.
         */
        if (!empty($_GET['code'])) {
            $code = sanitize_text_field(wp_unslash($_GET['code']));
            wp_safe_redirect(home_url('/termin-abrufen/?code=' . rawurlencode($code)), 301);
            exit;
        }

        nocache_headers();
    }, 6);

    /*
     * Auf der Buchungsseite steht ueber dem Shortcode ein Einleitungstext
     * ("Such dir einen freien Termin aus ..."). Auf Schritt 1 hilft er, ab
     * Schritt 2 steht er nur noch im Weg - besonders auf der Fertig-Seite,
     * wo er zum Aussuchen auffordert, obwohl schon gebucht ist.
     *
     * Deshalb ab Schritt 2 alles vor dem Shortcode weglassen. Der Text
     * bleibt im Seiten-Editor bearbeitbar, er wird nur nicht mehr ueberall
     * gezeigt.
     */
    add_filter('the_content', function ($content) {
        if (is_admin() || !in_the_loop() || !is_main_query()) return $content;
        if (!has_shortcode($content, 'repairffm_booking')) return $content;
        if (!isset($_GET['was']) && !isset($_GET['wann']) && !isset($_GET['code'])) return $content;

        $pos = strpos($content, '[repairffm_booking]');
        return $pos === false ? $content : substr($content, $pos);
    }, 5);

    add_shortcode('repairffm_booking', function () {

        $slot_id = isset($_GET['wann']) ? sanitize_text_field(wp_unslash($_GET['wann'])) : '';
        if ($slot_id !== '') {
            $slot = rc_slot_by_id($slot_id);
            if ($slot) return rc_step_confirm($slot);
        }

        $cat  = isset($_GET['was']) ? sanitize_key(wp_unslash($_GET['was'])) : '';
        $cats = rc_categories();
        if ($cat !== '' && isset($cats[$cat])) return rc_step_slots($cat);

        return rc_step_cats();
    });

    function rc_booking_error_html() {
        $key  = isset($_GET['fehler']) ? sanitize_key(wp_unslash($_GET['fehler'])) : '';
        $msgs = rc_booking_errors();
        if ($key === '' || !isset($msgs[$key])) return '';
        return '<p class="rc-error" role="alert">' . esc_html($msgs[$key]) . '</p>';
    }

    /* Zeigt, wo man im Ablauf steht - drei Schritte sind sonst schwer zu ueberblicken. */
    function rc_step_marker($aktiv) {
        $namen = array(1 => 'Was', 2 => 'Wann', 3 => 'Abschicken');
        $out = '<ol class="rc-steps" aria-label="Schritt ' . (int) $aktiv . ' von 3">';
        foreach ($namen as $nr => $name) {
            $klasse = $nr === $aktiv ? 'is-now' : ($nr < $aktiv ? 'is-done' : '');
            $out .= '<li class="' . $klasse . '"><span class="rc-step-nr">' . $nr . '</span>'
                  . '<span class="rc-step-name">' . esc_html($name) . '</span></li>';
        }
        return $out . '</ol>';
    }

    function rc_step_cats() {
        ob_start(); ?>
        <div class="rc-book">
          <?php echo rc_booking_error_html(); ?>
          <?php echo rc_step_marker(1); ?>
          <h2 class="rc-h">Was möchtest du reparieren?</h2>
          <?php $rc_cats = rc_categories(); ?>
          <?php if (!$rc_cats): ?>
            <?php // Alle Kategorien stillgelegt — dann lieber ein Satz als eine leere Fläche. ?>
            <p>Zurzeit nehmen wir keine Termine an. Schau in ein paar Tagen noch einmal vorbei.</p>
          <?php else: ?>
            <div class="rc-cats">
              <?php foreach ($rc_cats as $ck => $cl): ?>
                <a class="rc-cat" href="<?php echo esc_url(rc_booking_page_url(array('was' => $ck))); ?>"><?php echo esc_html($cl); ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    function rc_step_slots($cat) {
        $cats   = rc_categories();
        $booked = rc_booked_slot_ids();

        $days = array();
        foreach (rc_build_slots() as $s) {
            if ($s['cat'] !== $cat) continue;
            if (!isset($days[$s['date']])) $days[$s['date']] = array('label' => $s['dateLabel'], 'items' => array());
            $s['free'] = empty($booked[$s['id']]);
            $days[$s['date']]['items'][] = $s;
        }
        ksort($days);

        ob_start(); ?>
        <div class="rc-book">
          <p class="rc-back"><a href="<?php echo esc_url(rc_booking_page_url()); ?>">&larr; andere Kategorie</a></p>
          <?php echo rc_booking_error_html(); ?>
          <?php echo rc_step_marker(2); ?>
          <h2 class="rc-h">Freien Termin wählen</h2>
          <p class="rc-chosen"><?php echo esc_html($cats[$cat]); ?></p>
          <?php if (!$days): ?>
            <p>Zurzeit sind keine Termine frei. Schau in ein paar Tagen noch einmal vorbei.</p>
          <?php endif; ?>
          <?php foreach ($days as $day): ?>
            <div class="rc-daygroup">
              <h3><?php echo esc_html($day['label']); ?></h3>
              <div class="rc-slotgrid">
                <?php foreach ($day['items'] as $s): ?>
                  <?php if ($s['free']): ?>
                    <a class="rc-slot" href="<?php echo esc_url(rc_booking_page_url(array('was' => $cat, 'wann' => $s['id']))); ?>"><?php echo esc_html($s['time']); ?> Uhr</a>
                  <?php else: ?>
                    <span class="rc-slot is-taken" title="schon vergeben"><?php echo esc_html($s['time']); ?> Uhr</span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php return ob_get_clean();
    }

    function rc_step_confirm($slot) {
        $cats = rc_categories();
        ob_start(); ?>
        <div class="rc-book">
          <p class="rc-back"><a href="<?php echo esc_url(rc_booking_page_url(array('was' => $slot['cat']))); ?>">&larr; anderer Termin</a></p>
          <?php echo rc_booking_error_html(); ?>
          <?php echo rc_step_marker(3); ?>
          <h2 class="rc-h">Anfrage abschicken</h2>
          <p class="rc-summary"><?php echo esc_html($cats[$slot['cat']] . ' — ' . $slot['dateLabel'] . ', ' . $slot['time'] . ' Uhr'); ?></p>
          <form method="post" action="<?php echo esc_url(rc_booking_page_url(array('was' => $slot['cat'], 'wann' => $slot['id']))); ?>">
            <?php wp_nonce_field('rc_book', 'rc_nonce'); ?>
            <input type="hidden" name="slot" value="<?php echo esc_attr($slot['id']); ?>">
            <label class="rc-note-label" for="rc-note">Was ist kaputt? <span>(freiwillig)</span></label>
            <textarea id="rc-note" name="note" rows="3" maxlength="300" placeholder="z. B. 'Handy-Akku hält nicht mehr' oder 'E-Bike bremst schleift'"></textarea>
            <button type="submit" name="rc_book_submit" value="1" class="rc-btn">Termin anfragen</button>
            <p class="rc-hint">Noch nicht bestätigt — wir sehen uns die Anfrage an und melden uns.</p>
          </form>
        </div>
        <?php return ob_get_clean();
    }

    /*
     * Nur auf der Buchungsseite ausgeben, und im Kopf statt mitten im Text.
     *
     * `rfat_manage_booking` zählt mit: Beim Verschieben blendet diese Seite
     * die komplette Buchung ein (do_shortcode). Ohne die zweite Zeile stünden
     * dort nackte Links statt Terminknöpfen - und die Fehlermeldung der
     * Buchungssperre käme ohne ihren roten Kasten an.
     */
    add_action('wp_head', function () {
        if (!is_singular()) return;
        $content = (string) get_post_field('post_content', get_queried_object_id());
        if (!has_shortcode($content, 'repairffm_booking')
            && !has_shortcode($content, 'rfat_manage_booking')) return;
        ?>
        <?php ob_start(); ?>
        <style>
          .rc-book{max-width:620px}
          .rc-h{margin:0 0 14px;font-size:22px}
          .rc-back{margin:0 0 10px}
          .rc-back a{color:#1f5a38;font-weight:600;text-decoration:none;font-size:15px}
          .rc-back a:hover{text-decoration:underline}
          .rc-cats{display:grid;gap:12px}
          .rc-cat{display:block;background:#e8f1eb;border:2px solid transparent;border-radius:12px;padding:18px;
            font-size:17px;font-weight:700;color:#1c2a22;text-decoration:none}
          .rc-cat:hover,.rc-cat:focus{border-color:#2f7d4f;color:#1c2a22}
          .rc-chosen{color:#5b6b62;margin:0 0 18px}
          .rc-daygroup{margin-bottom:18px}
          .rc-daygroup h3{margin:0 0 8px;font-size:15px;color:#5b6b62;font-weight:600}
          .rc-slotgrid{display:flex;flex-wrap:wrap;gap:8px}
          .rc-slot{display:inline-block;background:#fff;border:2px solid #2f7d4f;color:#1f5a38;border-radius:10px;
            padding:11px 16px;font-weight:700;font-size:16px;text-decoration:none;min-height:44px;line-height:20px;box-sizing:border-box}
          .rc-slot:hover,.rc-slot:focus{background:#2f7d4f;color:#fff}
          .rc-slot.is-taken{border-color:#dfe4e0;color:#b7c1ba;background:#f4f6f4;text-decoration:line-through;cursor:not-allowed}
          .rc-slot.is-taken:hover{background:#f4f6f4;color:#b7c1ba}
          .rc-summary{background:#e8f1eb;border-radius:10px;padding:12px 14px;font-weight:600;margin:0 0 16px}
          .rc-note-label{display:block;margin:6px 0 6px;font-weight:600}
          .rc-note-label span{font-weight:400;color:#5b6b62;font-size:14px}
          #rc-note{width:100%;border:1px solid #cfd8d2;border-radius:10px;padding:10px;font:inherit;margin-bottom:16px;box-sizing:border-box}
          .rc-btn{display:inline-block;background:#2f7d4f;color:#fff;border:0;border-radius:10px;padding:14px 22px;
            font-size:17px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
          .rc-btn:hover,.rc-btn:focus{background:#1f5a38;color:#fff}
          .rc-hint{color:#5b6b62;font-size:14.5px;margin-top:12px}
          .rc-error{background:#fdecec;border-left:4px solid #a12;color:#7a1010;font-weight:600;padding:12px 14px;
            border-radius:8px;margin:0 0 16px}
          /* Gezeichneter Haken statt Emoji: das ✅ rendert auf iOS als klobiger
             gruener Kasten und passte nicht zur uebrigen Seite. */
          .rc-ok{width:52px;height:52px;border-radius:50%;background:#2f7d4f;position:relative;margin-bottom:14px}
          .rc-ok::after{content:"";position:absolute;left:18px;top:11px;width:12px;height:24px;
            border:solid #fff;border-width:0 3.5px 3.5px 0;transform:rotate(45deg)}

          /* Schrittanzeige */
          .rc-steps{display:flex;gap:8px;list-style:none;margin:0 0 18px;padding:0;counter-reset:none}
          .rc-steps li{display:flex;align-items:center;gap:7px;font-size:13px;color:#9aa8a0;flex:0 1 auto}
          .rc-steps li+li::before{content:"";display:block;width:14px;height:1px;background:#dfe4e0;margin-right:1px}
          .rc-step-nr{display:grid;place-items:center;width:23px;height:23px;border-radius:50%;
            background:#eef2ef;color:#9aa8a0;font-size:12px;font-weight:700;flex-shrink:0}
          .rc-steps li.is-now{color:#1c2a22;font-weight:600}
          .rc-steps li.is-now .rc-step-nr{background:#2f7d4f;color:#fff}
          .rc-steps li.is-done{color:#5b6b62}
          .rc-steps li.is-done .rc-step-nr{background:#cde0d4;color:#1f5a38}
          .rc-code{font-size:24px;letter-spacing:1px;color:#1f5a38}
          .rc-again{margin-top:26px}
          .rc-again a{color:#1f5a38;font-weight:600}
          @media(max-width:600px){
            .rc-steps li:not(.is-now) .rc-step-name{display:none}
            .rc-slot{flex:1 1 calc(50% - 8px);text-align:center}
            .rc-btn{width:100%;text-align:center}
          }
        </style>
        <?php echo rfat_minify_style_tags(ob_get_clean()); ?>
        <?php
    });

    /* ---------------------------------------------------------------
     * 5) Admin-Spalten fuer Buchungen
     * ------------------------------------------------------------- */
    add_filter('manage_rc_booking_posts_columns', function ($cols) {
        return array(
            'cb'=>$cols['cb'],
            'title'=>'Buchung',
            'rc_cat'=>'Kategorie',
            'rc_when'=>'Termin',
            'rc_note'=>'Problem',
            'rc_code'=>'Code',
            'date'=>'Gebucht am',
        );
    });
    add_action('manage_rc_booking_posts_custom_column', function ($col, $pid) {
        $cats = rc_categories();
        if ($col==='rc_cat') { $c=get_post_meta($pid,'_rc_cat',true); echo esc_html(isset($cats[$c])?$cats[$c]:$c); }
        if ($col==='rc_when') { echo esc_html(get_post_meta($pid,'_rc_date',true).' '.get_post_meta($pid,'_rc_time',true)); }
        if ($col==='rc_note') { echo esc_html(get_post_meta($pid,'_rc_note',true)); }
        if ($col==='rc_code') { echo '<code>'.esc_html(get_post_meta($pid,'_rc_code',true)).'</code>'; }
    }, 10, 2);

    /* ---------------------------------------------------------------
     * 6) Einmalige Einrichtung: Seiten, Permalinks
     * ------------------------------------------------------------- */
    add_action('init', function () {
        if (get_option('rc_setup_version') === '4') return;

        update_option('blogname', 'Repair FFM');
        update_option('blogdescription', 'Reparieren statt Wegwerfen – ehrenamtlich, kostenlos, für alle.');
        update_option('permalink_structure', '/%postname%/');

        $imp = <<<'RCHTML'
    <p>Angaben gemäß § 5 Digitale-Dienste-Gesetz (DDG):</p>
    <p><strong>Repair FFM</strong><br>Till Polzin<br>Hermannspforte 54<br>60437 Frankfurt am Main</p>
    <p><strong>Kontakt:</strong><br>E-Mail: repair.ffm@outlook.com</p>
    <h2>Verantwortlich für den Inhalt</h2>
    <p>Till Polzin, Anschrift wie oben</p>
    <h2>Art des Angebots</h2>
    <p>Repair FFM ist ein ehrenamtliches Reparatur-Angebot ohne Gewinnabsicht. Es werden weder Waren noch Dienstleistungen verkauft; für Reparaturen wird kein Entgelt erhoben. Die Hilfe erfolgt unentgeltlich und ohne Gewährleistung. Eine Umsatzsteuer-Identifikationsnummer besteht mangels wirtschaftlicher Tätigkeit nicht.</p>
    <h2>Verbraucherstreitbeilegung</h2>
    <p>Wir sind nicht verpflichtet und nicht bereit, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>
    <h2>Haftung bei Reparaturen</h2>
    <p>Die Reparaturhilfe erfolgt unentgeltlich, ehrenamtlich und ohne Auftragsverhältnis. Es kommt kein Werkvertrag zustande; ein Reparaturerfolg wird nicht geschuldet.</p>
    <p>Alle Arbeiten werden gemeinsam mit dir an deinem eigenen Gerät durchgeführt. Die Entscheidung, ob und wie an einem Gerät gearbeitet wird, triffst du selbst; das Gerät bleibt in deiner Verantwortung.</p>
    <p>Für Schäden am Gerät oder Folgeschäden haften wir nur bei Vorsatz und grober Fahrlässigkeit. Von dieser Beschränkung ausgenommen sind Schäden aus der Verletzung des Lebens, des Körpers oder der Gesundheit.</p>
    <p>Bitte sichere vor deinem Besuch alle Daten auf dem Gerät. Für Datenverlust können wir keine Haftung übernehmen.</p>
    <h2>Haftung für Inhalte</h2>
    <p>Als Diensteanbieter sind wir gemäß § 7 Abs. 1 DDG für eigene Inhalte auf diesen Seiten nach den allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 DDG sind wir jedoch nicht verpflichtet, übermittelte oder gespeicherte fremde Informationen zu überwachen oder nach Umständen zu forschen, die auf eine rechtswidrige Tätigkeit hinweisen. Eine Haftung ist erst ab Kenntnis einer konkreten Rechtsverletzung möglich; bei Bekanntwerden entfernen wir die Inhalte umgehend.</p>
    <h2>Haftung für Links</h2>
    <p>Unser Angebot enthält Links zu externen Websites Dritter, auf deren Inhalte wir keinen Einfluss haben. Für die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter oder Betreiber verantwortlich. Zum Zeitpunkt der Verlinkung waren keine Rechtsverstöße erkennbar. Bei Bekanntwerden von Rechtsverletzungen entfernen wir derartige Links umgehend.</p>
    <h2>Urheberrecht</h2>
    <p>Die durch den Betreiber erstellten Inhalte und Werke auf diesen Seiten unterliegen dem deutschen Urheberrecht. Vervielfältigung, Bearbeitung, Verbreitung und jede Art der Verwertung außerhalb der Grenzen des Urheberrechts bedürfen der schriftlichen Zustimmung. Downloads und Kopien dieser Seite sind nur für den privaten, nicht kommerziellen Gebrauch gestattet. Sollten Sie auf eine Urheberrechtsverletzung aufmerksam werden, bitten wir um einen Hinweis.</p>
    RCHTML;

        $dsg = <<<'RCHTML'
    <p>Wir halten diese Seite bewusst datensparsam. Diese Erklärung informiert nach Art. 13 DSGVO über die Verarbeitung personenbezogener Daten.</p>
    <h2>Verantwortlicher</h2>
    <p>Verantwortlich im Sinne der DSGVO ist die im <a href="/impressum/">Impressum</a> genannte Stelle.</p>
    <h2>Grundsatz: keine Tracker</h2>
    <ul><li>Kein Analytics, keine Zählpixel, keine Werbe- oder Social-Media-Tracker.</li><li>Keine externen Ressourcen (z. B. keine von Google geladenen Schriften) – alle Dateien kommen vom eigenen Server.</li><li>Keine Weitergabe von Daten zu Werbezwecken, keine Profilbildung.</li></ul>
    <h2>Terminbuchung (anonym)</h2>
    <p>Die Buchung erfolgt ohne Anmeldung und ohne Benutzerkonto. <strong>Erforderlich sind weder Name noch Anschrift noch Telefonnummer.</strong> Gespeichert werden der gewählte Termin, die Kategorie (z. B. IT oder E-Bike), ein automatisch erzeugter Buchungscode und – nur wenn du sie freiwillig einträgst – eine kurze Problembeschreibung. Rechtsgrundlage ist unser berechtigtes Interesse an der Organisation der Reparatur-Termine (Art. 6 Abs. 1 lit. f DSGVO), bei freiwilligen Angaben deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Die Buchungsdaten werden nach dem jeweiligen Termin gelöscht. Ohne freiwillige Angabe einer E-Mail-Adresse lassen sich Buchungen keiner Person zuordnen.</p>
    <h2>Freiwillige Benachrichtigung per E-Mail</h2>
    <p>Du kannst freiwillig eine E-Mail-Adresse hinterlegen, um über die Bestätigung und den weiteren Verlauf deines Termins informiert zu werden. Diese Angabe ist <strong>nicht erforderlich</strong>, um einen Termin zu buchen oder zu verwalten; ohne sie ändert sich nichts.</p>
    <p><strong>Rechtsgrundlage:</strong> deine Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).</p>
    <p><strong>Speicherdauer:</strong> Die Adresse wird nach deinem Termin automatisch gelöscht. Setzt du zusätzlich das Häkchen „Meine Angaben dürfen auch nach dem Termin gespeichert bleiben", bleibt sie gespeichert, bis du sie selbst wieder löschst. Stornierst du deinen Termin, wird sie sofort gelöscht.</p>
    <p><strong>Widerruf:</strong> Du kannst deine Einwilligung jederzeit und ohne Angabe von Gründen widerrufen. Rufe dazu deinen Termin unter <a href="/termin-abrufen/">Termin abrufen</a> mit deinem Buchungscode auf, leere das E-Mail-Feld und speichere; alternativ nutzt du den Abmeldelink in jeder Nachricht. Die Rechtmäßigkeit der bis zum Widerruf erfolgten Verarbeitung bleibt unberührt.</p>
    <p><strong>Empfänger:</strong> Die Adresse wird nicht an Dritte weitergegeben und nicht für Werbung verwendet. Für den Versand der Nachrichten setzen wir einen E-Mail-Dienstleister als Auftragsverarbeiter ein; dieser wird hier benannt, sobald er eingerichtet ist.</p>
    <!--RFAT_SIGNAL-->
    <!--RFAT_FRAGEN-->
    <!--RFAT_MISSBRAUCH-->
    <h2>Cookies</h2>
    <!--RFAT_COOKIES-->
    <h2>Server-Protokolle</h2>
    <p>Beim Aufruf verarbeitet der Betrieb technisch bedingt Zugriffsdaten (IP-Adresse, Datum/Uhrzeit, aufgerufene Seite, Datenmenge, Browsertyp). Diese dienen ausschließlich dem sicheren, stabilen Betrieb und der Abwehr von Angriffen (Art. 6 Abs. 1 lit. f DSGVO) und werden nur kurz gespeichert.</p>
    <h2>Auslieferung über Cloudflare (CDN)</h2>
    <p>Die Website wird über Cloudflare, Inc. (101 Townsend St, San Francisco, CA 94107, USA) als Content-Delivery-Network und Reverse-Proxy ausgeliefert. Dabei verarbeitet Cloudflare technisch notwendige Verbindungsdaten (insbesondere die IP-Adresse) und kann ein technisch notwendiges Sicherheits-Cookie (z. B. <code>__cf_bm</code> zur Abwehr automatisierter Zugriffe, kurze Laufzeit) setzen. Dies dient der sicheren und performanten Bereitstellung (Art. 6 Abs. 1 lit. f DSGVO). Eine etwaige Übermittlung in die USA erfolgt auf Grundlage des EU-US Data Privacy Framework bzw. der EU-Standardvertragsklauseln. Cloudflare ist als Auftragsverarbeiter tätig. Weitere Informationen: <a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener">cloudflare.com/privacypolicy</a>.</p>
    <h2>Deine Rechte</h2>
    <p>Du hast das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21 DSGVO). Außerdem besteht ein Beschwerderecht bei einer Aufsichtsbehörde, z. B. der/dem Hessischen Beauftragten für Datenschutz und Informationsfreiheit.</p>
    <h2>Kontakt in Datenschutzfragen</h2>
    <p>Wende dich an die im <a href="/impressum/">Impressum</a> genannte Stelle.</p>
    <p><em>Stand: August 2026.</em></p>
    RCHTML;

        // Cookie-Absatz aus einer Hand — siehe Abschnitt KENNWORTSPERRE ENTFERNEN.
        $dsg = str_replace('<!--RFAT_COOKIES-->', RFAT_COOKIE_ABSATZ, $dsg);
        $dsg = str_replace('<!--RFAT_MISSBRAUCH-->', RFAT_MISSBRAUCH_ABSATZ, $dsg);
        $dsg = str_replace('<!--RFAT_SIGNAL-->', RFAT_SIGNAL_ABSATZ, $dsg);
        $dsg = str_replace('<!--RFAT_FRAGEN-->', RFAT_FRAGEN_ABSATZ, $dsg);

        $pages = array(
            'termin-buchen' => array('Termin buchen',
                "<p>Such dir einen freien Termin aus &ndash; ohne Konto und ohne Namen. Du bekommst einen Buchungscode angezeigt. Möchtest du über die Bestätigung benachrichtigt werden, kannst du danach freiwillig eine E-Mail oder deinen Signal-Benutzernamen hinterlassen &ndash; nötig ist beides nicht.</p>\n[repairffm_booking]"),
            'termine-ort' => array('Termine & Ort',
                "<p>Wir treffen uns regelmäßig zum gemeinsamen Reparieren. Die aktuell buchbaren Termine findest du unter <a href=\"/termin-buchen/\">Termin buchen</a>.</p>\n<h2>Ort</h2>\n<p><em>[Bitte Veranstaltungsort / Adresse hier eintragen.]</em></p>\n<h2>Was mitbringen?</h2>\n<ul><li>Dein defektes Gerät (Handy, Laptop, IT) oder E-Bike</li><li>Passendes Ladegerät / Netzteil</li><li>Zugangscode bzw. Passwort bei IT-Geräten</li><li>Bei E-Bikes: Akkuschlüssel</li></ul>"),
            'mitmachen' => array('Mitmachen',
                "<h2>Werde Teil des Teams</h2>\n<p>Repair FFM lebt vom Ehrenamt. Du schraubst gern, lötest, flashst Handys oder kennst dich mit E-Bikes aus? Komm dazu &ndash; egal ob Profi oder neugierige:r Einsteiger:in.</p>\n<p>Alles ist ehrenamtlich und ohne Gewinnabsicht. Wir freuen uns über jede helfende Hand.</p>\n<p><em>[Kontaktweg zum Mitmachen hier eintragen &ndash; z. B. beim nächsten Termin vorbeikommen.]</em></p>"),
            'impressum' => array('Impressum', $imp),
            'datenschutz' => array('Datenschutz', $dsg),
        );

        foreach ($pages as $slug => $pd) {
            $existing = get_page_by_path($slug);
            if ($existing) {
                wp_update_post(array('ID'=>$existing->ID,'post_title'=>$pd[0],'post_content'=>$pd[1]));
            } else {
                wp_insert_post(array('post_title'=>$pd[0],'post_name'=>$slug,'post_content'=>$pd[1],'post_status'=>'publish','post_type'=>'page'));
            }
        }

        flush_rewrite_rules(false);
        update_option('rc_setup_version', '4');
    }, 20);

}
