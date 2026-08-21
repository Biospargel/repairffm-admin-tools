# Repair FfM – Projekt-Handoff

Übergabedokument für die Weiterarbeit mit Claude Code. Beschreibt Website,
bestehende Technik, das selbst gebaute Plugin, alle Design-Entscheidungen samt
Begründung, sowie offene Punkte.

Stand: 21.08.2026 · Plugin-Version 1.9.6

---

## 1. Die Website

| | |
|---|---|
| Domain | https://biospargel.org |
| Name | Repair FfM – „Reparieren statt Wegwerfen" |
| Zweck | Ehrenamtliches, kostenloses Reparatur-Angebot (Handy/Laptop/IT + E-Bike) |
| WordPress | 7.0.4 |
| Theme | „Repair FFM (Block)" – ein **Block-Theme** (Full Site Editing) |
| Zeitzone | Europe/Berlin (wichtig, siehe Abschnitt 6) |
| Status | Noch nicht öffentlich – Kennwortschutz-Gate aktiv |
| Hosting | Selbst gehostet (nicht WordPress.com), Jetpack verbunden (Free-Plan) |

### Leitprinzip: Datensparsamkeit

Datensparsamkeit ist die zentrale Produktentscheidung. Ohne Zutun des
Besuchers gibt es **keine Kontaktdaten**: kein Name, kein Konto, der
**Buchungscode ist das einzige „Credential"**.

> ⚠️ **Geändert am 21.08.2026.** Auf Wunsch des Betreibers gibt es seit 1.8.4
> ein **freiwilliges** E-Mail-Feld. Es ist nie erforderlich; ohne Eintrag
> bleibt alles wie zuvor. Ein zweiter, getrennter Haken entscheidet, ob die
> Adresse über den Termin hinaus bleiben darf — sonst wird sie danach
> automatisch gelöscht.
>
> **Folge:** Der alte Werbetext „ganz anonym, ohne Name, ohne E-Mail" und
> „Keine Daten" ist damit unzutreffend. Startseite und Datenschutzerklärung
> müssen angepasst sein, **bevor** die Seite öffentlich geht (siehe 7).
> Im Plugin selbst wurden alle solchen Zusagen in 1.8.5 bereinigt.

---

## 2. Bestehende Technik (nicht von uns – nicht anfassen)

Zwei **Must-Use-Plugins** (`/wp-content/mu-plugins/`):

1. **Repair FFM – Core (Buchung & Seiten)** v1.0
   Die eigentliche Buchungslogik + Grundseiten.
2. **Repair FFM – Kennwortschutz (WordPress)** v1.0
   Kennwort-Gate für die geschlossene Phase. Blockiert *nicht* admin-ajax
   (Buchung), wp-admin oder Login.

> ⚠️ **Wichtige Einschränkung:** Must-Use-Plugins sind über den WordPress-
> Plugin-Editor **nicht bearbeitbar**. Der Quellcode des Buchungs-Plugins war
> in der bisherigen Arbeit nie einsehbar. Alles, was wir wissen, wurde aus dem
> gerenderten HTML/JS und den gespeicherten Daten rekonstruiert.
> **Mit Claude Code und Dateizugriff ist das jetzt anders — der erste sinnvolle
> Schritt ist, diesen Quellcode endlich zu lesen.** Mehrere unserer Workarounds
> werden dadurch überflüssig (siehe Abschnitt 8).
>
> Wie man den Zugriff herstellt, steht in **`ZUGRIFF-MU-PLUGINS.md`** — samt
> der bereits geprüften Sackgassen, damit sie niemand erneut abläuft. Die
> Website läuft selbst gehostet auf einem **Umbrel-Server** (Docker).

### Datenmodell des Buchungs-Plugins (rekonstruiert)

- **Custom Post Type:** `rc_booking`
- **Post-Titel:** z. B. `Samstag, 18.07.2026 14:30 – IT & Elektronik (Handy · Laptop · IT) [RC-C6Q9L]`
- **Meta-Felder:**
  - `_rc_slot` → z. B. `it_2026-08-29_1430` (Kategorie + Datum + Uhrzeit)
  - `_rc_cat` → z. B. `it` oder `ebike`
