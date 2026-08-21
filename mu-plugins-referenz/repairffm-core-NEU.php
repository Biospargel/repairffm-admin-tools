<?php
/*
Plugin Name: Repair FFM – Core (Buchung & Seiten)
Description: Datensparsame Terminbuchung ohne Konto (kein Tracking, keine Cookies; E-Mail nur freiwillig) + Grundseiten.
Version: 1.0
*/
if (!defined('ABSPATH')) exit;

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
function rc_categories() {
    return array(
        'it'    => 'IT & Elektronik (Handy · Laptop · IT)',
        'ebike' => 'E-Bike-Service',
    );
}
function rc_slot_times($cat) {
    if ($cat === 'ebike') return array('14:00','15:00','16:00');
    return array('14:00','14:30','15:00','15:30','16:00','16:30');
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
    $ids = array();
    $q = get_posts(array('post_type'=>'rc_booking','numberposts'=>-1,'post_status'=>'publish','fields'=>'ids'));
    foreach ($q as $pid) {
        $s = get_post_meta($pid, '_rc_slot', true);
        if ($s) $ids[$s] = true;
    }
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

function rc_booking_by_code($code) {
    $code = strtoupper(trim((string) $code));
    if ($code === '') return null;
    $q = get_posts(array(
        'post_type'   => 'rc_booking',
        'numberposts' => 1,
        'post_status' => 'publish',
        'meta_key'    => '_rc_code',
        'meta_value'  => $code,
    ));
    return $q ? $q[0] : null;
}

/**
 * Fehlermeldungen als feste Schluessel statt als Text in der Adresse:
 * sonst koennte ueber einen praeparierten Link ein beliebiger Satz auf
 * der eigenen Seite erscheinen.
 */
function rc_booking_errors() {
    return array(
        'nonce'     => 'Die Sicherheitsprüfung ist fehlgeschlagen. Das passiert meist, wenn die Seite lange offen lag. Bitte den Termin noch einmal auswählen.',
        'weg'       => 'Diesen Termin gibt es nicht mehr. Bitte einen anderen wählen.',
        'belegt'    => 'Dieser Termin ist gerade vergeben worden. Bitte einen anderen wählen.',
        'speichern' => 'Das Speichern hat nicht geklappt. Bitte noch einmal versuchen.',
    );
}

/**
 * Legt die Buchung an. Gibt array('ok'=>true,'code'=>...) zurueck oder
 * array('ok'=>false,'err'=>'<schluessel>').
 */
function rc_create_booking($slot_id, $note) {
    $slot = rc_slot_by_id($slot_id);
    if (!$slot) return array('ok' => false, 'err' => 'weg');

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

    wp_safe_redirect(rc_booking_page_url(array('code' => $res['code'])));
    exit;
}, 5);

/**
 * Die Seite zeigt, welche Termine frei sind - eine zwischengespeicherte
 * Fassung wuerde vergebene Termine als frei anbieten.
 */
add_action('template_redirect', function () {
    if (!is_singular()) return;
    $content = (string) get_post_field('post_content', get_queried_object_id());
    if (has_shortcode($content, 'repairffm_booking')) nocache_headers();
}, 6);

