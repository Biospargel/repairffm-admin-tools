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
 * 3) Shortcode [repairffm_booking] – Button + Popup
 * ------------------------------------------------------------- */
add_shortcode('repairffm_booking', function () {
    $GLOBALS['rc_booking_used'] = true;
    return '<div id="rc-book"><button class="btn big" id="rc-open">📅 Termin buchen</button>'
         . '<noscript><p>Bitte aktiviere JavaScript, um einen Termin zu buchen.</p></noscript></div>';
});

// Popup + Assets erst im Footer ausgeben, damit wpautop die Struktur nicht zerstoert
add_action('wp_footer', function () {
    if (empty($GLOBALS['rc_booking_used'])) return;
    $slots = rc_build_slots();
    $booked = rc_booked_slot_ids();
    foreach ($slots as &$s) { $s['free'] = empty($booked[$s['id']]); }
    unset($s);
    $data = array(
        'nonce' => wp_create_nonce('rc_book'),
        'ajax'  => admin_url('admin-ajax.php'),
        'cats'  => rc_categories(),
        'slots' => $slots,
    );
    $json = wp_json_encode($data);
    ?>
    <div class="rc-overlay" id="rc-overlay" hidden>
      <div class="rc-modal" role="dialog" aria-modal="true" aria-label="Termin buchen">
        <button class="rc-close" id="rc-close" aria-label="Schließen">×</button>
        <div class="rc-steps">
          <div class="rc-step" data-step="1">
            <h3>1. Was möchtest du reparieren?</h3>
            <div class="rc-cats"></div>
          </div>
          <div class="rc-step" data-step="2" hidden>
            <button class="rc-back" data-to="1">&larr; zurück</button>
            <h3>2. Freien Termin wählen</h3>
            <div class="rc-slots"></div>
          </div>
          <div class="rc-step" data-step="3" hidden>
            <button class="rc-back" data-to="2">&larr; zurück</button>
            <h3>3. Bestätigen</h3>
            <p class="rc-summary"></p>
            <label class="rc-note-label">Kurz: Was ist kaputt? <span>(freiwillig, keine persönlichen Angaben nötig)</span></label>
            <textarea id="rc-note" rows="3" placeholder="z. B. 'Handy-Akku hält nicht mehr' oder 'E-Bike bremst schleift'"></textarea>
            <button class="btn" id="rc-confirm">Verbindlich buchen</button>
            <p class="rc-error" hidden></p>
          </div>
          <div class="rc-step" data-step="done" hidden>
            <div class="rc-ok">✅</div>
            <h3>Termin vorgemerkt</h3>
            <p class="rc-done-summary"></p>
            <p>Dein Buchungscode: <strong class="rc-code"></strong></p>
            <p class="rc-hint">Bitte warte noch auf unsere Bestätigung &ndash; erst dann steht dein Termin fest.
              Notiere dir den Code (oder mach einen Screenshot). Möchtest du benachrichtigt werden,
              kannst du unten freiwillig eine E-Mail hinterlassen. Bis bald! 🔧</p>
            <button class="btn ghost" id="rc-again">Weiteren Termin buchen</button>
          </div>
        </div>
      </div>
    </div>

    <style>
      .rc-overlay{position:fixed;inset:0;background:rgba(20,35,28,.55);align-items:center;justify-content:center;padding:16px;z-index:1000}
      .rc-overlay:not([hidden]){display:flex}
      .rc-modal{background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:90vh;overflow:auto;padding:26px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.3)}
      .rc-close{position:absolute;top:12px;right:14px;border:0;background:transparent;font-size:30px;line-height:1;cursor:pointer;color:#5b6b62}
      .rc-modal h3{margin:0 0 16px;font-size:21px}
      .rc-back{background:transparent;border:0;color:#1f5a38;font-weight:600;cursor:pointer;padding:0 0 12px;font-size:15px}
      .rc-cats{display:grid;gap:12px}
      .rc-cat{display:block;width:100%;text-align:left;background:#e8f1eb;border:2px solid transparent;border-radius:12px;padding:18px;font-size:17px;font-weight:700;color:#1c2a22;cursor:pointer}
      .rc-cat:hover{border-color:#2f7d4f}
      .rc-daygroup{margin-bottom:18px}
      .rc-daygroup h4{margin:0 0 8px;font-size:15px;color:#5b6b62}
      .rc-slotgrid{display:flex;flex-wrap:wrap;gap:8px}
      .rc-slot{background:#fff;border:2px solid #2f7d4f;color:#1f5a38;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer;font-size:15px}
      .rc-slot:hover{background:#2f7d4f;color:#fff}
      .rc-slot[disabled]{border-color:#dfe4e0;color:#b7c1ba;background:#f4f6f4;cursor:not-allowed;text-decoration:line-through}
      .rc-note-label{display:block;margin:6px 0 6px;font-weight:600}
      .rc-note-label span{font-weight:400;color:#5b6b62;font-size:14px}
      #rc-note{width:100%;border:1px solid #cfd8d2;border-radius:10px;padding:10px;font:inherit;margin-bottom:16px}
      .rc-summary,.rc-done-summary{background:#e8f1eb;border-radius:10px;padding:12px 14px;font-weight:600}
      .rc-error{color:#a12; font-weight:600}
      .rc-ok{font-size:44px}
      .rc-code{font-size:22px;letter-spacing:1px;color:#1f5a38}
      .rc-hint{color:#5b6b62;font-size:14.5px}
    </style>

    <script>
    (function(){
      var D = <?php echo $json; ?>;
      var overlay = document.getElementById('rc-overlay');
      var openBtn = document.getElementById('rc-open');
      var pick = { cat:null, slot:null };

      function show(step){
        overlay.querySelectorAll('.rc-step').forEach(function(s){ s.hidden = (s.getAttribute('data-step') !== step); });
      }
      function open(){ overlay.hidden = false; buildCats(); show('1'); }
      function close(){ overlay.hidden = true; }

      openBtn && openBtn.addEventListener('click', open);
      document.getElementById('rc-close').addEventListener('click', close);
      overlay.addEventListener('click', function(e){ if(e.target===overlay) close(); });
      overlay.querySelectorAll('.rc-back').forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-to')); }); });

      function buildCats(){
        var wrap = overlay.querySelector('.rc-cats'); wrap.innerHTML='';
        Object.keys(D.cats).forEach(function(ck){
          var b=document.createElement('button'); b.className='rc-cat'; b.textContent=D.cats[ck];
          b.addEventListener('click', function(){ pick.cat=ck; buildSlots(ck); show('2'); });
          wrap.appendChild(b);
        });
      }
      function buildSlots(cat){
        var wrap = overlay.querySelector('.rc-slots'); wrap.innerHTML='';
        var byDate = {};
        D.slots.filter(function(s){return s.cat===cat;}).forEach(function(s){
          (byDate[s.date]=byDate[s.date]||{label:s.dateLabel,items:[]}).items.push(s);
        });
        var keys = Object.keys(byDate).sort();
        if(!keys.length){ wrap.innerHTML='<p>Zurzeit keine Termine frei.</p>'; return; }
        keys.forEach(function(d){
          var g=document.createElement('div'); g.className='rc-daygroup';
          var h=document.createElement('h4'); h.textContent=byDate[d].label; g.appendChild(h);
          var grid=document.createElement('div'); grid.className='rc-slotgrid';
          byDate[d].items.forEach(function(s){
            var b=document.createElement('button'); b.className='rc-slot'; b.textContent=s.time+' Uhr';
            if(!s.free){ b.disabled=true; b.title='schon vergeben'; }
            else b.addEventListener('click', function(){ pick.slot=s; goConfirm(s); });
            grid.appendChild(b);
          });
          g.appendChild(grid); wrap.appendChild(g);
        });
      }
      function goConfirm(s){
        overlay.querySelector('.rc-summary').textContent = D.cats[s.cat] + ' — ' + s.dateLabel + ', ' + s.time + ' Uhr';
        overlay.querySelector('.rc-error').hidden = true;
        show('3');
      }
      document.getElementById('rc-confirm').addEventListener('click', function(){
        var btn=this; btn.disabled=true; btn.textContent='Buche…';
        var note=document.getElementById('rc-note').value.slice(0,300);
        var body='action=rc_book&nonce='+encodeURIComponent(D.nonce)+'&slot='+encodeURIComponent(pick.slot.id)+'&note='+encodeURIComponent(note);
        fetch(D.ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
          .then(function(r){return r.json();})
          .then(function(j){
            btn.disabled=false; btn.textContent='Verbindlich buchen';
            if(j && j.ok){
              overlay.querySelector('.rc-done-summary').textContent = D.cats[pick.slot.cat]+' — '+pick.slot.dateLabel+', '+pick.slot.time+' Uhr';
              overlay.querySelector('.rc-code').textContent = j.code;
              show('done');
            } else {
              var e=overlay.querySelector('.rc-error'); e.hidden=false;
              e.textContent = (j && j.msg) ? j.msg : 'Buchung fehlgeschlagen. Bitte anderen Slot wählen.';
            }
          })
          .catch(function(){ btn.disabled=false; btn.textContent='Verbindlich buchen';
            var e=overlay.querySelector('.rc-error'); e.hidden=false; e.textContent='Netzwerkfehler. Bitte erneut versuchen.'; });
      });
      document.getElementById('rc-again').addEventListener('click', function(){ pick={cat:null,slot:null}; document.getElementById('rc-note').value=''; open(); });
    })();
    </script>
    <?php
});

/* ---------------------------------------------------------------
 * 4) AJAX: Buchung speichern (anonym), Doppelbuchung verhindern
 * ------------------------------------------------------------- */
function rc_handle_book() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rc_book')) {
        wp_send_json(array('ok'=>false,'msg'=>'Sicherheitsprüfung fehlgeschlagen. Seite neu laden.'));
    }
    $slot = isset($_POST['slot']) ? sanitize_text_field(wp_unslash($_POST['slot'])) : '';
    $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

    // Slot gegen generierte Slots validieren
    $valid = false; $slotInfo = null;
    foreach (rc_build_slots() as $s) { if ($s['id'] === $slot) { $valid = true; $slotInfo = $s; break; } }
    if (!$valid) wp_send_json(array('ok'=>false,'msg'=>'Ungültiger Termin. Bitte neu wählen.'));

    // Bereits vergeben?
    $existing = get_posts(array('post_type'=>'rc_booking','numberposts'=>1,'post_status'=>'publish','fields'=>'ids',
        'meta_key'=>'_rc_slot','meta_value'=>$slot));
    if (!empty($existing)) wp_send_json(array('ok'=>false,'msg'=>'Dieser Termin ist gerade vergeben worden. Bitte anderen wählen.'));

    $cats = rc_categories();
    $code = 'RC-' . strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5));
    $title = $slotInfo['dateLabel'] . ' ' . $slotInfo['time'] . ' – ' . $cats[$slotInfo['cat']] . ' [' . $code . ']';
    $pid = wp_insert_post(array(
        'post_type'=>'rc_booking','post_status'=>'publish','post_title'=>$title,
    ));
    if (!$pid || is_wp_error($pid)) wp_send_json(array('ok'=>false,'msg'=>'Speichern fehlgeschlagen. Bitte erneut versuchen.'));
    update_post_meta($pid, '_rc_slot', $slot);
    update_post_meta($pid, '_rc_code', $code);
    update_post_meta($pid, '_rc_cat', $slotInfo['cat']);
    update_post_meta($pid, '_rc_date', $slotInfo['date']);
    update_post_meta($pid, '_rc_time', $slotInfo['time']);
    if ($note !== '') update_post_meta($pid, '_rc_note', $note);

    wp_send_json(array('ok'=>true,'code'=>$code));
}
add_action('wp_ajax_rc_book', 'rc_handle_book');
add_action('wp_ajax_nopriv_rc_book', 'rc_handle_book');

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