- **Buchungscode:** Format `RC-XXXXX`, steht im Titel (kein eigenes Meta-Feld gefunden)
- **Shortcode:** `[repairffm_booking]` rendert das Buchungs-Widget
- **Frontend:** Modal-Dialog, 3 Schritte (Kategorie → Slot → Bestätigen),
  läuft über `admin-ajax`. CSS-Klassen: `.rc-overlay`, `.rc-modal`, `.rc-cat`,
  `.rc-slot`, `.btn`, `.btn.ghost`. Button-IDs: `#rc-open`, `#rc-confirm`, `#rc-again`.
- **Notizfeld:** „Kurz: Was ist kaputt?" (freiwillig, keine personenbezogenen Angaben)

### Bestätigte Klassennamen des Buchungsdialogs

Am 21.08.2026 über eine eingebaute Diagnose ausgelesen — **nicht mehr
geraten**, sondern aus dem laufenden Dialog gemeldet:

```
.rc-overlay              Hintergrundfläche
.rc-modal                der Dialog selbst
.rc-close   #rc-close    Schließen-Button  ← fehlte in der Rekonstruktion
.rc-cat                  Kategorie-Knöpfe
.rc-back                 "zurück"
.btn        #rc-confirm  "Verbindlich buchen"
.btn.ghost  #rc-again    "Weiteren Termin buchen"
```

Dass `.rc-close` unbekannt war, ist der Grund, warum sich der schwer
erreichbare Schließen-Button monatelang nicht beheben ließ.

Die Diagnose bleibt erhalten: **`?rfat_diag=1`** an die Adresse hängen,
nur für Angemeldete sichtbar.

### Seiten

| Seite | Slug | Post-ID | Inhalt |
|---|---|---|---|
| Termin buchen | `/termin-buchen/` | 6 | `[repairffm_booking]` |
| Termin abrufen | `/termin-abrufen/` | 37 | `[rfat_manage_booking]` (von uns angelegt) |
| Start, Termine & Ort, Mitmachen, Impressum, Datenschutz | | | Theme-Seiten |

---

## 3. Unser Plugin: `repairffm-admin-tools`

Ein **eigenständiges Companion-Plugin**, das die Buchungslogik nicht anfasst,
sondern nur ergänzt. Eine einzige PHP-Datei, keine Fremdabhängigkeiten.

- **Repo:** https://github.com/Biospargel/repairffm-admin-tools
- **Aktuelle Version:** 1.8.0
- **Funktions-Präfix:** `rfat_` (RepairFfmAdminTools)

### Warum ein separates Plugin?

Die Buchungslogik liegt in einem Must-Use-Plugin (nicht editierbar), und das
Theme wollten wir nicht verändern (überlebt keinen Theme-Wechsel). Ein
eigenes Plugin war der sauberste Ort — mit dem Nachteil, dass jede Version
manuell hochgeladen werden musste. Das löst jetzt der GitHub-Updater.

### 3.1 Feld-Erkennung nach Wertform (`rfat_analyse_booking`)

**Das Kernstück.** Weil die internen Meta-Schlüssel des Buchungs-Plugins
unbekannt waren, klassifiziert das Plugin Felder **nach ihrer Wertform**
statt nach Namen:

```
rfat_looks_like_datetime()  → /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/
rfat_looks_like_date()      → /^\d{4}-\d{2}-\d{2}$/
rfat_looks_like_time()      → /^\d{2}:\d{2}/
rfat_looks_like_code()      → /^RC-[A-Z0-9]{3,}$/i
```

Alles andere landet als „other" in der Ausgabe — mit lesbar gemachtem
Schlüsselnamen, damit **nichts unsichtbar** wird. Interne WordPress-Felder
(`_edit_lock`, `_edit_last`, …) filtert eine Blockliste heraus.