add_shortcode('repairffm_booking', function () {

    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
    if ($code !== '') return rc_step_done($code);

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

function rc_step_cats() {
    ob_start(); ?>
    <div class="rc-book">
      <?php echo rc_booking_error_html(); ?>
      <h2 class="rc-h">1. Was möchtest du reparieren?</h2>
      <div class="rc-cats">
        <?php foreach (rc_categories() as $ck => $cl): ?>
          <a class="rc-cat" href="<?php echo esc_url(rc_booking_page_url(array('was' => $ck))); ?>"><?php echo esc_html($cl); ?></a>
        <?php endforeach; ?>
      </div>
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
      <h2 class="rc-h">2. Freien Termin wählen</h2>
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
      <h2 class="rc-h">3. Anfrage abschicken</h2>
      <p class="rc-summary"><?php echo esc_html($cats[$slot['cat']] . ' — ' . $slot['dateLabel'] . ', ' . $slot['time'] . ' Uhr'); ?></p>
      <form method="post" action="<?php echo esc_url(rc_booking_page_url(array('was' => $slot['cat'], 'wann' => $slot['id']))); ?>">
        <?php wp_nonce_field('rc_book', 'rc_nonce'); ?>
        <input type="hidden" name="slot" value="<?php echo esc_attr($slot['id']); ?>">
        <label class="rc-note-label" for="rc-note">Kurz: Was ist kaputt? <span>(freiwillig, keine persönlichen Angaben nötig)</span></label>
        <textarea id="rc-note" name="note" rows="3" maxlength="300" placeholder="z. B. 'Handy-Akku hält nicht mehr' oder 'E-Bike bremst schleift'"></textarea>
        <button type="submit" name="rc_book_submit" value="1" class="rc-btn">Termin anfragen</button>
        <p class="rc-hint">Der Termin ist damit vorgemerkt, aber noch nicht bestätigt. Wir sehen uns die Anfrage an und melden uns.</p>
      </form>
    </div>
    <?php return ob_get_clean();
}

function rc_step_done($code) {
    $post = rc_booking_by_code($code);
    if (!$post) {
        ob_start(); ?>
        <div class="rc-book">
          <p class="rc-error">Zu diesem Code haben wir keine Anfrage gefunden. Möglicherweise ist der Termin schon vorbei und die Daten wurden gelöscht.</p>
          <p><a class="rc-btn" href="<?php echo esc_url(rc_booking_page_url()); ?>">Neuen Termin wählen</a></p>
        </div>
        <?php return ob_get_clean();
    }

    $cats = rc_categories();
    $cat  = get_post_meta($post->ID, '_rc_cat', true);
    $date = get_post_meta($post->ID, '_rc_date', true);
    $time = get_post_meta($post->ID, '_rc_time', true);
    $when = '';
    if ($date) {
        $d = date_create($date);
        if ($d) $when = rc_weekday_de($d->format('w')) . ', ' . $d->format('d.m.Y');
    }

    ob_start(); ?>
    <div class="rc-book rc-done">
      <div class="rc-ok">✅</div>
      <h2 class="rc-h">Termin vorgemerkt</h2>
      <p class="rc-summary"><?php
        $teile = array();
        if (isset($cats[$cat])) $teile[] = $cats[$cat];
        if ($when !== '')       $teile[] = $when . ($time ? ', ' . $time . ' Uhr' : '');
        echo esc_html($teile ? implode(' — ', $teile) : 'Termin gespeichert');
      ?></p>
      <p>Dein Buchungscode: <strong class="rc-code"><?php echo esc_html(get_post_meta($post->ID, '_rc_code', true)); ?></strong></p>
      <p class="rc-hint">Bitte warte noch auf unsere Bestätigung &ndash; erst dann steht dein Termin fest.
        Notiere dir den Code oder mach einen Screenshot; damit kommst du jederzeit wieder an deinen Termin.</p>

      <?php
      /* Anknuepfpunkt fuer das Begleit-Plugin: dort haengt das freiwillige
       * E-Mail-Feld und der Kalender-Knopf. Ist es nicht aktiv, steht hier
       * einfach nichts - die Buchung ist trotzdem vollstaendig. */
      do_action('repairffm_after_booking', get_post_meta($post->ID, '_rc_code', true), $post->ID);
      ?>

      <p class="rc-again"><a href="<?php echo esc_url(rc_booking_page_url()); ?>">Weiteren Termin anfragen</a></p>
    </div>
    <?php return ob_get_clean();
}

/* Nur auf der Buchungsseite ausgeben, und im Kopf statt mitten im Text. */
add_action('wp_head', function () {
    if (!is_singular()) return;
    $content = (string) get_post_field('post_content', get_queried_object_id());
    if (!has_shortcode($content, 'repairffm_booking')) return;
    ?>
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
      .rc-ok{font-size:44px;line-height:1}
      .rc-code{font-size:24px;letter-spacing:1px;color:#1f5a38}
      .rc-again{margin-top:26px}
      .rc-again a{color:#1f5a38;font-weight:600}
      @media(max-width:600px){
        .rc-slot{flex:1 1 calc(50% - 8px);text-align:center}
        .rc-btn{width:100%;text-align:center}
      }
    </style>
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
<p><strong>Speicherdauer:</strong> Die Adresse wird nach deinem Termin automatisch gelöscht. Setzt du zusätzlich das Häkchen „Meine Adresse darf auch nach dem Termin gespeichert bleiben", bleibt sie gespeichert, bis du sie selbst wieder löschst. Stornierst du deinen Termin, wird sie sofort gelöscht.</p>
<p><strong>Widerruf:</strong> Du kannst deine Einwilligung jederzeit und ohne Angabe von Gründen widerrufen. Rufe dazu deinen Termin unter <a href="/termin-abrufen/">Termin abrufen</a> mit deinem Buchungscode auf, leere das E-Mail-Feld und speichere; alternativ nutzt du den Abmeldelink in jeder Nachricht. Die Rechtmäßigkeit der bis zum Widerruf erfolgten Verarbeitung bleibt unberührt.</p>
<p><strong>Empfänger:</strong> Die Adresse wird nicht an Dritte weitergegeben und nicht für Werbung verwendet. Für den Versand der Nachrichten setzen wir einen E-Mail-Dienstleister als Auftragsverarbeiter ein; dieser wird hier benannt, sobald er eingerichtet ist.</p>
<h2>Cookies</h2>
<p>Während der geschlossenen Phase setzt der Kennwortschutz einen technisch notwendigen Cookie (<code>rc_access</code>), der nur merkt, dass das Zugangskennwort korrekt eingegeben wurde (Laufzeit rund 30 Tage, kein Tracking). Für Team-Mitglieder setzt WordPress beim Login in den Verwaltungsbereich technisch notwendige Cookies. Für normale Besucher:innen werden darüber hinaus keine Cookies gesetzt. Rechtsgrundlage: § 25 Abs. 2 TDDDG i. V. m. Art. 6 Abs. 1 lit. f DSGVO.</p>
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

    $pages = array(
        'termin-buchen' => array('Termin buchen',
            "<p>Such dir einen freien Termin aus &ndash; ohne Konto und ohne Namen. Du bekommst einen Buchungscode angezeigt. Möchtest du über die Bestätigung benachrichtigt werden, kannst du danach freiwillig eine E-Mail hinterlassen &ndash; nötig ist sie nicht.</p>\n[repairffm_booking]"),
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