> **Bewertung für die Weiterarbeit:** Dieser Ansatz war eine
> Notlösung für fehlenden Codezugriff. Er ist robust, aber unnötig indirekt.
> Sobald der mu-Plugin-Quellcode gelesen ist: durch direkte Meta-Keys
> (`_rc_slot`, `_rc_cat`) ersetzen. Das macht auch `rfat_public_hidden_field()`
> und `rfat_friendly_category()` überflüssig.

### 3.2 Admin-Übersicht

`Buchungen → Übersicht` (`edit.php?post_type=rc_booking&page=rfat-overview`)

- Tabs: **Kommende / Vergangene / Alle** (Sortierung: kommende aufsteigend,
  vergangene absteigend)
- Suche über Code und alle Detailwerte
- Aufklappbares Bearbeiten-Formular je Buchung: Datum, Uhrzeit, alle
  dynamisch erkannten Felder, Status
- Papierkorb-Button mit Bestätigung
- Alle Formulare mit `wp_nonce_field` abgesichert, `manage_options` erforderlich

**Status-System** (`_rfat_status`, eigenes Meta-Feld von uns):
`offen` (grün) · `erledigt` (grau) · `storniert` (rot)

**Meta-Key-Encoding:** Feldnamen werden für HTML-Formularnamen base64-kodiert
(`rfat_encode_key`/`rfat_decode_key`), weil Meta-Keys Zeichen enthalten können,
die in `name="..."`-Attributen problematisch sind.

### 3.3 Öffentliche Selbstverwaltung: `[rfat_manage_booking]`

Auf `/termin-abrufen/`. Besucher gibt Buchungscode ein und kann:

1. **Status ansehen** – Termin, Kategorie (als Klartext), Status als Badge
2. **Stornieren** – `wp_trash_post()`, Slot wird sofort wieder frei
3. **Verschieben** – zweistufig, siehe unten

**Warum Verschieben zweistufig ist:** Die Regeln des Buchungs-Plugins für
freie Slots (Kapazität, Blackout-Termine, Vorlaufzeit) waren nicht einsehbar.
Sie nachzubauen hätte Doppelbuchungen riskiert. Stattdessen:

> Besucher bucht über das **echte** Buchungs-Widget (`[repairffm_booking]`,
> direkt im Aufklappbereich eingebettet) einen neuen Termin → bekommt neuen
> Code → trägt diesen ein → alter Termin wird automatisch storniert.

Ein Schritt mehr für den Nutzer, dafür kann die Slot-Logik nicht auseinander-
laufen. **Mit Codezugriff neu bewertbar** — wenn die Slot-Funktionen des
mu-Plugins aufrufbar sind, wird daraus ein sauberer Ein-Schritt-Flow.

Sicherheit: Nonces pro Code, Groß-/Kleinschreibung normalisiert,
Sicherheitsabfrage vor dem Stornieren.

### 3.4 Frontend-CSS (`wp_head`, Priorität 999)

Späte Einbindung, damit es ohne `!important`-Wildwuchs gegen Theme- und
Customizer-CSS gewinnt.

- **Buttons** (`.btn`, `.btn.ghost`): grün `#2f7d4f`, passend zum Theme-Button
  (`.wp-element-button`, Radius 11px). Fluid via `clamp()`.
- **Randabstand mobil:** über die CSS-Variablen
  `--wp--style--root--padding-left/right` (offizieller Block-Theme-Mechanismus)
  plus Fallback für Layout-Container.
- **Handy-Menü:** siehe 3.5 — seit 1.8.0 ein eigenständiges Menü statt des
  Theme-Overlays.
- **Breakpoints:** Layout-Anpassungen bis 782px, das **Menü bis 600px**. 600px
  ist die Grenze, ab der WordPress' Navigationsblock auf Overlay umschaltet;
  782px ist der wp-admin-Wert und war hier bis 1.8.0 falsch gesetzt.
- **`overflow-x: hidden` liegt auf `body`, nicht auf `html`.** Auf dem
  html-Element schaltet es `position: sticky` für alle Kindelemente ab — ein
  klebriger Theme-Header funktioniert dann nicht mehr.

### 3.5 Eigenständiges Handy-Menü (seit 1.8.0)

Button und Overlay werden **serverseitig** ausgegeben, unabhängig davon,
welchen Navigationsblock das Theme benutzt. Grund siehe Abschnitt 7.

Die Menüpunkte kommen aus echten WordPress-Daten statt aus DOM-Scraping —
`rfat_get_menu_items()` versucht der Reihe nach:

1. klassisches Menü (`wp_get_nav_menu_items`)
2. `wp_navigation`-Post (so speichern Block-Themes ihre Menüs), rekursiv nach
   `core/navigation-link` und `core/page-list` durchsucht
3. veröffentlichte Top-Level-Seiten

Ergebnis 12 h im Transient `rfat_menu_items`, verworfen bei
`wp_update_nav_menu` und beim Speichern von Seiten/Navigation.

Barrierefrei: `aria-expanded`, Escape schließt, Fokusfalle im offenen Menü,
Fokus-Rückgabe beim Schließen, Scroll-Sperre der Seite dahinter.

**Kein doppelter Hamburger:** Findet das Skript ein
`.wp-block-navigation__responsive-container-open` des Themes, entfernt es
unser Menü restlos und überlässt dem Theme den Vortritt.

**Selbstdiagnose:** `<html>` bekommt `rfat-nav-core` oder
`rfat-nav-fallback` — im Inspektor sofort sichtbar, welcher Weg läuft.

### 3.6 Navigation

`render_block_core/navigation` hängt **„Termin abrufen"** serverseitig an die
Menüliste. Zusätzlich ein Client-Fallback im Footer, falls die Navigation kein
`core/navigation`-Block ist. Doppelte Einträge sind ausgeschlossen (Prüfung auf
`termin-abrufen` im Markup).

### 3.7 GitHub-Auto-Update

Nutzt den **offiziellen WordPress-Mechanismus** (seit 5.8):
`Update URI`-Header im Plugin-Kopf → WordPress fragt den Filter
`update_plugins_github.com` statt wordpress.org.

- Holt `https://api.github.com/repos/Biospargel/repairffm-admin-tools/releases/latest`
- Cache 6 h (`rfat_github_release`, Site-Transient) wegen API-Limit (60/h),
  Fehler werden 1 h negativ gecacht
- „Erneut prüfen" im Dashboard leert den Cache (`load-update-core.php`)
- Nimmt **nur hochgeladene `.zip`-Assets**, ignoriert GitHubs automatisches
  „Source code (zip)" (falscher Ordnername → würde das Plugin beim Update
  umbenennen)

**Härtung in 1.8.0:**

- `slug` fällt nicht mehr auf `.` zurück, wenn das Plugin als Einzeldatei
  direkt in `/wp-content/plugins/` liegt
- vollständige Update-Felder (`id`, `plugin`, `new_version`, `tested`,
  `requires_php`) — ohne sie blieb die Zeile auf der Plugin-Seite leer
- `upgrader_source_selection` biegt abweichende Zip-Ordnernamen zurecht
- Asset-Auswahl bevorzugt die Zip, deren Name zum Slug passt, nimmt sonst
  die erste `.zip`

**Release-Prozess: automatisch (seit PR #2).** Der Workflow
`.github/workflows/release.yml` läuft, sobald sich `repairffm-admin-tools.php`
auf `main` ändert, liest die Version aus dem Plugin-Kopf und legt Tag, Zip und
Release an. Existiert das Release schon, passiert nichts — gefahrlos
wiederholbar. Vorgeschaltet `php -l`; bei Syntaxfehler entsteht kein Release.

> **Der einzige Auslöser ist die Versionsnummer im Plugin-Kopf.** Wer Code
> ändert, ohne sie hochzuzählen, bekommt kein Release — und keine Fehlermeldung.

Handarbeit ist damit nur noch der Merge. Getestet am 21.08.2026 per
`workflow_dispatch`: Version korrekt gelesen, bestehendes `v1.8.0` erkannt,
Bau und Release übersprungen. Der scharfe Pfad läuft beim nächsten
Versionssprung zum ersten Mal.

---

## 4. Versionshistorie

| Version | Inhalt |
|---|---|
| 1.0.0 | Admin-Übersicht mit Bearbeiten/Status/Papierkorb |
| 1.1.0 | Shortcode `[rfat_manage_booking]` (ansehen/stornieren/verschieben) |
| 1.1.1 | **Bugfix Zeitzone** (siehe Abschnitt 6) |
| 1.2.0 | Öffentliche Karte moderner; Slot-ID versteckt, Kategorie als Klartext |
| 1.3.0 | Responsives CSS ins Plugin verlagert (vorher Customizer) |
| 1.4.0 | Mobile: Randabstand, kleinere Überschriften/Buttons, 📅-Emoji entfernt |
| 1.5.0 | Randabstand über Root-Padding-Variablen; Buttons über `.wp-element-button` |
| 1.6.0 | „Termin abrufen" im Menü + Hamburger-Overlay auf dem Handy |
| 1.7.0 | GitHub-Auto-Update eingebaut |
| 1.8.0 | Eigenständiges Handy-Menü (unabhängig vom Theme-Block); Updater gehärtet |
| 1.8.1–1.8.2 | Buchungs-Popup: Höhe gedeckelt, Menüknopf bei offenem Dialog aus; sichtbare Diagnose |
| 1.8.3 | `/termin-abrufen/?code=…` als Link, mit Kopier-Knopf |
| 1.8.4 | **Freiwillige E-Mail** mit getrennter Einwilligung und Selbstlöschung |
| 1.8.5 | Unzutreffende Datenschutz-Zusagen im Plugin bereinigt |
| 1.8.6 | Mail bei neuer Buchung; Kalendereintrag (.ics); Abmeldelink |
| 1.8.7 | Empfänger der Benachrichtigung einstellbar |
| 1.8.8 | Block im Bestätigungsschritt (Erkennung am Buchungscode) |
| 1.8.9 | Statusvermerk im Seitenfuß |
| 1.9.0 | **Termine gelten als Anfrage**, bis die Werkstatt zusagt |
| 1.9.1–1.9.2 | Mailversand und Updater diagnostizierbar gemacht |
| 1.9.3 | E-Mail direkt im Bestätigungsschritt, danach Weiterleitung |
| 1.9.4 | Schließen-Button des Popups erreichbar (`.rc-close` bestätigt) |
| 1.9.5 | Mail-Fehlermeldungen in Klartext |
| 1.9.6 | Toter Funktionsaufruf behoben; nur noch Hamburger auf dem Handy; Dialogtext richtiggestellt |

---

## 5. Erledigte Aufgaben

- ✅ Buchungs-Buttons waren komplett ungestylt (nackte Browser-Buttons) → an
  das Theme-Design angeglichen
- ✅ Admin-Übersicht der Buchungen
- ✅ Seite „Termin abrufen" angelegt und veröffentlicht
- ✅ Selbstverwaltung per Code
- ✅ Menüeintrag + mobiles Hamburger-Menü
- ✅ GitHub-Updater
- ✅ Erstes Release (`v1.8.0`) angelegt und verifiziert
- ✅ Releases entstehen automatisch per GitHub Actions
- ✅ Schließen-Button des Popups erreichbar (Ursache: `.rc-close` war unbekannt)
- ✅ Freiwillige E-Mail samt Einwilligung, Löschung und Abmeldelink
- ✅ Benachrichtigung bei neuer Anfrage, mit Zusagen-/Absagen-Links
- ✅ Zusage-Schritt: Termine sind erst nach Bestätigung verbindlich
- ✅ Kalendereintrag und Selbstverwaltungs-Link nach der Buchung

---

## 6. Gelöste Fallstricke (nicht wieder einbauen)

### Zeitzonen-Bug (behoben in 1.1.1)

`date_create()` **ohne** Zeitzonen-Argument nutzt die Server-Zeitzone (meist
UTC) — die gespeicherten Werte sind aber naive Ortszeit. Ergebnis: alle
Termine wurden 2 Stunden zu spät angezeigt. Hätte man in dem Zustand eine
Buchung gespeichert, wäre die falsche Zeit in die Datenbank geschrieben worden.

**Korrekt ist:** `date_create($raw, wp_timezone())`.

Merksatz: In WordPress immer `wp_timezone()` / `wp_date()` verwenden, nie die
PHP-Standardzeitzone.

### Weitere Punkte

- **Must-Use-Plugins** lassen sich nicht über den Plugin-Editor bearbeiten.
- **Handy-Caching:** Safari cacht aggressiv. Nach Updates hart neu laden,
  sonst wirkt eine Änderung fälschlich als „hat nicht funktioniert".
- **Immer die Plugin-Version prüfen**, bevor man einem Screenshot glaubt —
  einmal wurde eine Version getestet, die gar nicht aktiv war.

### Stiller Leerlauf (zweimal erlebt, 21.08.2026)

Zweimal landete Code auf `main`, der **nichts tat** — und beide Male fiel es
niemandem auf, weil beides syntaktisch einwandfrei war:

1. Ein vergessener Versions-Bump: Der Code von 1.9.0 lag auf `main`, die
   Nummer stand auf 1.8.9. Der Workflow sah das Release schon vorhanden und
   übersprang alles lautlos.
2. `rcAfterBooking()` war seit 1.8.8 definiert, wurde aber **nie aufgerufen**.
   Der Block nach der Buchung erschien deshalb monatelang nicht.

Gemeinsame Ursache: Ein Bearbeitungsskript brach nach dem gelungenen Teil
ab und schrieb die Datei nicht — die erfolgreiche Änderung ging mit verloren.

**Beides prüft jetzt der Workflow** und bricht mit Fehlermeldung ab:
Plugin-Datei geändert, aber Release existiert schon → Nummer vergessen.
Funktion im eingebetteten JavaScript definiert, aber nirgends aufgerufen →
toter Code.

### Mailversand: `mail()` ist abgeschaltet

Die Diagnose meldete „Die E-Mail-Funktion konnte nicht instanziiert werden"
(PHPMailers *Could not instantiate mail function*). Das heißt: PHPs `mail()`
ist beim Hoster deaktiviert, der Versand wird gar nicht erst versucht.

**Vom Plugin aus nicht lösbar.** Nötig ist SMTP. Nicht am Spam-Filter, nicht
an Outlook, nicht am Code suchen — die Meldung ist eindeutig, und seit 1.9.5
steht die Erklärung in der Übersicht direkt darunter.

### Release-Fallstricke (erlebt am 20.08.2026)

Das erste, von Hand angelegte Release hatte gleich drei Fehler: Tag
`Biospargel.org` statt `v1.8.0`, **kein Asset**, und als Ziel den
Feature-Branch statt `main`.

Tückisch daran: **Der Updater gibt bei einem unbrauchbaren Release
schweigend auf** und merkt sich das Ergebnis eine Stunde lang. Von außen
sieht das genauso aus wie „kein Update vorhanden".

- Tag muss **exakt** `v` + die Version aus dem Plugin-Kopf sein — sonst
  scheitert der Versionsvergleich still.
- Ohne hochgeladene `.zip` passiert nichts. GitHubs „Source code (zip)" zählt
  bewusst nicht.
- Der Ordner **im** Archiv muss dem Installationsordner entsprechen, sonst
  installiert WordPress das Plugin unter neuem Namen und lässt das alte
  deaktiviert zurück.

Seit PR #2 sind alle drei Punkte im Workflow verdrahtet — von Hand sollte
kein Release mehr entstehen.

---

## 7. Offene Punkte

### Mobile Darstellung — teilweise geklärt

**Ursache des fehlenden Hamburger-Menüs gefunden (1.8.0):** Der Button fehlte
komplett im DOM. WordPress rendert das Overlay-Markup — und damit den Button —
ausschließlich für `core/navigation`-Blöcke. Ist die Kopf-Navigation ein
anderer Block (oder ein klassisches Menü), greift weder `overlayMenu` noch
das eingebaute Overlay, und es entsteht schlicht kein Button. Genau das hatte
der JS-Fallback in 1.6.0 schon vermutet.

**Lösung:** Ein eigenständiges Handy-Menü, das Button und Overlay
serverseitig ausgibt und die Menüpunkte aus echten WordPress-Daten holt
(klassisches Menü → `wp_navigation`-Post → Seiten). Bringt das Theme doch ein
funktionierendes Overlay mit, entfernt sich unseres beim Laden selbst — es
kann also nie zwei Hamburger geben.

**Selbstdiagnose:** Das Skript setzt eine Klasse auf `<html>`:
`rfat-nav-core` (Theme-Menü übernimmt) oder `rfat-nav-fallback` (unseres
läuft). Damit lässt sich im Browser-Inspektor in zwei Sekunden feststellen,
welcher Weg aktiv ist — statt wie bisher zu raten.

Weiter offen: Buttons wirkten „zu dick", Inhalte klebten am Bildschirmrand.
Beides ungeprüft, weil biospargel.org aus der Sandbox nicht erreichbar ist.

### Kleinere Punkte

### SMTP einrichten — dringendster Punkt ⚠️

Ohne funktionierenden Versand erfährt die Werkstatt von einer Anfrage nur,
wenn jemand zufällig in die Übersicht schaut. Das muss vor dem Livegang
stehen.

Empfohlen wurde am 21.08.2026 unter dem Kriterium „möglichst anonym":

1. **Das Postfach des eigenen Hosters** — es kommt keine neue Partei hinzu,
   kein zusätzlicher Auftragsverarbeiter, keine Änderung am Datenschutztext.
2. **mailbox.org**, falls der Hoster keines anbietet.

Ausdrücklich **nicht** Brevo, Mailjet, SMTP2GO: Marketing-Plattformen mit
Öffnungs- und Klickmessung — ein Bruch mit dem eigenen Versprechen der Seite.

Plugin: **FluentSMTP** (frei, ohne Werbung und Telemetrie).

Absender muss eine Adresse der **eigenen Domain** sein, sonst scheitern SPF
und DMARC. Empfänger ist `repair.ffm@outlook.com`.

Kommt ein externer Dienst hinzu, gehört er als Auftragsverarbeiter in die
Datenschutzerklärung.

### Kleinere Punkte

- Customizer → Zusätzliches CSS enthält noch die alten `.btn`-Regeln.
  Seit 1.3.0 redundant, kann gelöscht werden.
- Kennwortschutz ist noch aktiv — vor dem Livegang deaktivieren.
- Suchmaschinen-Sichtbarkeit prüfen (Einstellungen → Lesen).
- Startseite, Datenschutz und Impressum müssen vor dem Livegang stehen;
  fertige Texte lagen dem Betreiber am 21.08.2026 vor.

### Buchungs-Popup → eigene Seite (Wunsch vom 21.08.2026)

Der X-Button des Buchungs-Modals ist auf dem Handy schwer erreichbar, das
Feld zu groß. Gewünscht ist **kein Popup, sondern eine normale Seite**, die
sich im Block-Editor bearbeiten lässt.

Das ist auch fachlich die bessere Lösung: kein Scroll-Lock, keine Fokusfalle,
funktionierender Zurück-Button.

**Blockiert durch fehlenden Quellcode.** Das Modal kommt aus dem mu-Plugin.
Bekannt sind nur die aus dem HTML rekonstruierten Klassen (`.rc-overlay`,
`.rc-modal`, `#rc-open`, `#rc-confirm`, `#rc-again`) — **der Schließen-Button
ist nicht darunter**. Blind CSS dafür zu schreiben ist genau der Fehler, der
1.4.0–1.6.0 wirkungslos gemacht hat.

Als Zwischenschritt wurde ein CSS-Schnipsel zum Test in den Customizer
gegeben (`max-height: 85vh` + `overflow-y: auto` auf `.rc-modal`). Wirkt es,
stimmen die rekonstruierten Klassennamen; wirkt es nicht, sind sie falsch —
beides ist ein verwertbarer Befund.

---

## 8. Empfohlene erste Schritte mit Claude Code

1. **`/wp-content/mu-plugins/` lesen.** Der Buchungs-Plugin-Quellcode war nie
   zugänglich und ist der Schlüssel zu fast allem hier.
2. **Feld-Erkennung ersetzen:** `rfat_analyse_booking()` durch direkte
   Meta-Keys ablösen (siehe 3.1). Vereinfacht das Plugin erheblich.
3. **Verschieben-Flow überarbeiten:** Wenn die Slot-Logik aufrufbar ist, wird
   aus dem Zweischritt-Umweg ein direktes Umbuchen (siehe 3.3).
4. **Mobile Darstellung mit echtem Theme-Wissen fixen** statt mit geratenen
   Selektoren (siehe 7).
5. **Buchungscode als Meta-Feld:** Wird aktuell aus dem Titel gelesen. Falls
   das mu-Plugin ihn ohnehin als Meta speichert, direkt daher nehmen.

### Warum Claude Code hier klar im Vorteil ist

In dieser Sitzung fehlte sowohl Dateizugriff auf den Server als auch —
zeitweise — Browserzugriff auf die Seite. Die Sandbox-Firewall blockierte
`biospargel.org` und `github.com`. Deshalb wurde vieles aus dem gerenderten
HTML rekonstruiert und per Zip-Datei ausgeliefert. Mit direktem Dateizugriff
entfallen diese Umwege komplett.

---

## 9. Referenz

**Farbpalette**

| Zweck | Hex |
|---|---|
| Grün (primär) | `#2f7d4f` |
| Grün (hover) | `#256a42` |
| Grün (dunkel/Text) | `#1f5a38` |
| Grün (hell/Fläche) | `#e8f1eb` |
| Text dunkel | `#1c2a22` |
| Text grau | `#5b6b62` |
| Rahmen hell | `#cfd8d2` / `#e7ebe8` |
| Rot (storniert) | `#b3402f` |

Schrift: `system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`
Button-Radius: 11px · Karten-Radius: 20px

**Wichtige Kennungen**

```
CPT:            rc_booking
Meta:           _rc_slot, _rc_cat, _rfat_status (unseres)
Shortcodes:     [repairffm_booking] (Kern), [rfat_manage_booking] (unseres)
Admin-Seite:    edit.php?post_type=rc_booking&page=rfat-overview
Transients:     rfat_github_release (6 h), rfat_menu_items (12 h),
                rfat_status (5 min), rfat_cleanup_ran (24 h)
Meta (unseres): _rfat_status, _rfat_email, _rfat_email_keep, _rfat_notified
Optionen:       rfat_notify_to, rfat_notify_log, rfat_release_log
Status:         angefragt, bestaetigt, offen, erledigt, storniert
Handy-Menü:     .rfat-nav-open, .rfat-nav-overlay, .rfat-nav-link
Nach Buchung:   #rfat-after-booking (in den fremden Dialog eingehängt)
Diagnose:       <html class="rfat-nav-core"> oder "rfat-nav-fallback"
                ?rfat_diag=1 blendet die Struktur des Dialogs ein
Workflow:       .github/workflows/release.yml (Auslöser: Version im Plugin-Kopf)

Öffentliche Adressen
  /termin-abrufen/?code=RC-XXXXX     Termin ansehen und verwalten
  /termin-abrufen/?ics=RC-XXXXX      Kalendereintrag herunterladen
  /termin-abrufen/?abmelden=RC-XXXXX E-Mail entfernen (fragt nach)
  /?rfat_do=bestaetigen&…            signierter Zusage-Link aus der Mail
```
