# Repair FfM – Projekt-Handoff

Übergabedokument für die Weiterarbeit mit Claude Code. Beschreibt Website,
bestehende Technik, das selbst gebaute Plugin, alle Design-Entscheidungen samt
Begründung, sowie offene Punkte.

Stand: 24.08.2026 · Plugin-Version 1.18.0

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
| Status | Kennwortsperre entfernt (Plugin 1.17.0) – Seite ohne Kennwort erreichbar |
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
2. **Repair FFM – Kennwortschutz (WordPress)** v1.0 — *seit 1.17.0 stillgelegt*
   Kennwort-Gate für die geschlossene Phase. Blockierte *nicht* admin-ajax
   (Buchung), wp-admin oder Login. Das Plugin benennt die Datei in
   `repairffm-gate.php.deaktiviert` um; zum Zurückholen genügt der alte
   Name (siehe Abschnitt 7, „Kennwortsperre raus").

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
| Haltung | `/haltung/` | | Wofür die Werkstatt offen ist, was hier keinen Platz hat (von uns angelegt, ab 1.18.0) |
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
  `--wp--style--root--padding-left/right` (offizieller Block-Theme-Mechanismus).
  Das Theme setzt beide auf `0`; bis 782px überschreiben wir sie auf 20px.
  Nur so bleiben ganzflächige Hintergründe (`alignfull`, z. B. der Hero)
  randlos, während der Text einrückt.
  > ⚠️ Diese Regeln waren in **1.14.0** versehentlich mit dem Theme-Menü-CSS
  > mitentfernt worden — der Text klebte seitdem am Rand. Zurück in 1.17.1.
  > Ein zusätzliches Auffangnetz für einzelne Container gibt es bewusst
  > **nicht** mehr: Auf den Seiten, die ohnehin am Global-Padding hängen,
  > legte es sich obendrauf (40 statt 20 Pixel, schmalere Karten).
- **Lange Wörter in Überschriften** (`h1`–`h3`): `hyphens: auto` plus
  `overflow-wrap`. „Verbraucherstreitbeilegung" im Impressum ist als h2 auf
  einem 390px-Display 427px breit und lief bis 1.17.1 rechts aus dem Bild —
  unsichtbar, weil `overflow-x: hidden` den Rest abschneidet.
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
| 1.9.7 | Versionsnummer günstig gelesen statt `get_plugin_data()` bei jedem Aufruf |
| 1.10.0 | Echte Meta-Keys des Buchungs-Plugins statt Raten |
| 1.11.0 | **Buchung ist eine echte Seite** — Popup, Overlay-CSS und MutationObserver entfernt |
| 1.12.0 | **Buchung ins Plugin geholt** — aktualisiert sich über GitHub; Schrittanzeige; Einleitung ab Schritt 2 aus |
| 1.13.0 | Kopfleiste mit großem Hamburger, schrumpft beim Scrollen; Theme-Menü über die **Adressen** ausgeblendet statt über geratene Klassen |
| 1.14.0 | Logo und Seitentitel verschont (1.13.0 nahm sie mit); Theme-Menü wird **serverseitig entfernt** statt versteckt — kein Aufblitzen, 149 Zeilen weniger |
| 1.14.1 | Balken hinter dem Hamburger entfernt (schnitt beim Scrollen durch den Text); Versatz für die Adminleiste richtiggestellt |
| 1.15.0 | Weniger Datenbank-Zugriffe (Meta-Batching, zwei kurze Caches); CSS wird **beim Ausliefern** gestrichen statt in der Quelle |
| 1.16.0 | Buchung führt **direkt** auf `/termin-abrufen/` — Zwischenseite und zweites E-Mail-Formular entfallen (183 Zeilen weniger) |
| 1.17.0 | **Kennwortsperre entfernt** — das Gate-mu-Plugin wird stillgelegt, obwohl seine Datei nicht im Repo liegt; Cookie-Absatz im Datenschutz richtiggestellt |
| 1.17.1 | **Seitenrand auf dem Handy zurück** (ging in 1.14.0 verloren); lange Komposita in Überschriften brechen um |
| 1.18.0 | Seite **„Haltung"** — alle willkommen, kein Platz für Rassismus, Queer-/Transfeindlichkeit und Faschismus; WordPress-Beispielseite in den Papierkorb |

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
- ✅ Kennwortsperre entfernt (1.17.0) — ohne Serverzugriff, aus dem Plugin heraus
- ✅ Seite „Haltung" (1.18.0) — Willkommenszusage und klare Grenze, im Menü
- ✅ WordPress-Beispielseite aus dem Menü geräumt (1.18.0)

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
- ~~Kennwortschutz ist noch aktiv~~ — erledigt in 1.17.0.
- **Suchmaschinen-Sichtbarkeit — offen.** Am 24.08.2026 live geprüft: Die
  Seite liefert weiterhin `<meta name="robots" content="noindex, nofollow">`.
  Der Haken *Einstellungen → Lesen → „Suchmaschinen davon abhalten"* ist
  also noch gesetzt; ohne ihn zu lösen, taucht die Seite in keiner Suche auf.
  Das Plugin sagt im Hinweis nach dem Entsperren, wie der Haken steht —
  setzen muss ihn jemand von Hand, das ist keine Entscheidung fürs Plugin.
- Startseite, Datenschutz und Impressum müssen vor dem Livegang stehen;
  fertige Texte lagen dem Betreiber am 21.08.2026 vor.

### Die Seite „Haltung" — neu in 1.18.0

Eine eigene Seite unter `/haltung/`: dass alle willkommen sind, was hier
keinen Platz hat (Rassismus, Antisemitismus, antimuslimischer Rassismus,
Sexismus, Queer- und Transfeindlichkeit, Behindertenfeindlichkeit,
Faschismus), und an wen man sich wendet, wenn doch etwas passiert.

**Die Zusage bleibt allgemein — ohne Aufzählung.** „Wir fragen nicht, wer
du bist, woher du kommst oder wen du liebst" statt einer Liste von
Herkünften, Geschlechtern und Orientierungen: Eine Liste kann jemanden
vergessen, ein Satz nicht. Konkret wird die Seite dort, wo es zählt — bei
dem, was hier keinen Platz hat. So gewollt vom Betreiber (24.08.2026).

**Angelegt wird sie einmalig und zusätzlich**, nicht über
`rc_setup_version`: Das Hochzählen schreibt alle Seiten neu und nimmt
eigene Änderungen im Seiteneditor mit. Gibt es unter `/haltung/` schon
eine Seite, bleibt sie unangetastet — ein einmal geschriebener Text
gehört dem Betreiber, nicht dem Plugin. Gemerkt wird das in der Option
`rfat_haltung_seite` (die Seiten-ID).

**Ins Menü kommt sie von selbst:** `rfat_menu_items_from_pages()` nimmt
veröffentlichte Top-Level-Seiten in der Reihenfolge `menu_order,
post_title`. Das Anlegen verwirft deshalb nur noch den Cache
`rfat_menu_items`, damit der Punkt sofort dasteht statt in zwölf Stunden.

**Die WordPress-Beispielseite** („Beispiel-Seite") stand im Menü zwischen
den echten Seiten — sie ist Top-Level und veröffentlicht, und genau daraus
baut sich das Menü. Sie wandert einmalig in den **Papierkorb**, und nur
solange ihr Text der unveränderte Auslieferungszustand ist („Dies ist eine
Beispiel-Seite" / „This is an example page"). Wer sie selbst beschrieben
hat, meint sie auch so — dann passiert nichts. Gemerkt in
`rfat_beispielseite` (Seiten-ID, oder `0` für „nachgesehen, nichts zu
tun").

**Text ändern:** in `rfat_haltung_text()` im Plugin — dann fährt er über
GitHub mit und es bleibt nachvollziehbar, wer wann was geändert hat
(dieselbe Begründung wie bei Impressum und Datenschutz). Wer ihn lieber
im Seiteneditor pflegt, kann das tun: Das Plugin schreibt die Seite nach
dem ersten Anlegen nicht mehr an.

### Kennwortsperre raus — erledigt in 1.17.0

Die Sperre steckte in `/wp-content/mu-plugins/repairffm-gate.php`. Diese
Datei liegt **absichtlich nicht im Repository** (sie enthält den
Kennwort-Hash), mu-Plugins lassen sich weder im Plugin-Editor bearbeiten
noch unter „Plugins" abschalten, und `biospargel.org` ist aus der
Cloud-Sandbox nicht erreichbar. Von außen führte also kein Weg hin.

Von innen schon: Das Begleit-Plugin läuft auf demselben Server, im selben
PHP-Prozess, und wird **nach** den mu-Plugins geladen. Der Abschnitt
`KENNWORTSPERRE ENTFERNEN` tut deshalb zweierlei:

1. **Datei stilllegen.** Einmalig `rename()` auf
   `repairffm-gate.php.deaktiviert`. WordPress lädt aus `mu-plugins` nur
   `*.php` — ab dem nächsten Aufruf ist die Sperre weg. Umbenannt statt
   gelöscht: Wer die geschlossene Phase zurückholen will, benennt zurück.
2. **Haken lösen.** Für den laufenden Aufruf ist das Umbenennen zu spät,
   da hängen die Haken des Gates schon. Also werden alle Rückrufe entfernt,
   deren Datei laut Reflection die Gate-Datei ist — an rund fünfzehn Haken,
   an denen eine Zugangssperre überhaupt hängen kann. Wie die Funktionen
   des Gates heißen, muss dafür niemand wissen.

**Erkannt wird am Verhalten, nicht am Dateinamen:** Als Gate gilt eine
Datei, die `rc_access` aus `$_COOKIE` liest — oder deren Plugin-Kopf
„Kennwortschutz" heißt. Der Buchungs-Kern nennt denselben Cookie zwar im
Datenschutztext, liest ihn nicht, und wird an `rc_build_slots` erkannt und
in jedem Fall in Ruhe gelassen.

Geht das Umbenennen nicht (Dateisystem schreibgeschützt), bleibt es beim
Aushängen der Haken — offen ist die Seite dann trotzdem, aber bei jedem
Aufruf neu. Ein Hinweis im Verwaltungsbereich nennt dann den `mv`-Befehl
für den Server.

**Was sich am Datenschutztext ändert:** Der Cookie-Absatz beschrieb den
`rc_access`-Cookie der geschlossenen Phase. Ohne Sperre stimmt das nicht
mehr, deshalb ersetzt das Plugin **genau diesen einen Absatz** auf der
Seite `/datenschutz/`. Nicht über `rc_setup_version` — das Hochzählen
schreibt alle fünf Seiten neu und würde eigene Änderungen im Seiteneditor
mitnehmen.

Nicht angefasst, weil es keine Plugin-Entscheidung ist: der Haken
*Einstellungen → Lesen → Suchmaschinen abhalten*. Wie er steht, steht im
Hinweis nach dem Entsperren.

### Buchung als echte Seite — erledigt in 1.11.0

Die Buchung war ein Popup: ein Overlay, das das mu-Plugin per JavaScript
über die Seite legte, alle vier Schritte im selben Dokument. Solange der
Quellcode fehlte, konnten wir nur von außen dagegenhalten — CSS gegen
rekonstruierte Klassennamen, ein MutationObserver, der auf einen
Buchungscode im Overlay wartete, um das E-Mail-Feld einzuhängen. Das hat
funktioniert, war aber durchgehend Behelf.

Seit der Quellcode vorliegt (21.08.2026) ist jeder Schritt eine eigene
Adresse:

```
/termin-buchen/                      Kategorie wählen
/termin-buchen/?was=it               freien Termin wählen
/termin-buchen/?was=it&wann=<slot>   bestätigen  (Formular, POST)
/termin-buchen/?code=RC-XXXXX        fertig
```

Schritt 1 und 2 sind Links, Schritt 3 ist ein gewöhnliches Formular, danach
wird weitergeleitet (Post/Redirect/Get — Neuladen bucht dadurch nicht
doppelt). Zurück-Knopf, Lesezeichen und Weiterschicken funktionieren von
selbst; die Buchung läuft ohne eine Zeile JavaScript.

**Was dabei ersatzlos entfiel** (rund 470 Zeilen):

- das Overlay-Markup und sein CSS im mu-Plugin
- der AJAX-Endpunkt `rc_book` — es gibt keinen JavaScript-Aufrufer mehr
- im Begleit-Plugin: der MutationObserver, `rcAfterBooking()`, die
  Diagnose-Box, das CSS gegen `.rc-overlay` / `.rc-modal` / `.rc-close`
  und die Regel, die den Menüknopf bei offenem Dialog ausblendete

**Die Schnittstelle zwischen den beiden Plugins** ist jetzt ein Hook statt
DOM-Beobachtung: Das mu-Plugin ruft am Ende

```php
do_action('repairffm_after_booking', $code, $post_id);
```

Das Begleit-Plugin hängt sich dort ein und gibt das freiwillige E-Mail-Feld
als normales HTML aus. Ist es nicht aktiv, steht dort nichts — die Buchung
bleibt vollständig.

> **Achtung bei künftigen Änderungen:** Die Buchungsseite darf nicht
> zwischengespeichert werden — sie zeigt, welche Termine frei sind. Das
> mu-Plugin setzt dafür `nocache_headers()`, sobald der Shortcode auf der
> Seite steht. Kommt ein Caching-Plugin dazu, muss `/termin-buchen/`
> ausgenommen werden.

### Die Buchung wohnt im Plugin — nicht mehr im mu-Plugin (ab 1.12.0)

mu-Plugins kennt der WordPress-Updater nicht: keine Version, kein Eintrag
unter „Plugins", kein Update-Knopf. Jede Änderung am Buchungsablauf musste
von Hand auf den Umbrel-Server kopiert werden.

Am 21.08.2026 hat sich gezeigt, warum das nicht trägt. Das Begleit-Plugin
zog sich nach dem Merge selbst von GitHub und ließ dabei den JavaScript-
Behelf fallen, der das E-Mail-Feld ins alte Popup schob. Das mu-Plugin lag
noch unverändert auf dem Server. Ergebnis: altes Popup, aber ohne Feld.
Zwei Hälften, zwei Aktualisierungswege, eine davon vergessen.

Seit 1.12.0 steht der komplette Buchungsablauf in
`repairffm-admin-tools.php` und fährt im selben Zug wie alles andere mit.

**Der Schalter für die Umstellung:**

```php
if (!function_exists('rc_build_slots')) {
    // ... kompletter Buchungsablauf ...
}
```

Liegt das alte mu-Plugin noch auf dem Server, hat es Vorrang (mu-Plugins
laden zuerst) und der Block bleibt still — sonst gäbe es einen Fatal Error
durch doppelt deklarierte Funktionen. Erst wenn
`/wp-content/mu-plugins/repairffm-core.php` gelöscht ist, übernimmt das
Plugin. **Die Reihenfolge spielt dadurch keine Rolle**, und ein
versehentlich zurückgespieltes mu-Plugin legt die Seite nicht lahm.

> Der Preis: Wird `repairffm-admin-tools` deaktiviert, ist auch die Buchung
> weg — der Custom Post Type `rc_booking` wird dann nicht mehr registriert
> und vorhandene Buchungen sind im Backend unsichtbar. Gelöscht ist nichts,
> sie tauchen beim Aktivieren wieder auf. Vorher hielt das mu-Plugin das
> offen, weil es sich nicht abschalten ließ.

**Übersichtlichkeit der Buchungsseite** (gleiche Version):

- Schrittanzeige „Was → Wann → Abschicken"; auf schmalen Displays bleibt
  nur der aktive Schritt beschriftet
- Der Einleitungstext der Seite wird ab Schritt 2 ausgeblendet. Er steht im
  Seiten-Editor und bleibt dort bearbeitbar — auf der Fertig-Seite forderte
  er aber zum Aussuchen auf, obwohl längst gebucht war. Gelöst über einen
  `the_content`-Filter, der alles vor dem Shortcode wegschneidet, sobald
  `?was`, `?wann` oder `?code` in der Adresse steht.
- Der Haken auf der Fertig-Seite ist gezeichnet statt ✅ — das Emoji kam auf
  iOS als klobiger grüner Kasten.

---

### Das Theme-Menü ausblenden — über die Adressen, nicht über Klassennamen (1.13.0)

**Zwei Anläufe waren wirkungslos**, beide aus demselben Grund. In 1.8.0 und
1.9.6 stand hier ein geratener CSS-Selektor:

```css
.rfat-nav-fallback header nav.wp-block-navigation { display: none !important; }
```

Das Markup des Themes ist uns unbekannt und von der Entwicklungsumgebung aus
nicht einsehbar — biospargel.org ist über den Proxy nicht erreichbar (403).
Der Selektor traf schlicht nichts, und weil CSS bei einem Fehlschlag
schweigt, fiel es erst über einen Screenshot auf. Zweimal.

**Was wir dagegen sicher kennen, sind die Adressen der Menüpunkte** — sie
stehen in unserem eigenen Menü daneben. Also sucht das Skript jetzt danach:

1. Alle Adressen aus dem eigenen Menü einsammeln
2. Links darauf finden, die *oberhalb* des Inhalts stehen (`main`, `#content`)
3. Kleinsten gemeinsamen Vorfahr bestimmen und ausblenden

**Adresse UND Beschriftung müssen passen.** Die Adresse allein reicht
nicht: Auf `/` zeigt in fast jedem Theme auch das **Logo** und der
Seitentitel. Nach Adresse allein gesucht, verschwand im Test der komplette
Seitenkopf — Logo und Titel mit. Die Beschriftung trennt beide sauber: Der
Menüpunkt heißt „Start", das Logo trägt den Seitennamen oder gar keinen
Text. Zusätzlich werden Links mit `img`/`svg`/`picture` übersprungen — das
ist immer das Logo, nie ein Menüpunkt.

**Die zweite Sicherung** ist ebenso wichtig: Enthält dieser Container merklich
mehr Text als die Menüpunkte zusammen (`gesamt > summe * 2 + 40`), steckt
vermutlich der Seitentitel mit drin. Dann werden nur die Links selbst
ausgeblendet, nicht der Container. Lieber ein leerer Streifen als ein
verschwundener Titel.

Unter zwei Treffern passiert nichts — das ist zu wenig, um sicher zu sein.

**Gegengeprüft** mit Chromium gegen fünf Theme-Strukturen, weil wir die
echte nicht kennen:

| Struktur | Ergebnis |
|---|---|
| `nav.wp-block-navigation` im `header` | `<ul>` ausgeblendet, Link im Fließtext bleibt |
| Buttons-Block, gar kein `nav` | `div.wp-block-buttons` ausgeblendet |
| Titel und Links im selben Container | Sicherung greift, Links einzeln aus, **Titel bleibt** |
| gar kein Theme-Menü | nichts passiert, keine Fehlgriffe |
| Logo **und** Titel verlinken auf `/` | nur die Menüliste aus, **Logo und Titel bleiben** |

In allen fünf Fällen blieben Seitentitel und Logo stehen.

> **Wenn oben doch noch etwas steht:** `?rfat_diag=1` an die Adresse hängen
> (nur als Administrator). Unten links steht dann, was gefunden und was
> ausgeblendet wurde. Ein Screenshot davon genügt, um es zu beheben — genau
> das hatte beim Schließen-Knopf des alten Popups die Raterei beendet.

### Kein Aufblitzen mehr: serverseitig statt im Fuß (1.14.0)

Die vier Knöpfe verschwanden zwar, aber **sichtbar**: Sie standen beim Laden
kurz da und wurden dann weggeschaltet. Kein Rätsel — das Skript lag im
Seitenfuß, das HTML des Kopfes war längst gezeichnet, bevor es überhaupt
lief.

Die Erkennung läuft deshalb jetzt zusätzlich beim Rendern, über den
`render_block`-Filter, nach denselben Regeln wie im Skript (Adresse **und**
Beschriftung, Bildlinks übersprungen, dieselbe Textlängen-Sicherung).

**Der Block wird ersatzlos entfernt**, nicht bloß ausgeblendet:

```php
return '';
```

Ein Zwischenstand hatte ihn nur eingepackt und per CSS versteckt. Das
beseitigte zwar das Aufblitzen, lieferte aber weiter Markup aus, das der
Browser aufbaut und dann wegwirft — Arbeit für jeden Besucher, für nichts.

Daraus folgt zwingend: **Unser Menü ist nicht länger auf schmale Fenster
beschränkt.** Am Server ist die Fensterbreite unbekannt; wer den Block
entfernt, nimmt ihn überall weg. Die Leiste steht deshalb in jedem Fenster
und ist ab jetzt *die* Navigation der Seite.

Das Skript im Fuß bleibt als Auffangnetz für Köpfe, die nicht über die
Block-Ausgabe laufen — **und wird nur dann überhaupt ausgeliefert:**

```php
<?php if (empty($GLOBALS['rfat_nav_markiert'])) : ?>
    … Erkennung …
<?php endif; ?>
```

Im Normalfall spart das rund 6 KB JavaScript pro Seitenaufruf (gemessen:
12 615 → 6 534 Zeichen Fuß-Ausgabe).

**Mit entfernt wurden**, weil sie ein Menü aufhübschten, das es nicht mehr
gibt: der `overlayMenu`-Filter, der Filter der „Termin abrufen" an die
Theme-Navigation hängte (steht ohnehin in unserem Menü), das CSS für die
Menü-Buttons des Themes und das für dessen Handy-Overlay — zusammen 144
Zeilen.

> Die Prüfung auf einen eigenen Hamburger des Themes verlangt jetzt, dass
> er **sichtbar** ist (`offsetParent !== null`). Auf „vorhanden" allein zu
> prüfen genügte nicht mehr: Themes verstecken ihren Knopf am Rechner per
> CSS, und wir hätten unser Menü dort mit weggeräumt — die Seite stünde
> ohne jede Navigation da.

**Geprüft** (PHP-Seite, sechs Blockformen):

| Block | Ergebnis |
|---|---|
| `<ul>` mit den vier Punkten | entfernt |
| Buttons-Block | entfernt |
| Container mit Titel **und** Links | unberührt → Skript übernimmt |
| `<header>` außen, `<nav>` innen | nur das `<nav>`, Header unberührt |
| Fließtext mit einem Menülink | unberührt |
| nur Logo- und Titel-Link | unberührt |

---

### Ein Termin, eine Seite (1.16.0)

Nach dem Buchen gab es eine Zwischenseite: Code, ein E-Mail-Feld, ein
Kalender-Knopf und ein Link „Zur Terminübersicht". Wer etwas ändern wollte,
musste erst dort weiterklicken.

Die Buchung leitet jetzt direkt auf `/termin-abrufen/?code=…&neu=1`. Diese
Seite zeigt **alles, was die Zwischenseite zeigte** — Termin, Code, das
freiwillige E-Mail-Feld mit Einwilligungs-Häkchen, den Kalender-Knopf, den
persönlichen Link — und zusätzlich Verschieben und Absagen.

**Was dabei entfiel** (183 Zeilen netto):

- `rc_step_done()` samt `rc_booking_by_code()`
- der Hook `repairffm_after_booking` und `rfat_after_booking_box()` —
  ein zweites E-Mail-Formular für dieselbe Sache
- dessen POST-Handler und das komplette `.rfat-ab-*`-CSS

`/termin-buchen/?code=…` leitet per **301** auf die Abruf-Seite um, damit
alte Lesezeichen und der Zurück-Knopf weiter funktionieren.

> **Neu abgesichert:** `/termin-abrufen/` wurde bisher **nirgends angelegt**
> — die Seite existierte nur, weil sie einmal von Hand erstellt worden war.
> Verlinkt wird sie an einem Dutzend Stellen (Bestätigungsmails,
> Abmeldelink, Kalendereintrag), und seit die Buchung dorthin führt, hängt
> der ganze Ablauf daran. Ein `init`-Lauf legt sie jetzt an, falls sie
> fehlt, veröffentlicht sie wieder, falls sie im Papierkorb liegt, und
> hängt den Shortcode an, falls er fehlt — **ohne** vorhandenen Text zu
> überschreiben. Einmalig, über die Option `rfat_abrufseite` gedeckelt.

Der Hinweis „Termin vorgemerkt, bitte auf die Bestätigung warten" wandert
mit: `?neu=1` blendet ihn oben auf der Abruf-Seite ein. Der Code darf nicht
kommentarlos verschwinden — er ist das Einzige, womit man später wieder an
den Termin kommt.

---

### Performance — und was davon nicht übernommen wurde (1.15.0)

Am 21.08.2026 kam eine fremde Fassung 1.15.0 („Klima-Update") herein.
Vier ihrer fünf Änderungen sind übernommen, zwei davon anders umgesetzt,
eine ist **abgelehnt**.

**Übernommen:**

| Änderung | Wirkung |
|---|---|
| Meta-Batching in `rfat_analyse_booking` | `$all_meta` war schon geladen; vier `get_post_meta` je Buchung fielen weg |
| `no_found_rows`, kein Term-Cache | spart die Zähl-Abfrage; Buchungen haben keine Kategorien |
| Buchungs-Lookup 60 s gecacht | die Selbstverwaltungs-Seite schlägt denselben Code mehrfach nach |
| Slot-Belegung 2 min gecacht | steht auf jeder Slot-Seite an |

Gemessen mit gestubbter Umgebung: erster Aufruf 3 DB-Zugriffe, zweiter 0.

**Anders umgesetzt — Cache-Invalidierung am Hook statt an der Aufrufstelle.**
Die fremde Fassung rief `delete_transient` an den Stellen auf, die ihr
bekannt waren. Storniert wird aber an **vier** Stellen (Selbstverwaltung,
Verschieben, Mail-Link, Papierkorb im Backend), und die fünfte kommt
bestimmt. Jetzt hängt es an `save_post_rc_booking`, `trashed_post`,
`untrashed_post` und `before_delete_post` — das kann man nicht vergessen.

**Anders umgesetzt — CSS beim Ausliefern statt in der Quelle.**
Die fremde Fassung hatte das CSS *in der Datei* minifiziert: aus 593 Zeilen
mit 23 erklärenden Kommentaren wurden drei Zeilen, eine davon 6768 Zeichen
lang. Beim Besucher kommt so dasselbe an — aber die Begründungen waren weg,
und genau die haben hier mehrfach verhindert, dass ein Fehler ein zweites
Mal passiert.

`rfat_minify_style_tags()` streicht jetzt beim Senden. Nachgemessen: **13 393
Zeichen, beide Fassungen zeichengleich.** Quelle wieder 593 Zeilen mit 23
Kommentaren.

> **Abgelehnt: `Cache-Control: public, max-age=86400` auf der .ics-Datei.**
>
> ```php
> nocache_headers();                                  // Zeile 1765
> …
> header('Cache-Control: public, max-age=86400');     // Zeile 1769 — hebt es auf
> ```
>
> Die Datei liegt unter `?ics=RC-XXXXX` und enthält Termin, Kategorie und
> Buchungscode einer bestimmten Person. `public` erlaubt **jedem
> Zwischenspeicher** — und die Seite läuft laut eigener
> Datenschutzerklärung über Cloudflare — diese Datei einen Tag lang
> vorzuhalten und auszuliefern. Das widerspricht der Datensparsamkeit, die
> die Seite zusagt. Dazu kommt: Wird der Termin verschoben oder storniert,
> bekommt man 24 Stunden lang die alte Datei.
>
> Persönliche Daten gehören nicht in einen geteilten Cache.
> `nocache_headers()` bleibt.

---

### Kein Balken hinter dem Knopf (1.14.1)

1.13.0 legte beim Scrollen eine weiße, halbdurchsichtige Leiste hinter den
Hamburger. Auf dem Handy sah das kaputt aus, aus **zwei** Gründen:

**1. Die harte Kante.** Der Balken zog eine waagerechte Linie quer durch
den Fließtext — Zeilen wurden mittendrin abgeschnitten. Ein Balken bringt
nichts, was der Schatten des Knopfes nicht auch leistet; er ist ersatzlos
weg.

**2. Der Versatz für die Adminleiste war falsch.** WordPress' Adminleiste
verhält sich je nach Fensterbreite anders:

| Fenster | `#wpadminbar` | Höhe |
|---|---|---|
| ≥ 783 px | `position: fixed` — bleibt oben stehen | 32 px |
| ≤ 782 px | `position: absolute` — **scrollt mit weg** | 46 px |

`body.admin-bar .rfat-topbar { top: 46px }` galt für beide. Auf dem Handy
blieb die Leiste dadurch 46 px unter dem Fensterrand hängen, obwohl die
Adminleiste längst weggescrollt war — und durch die Lücke darüber lief der
Seiteninhalt. Genau das Abgeschnittene im Screenshot vom 21.08.

Richtig ist:

```css
body.admin-bar .rfat-topbar          { top: 46px; }  /* noch nicht gescrollt */
body.admin-bar .rfat-topbar.is-small { top: 0; }     /* Adminleiste ist weg */
@media screen and (min-width: 783px) {
    body.admin-bar .rfat-topbar,
    body.admin-bar .rfat-topbar.is-small { top: 32px; }
}
```

> Betroffen waren nur eingeloggte Team-Mitglieder — Besucher:innen haben
> keine Adminleiste. Deshalb fiel es erst beim Draufschauen auf und nicht
> im Test.

---

### Kopfleiste und Hamburger (1.13.0)

Der Knopf war weiß mit dünnem Rand — auf dem ohnehin weißen Seitenkopf ging
er darin unter. Jetzt im Grün der Seite (`#2f7d4f`), damit er als das
erkennbar ist, was er ist: die einzige Navigation.

Drei einzelne `<span>` statt eines SVG-Pfades, weil sich nur so beim Öffnen
ein Kreuz daraus legen lässt. Der mittlere Strich ist auf 68 % gekürzt, das
nimmt dem Symbol die Strenge. `prefers-reduced-motion` schaltet alle
Übergänge ab.

Der Hamburger schwebte bisher frei in der Ecke. Jetzt sitzt er in einer
Leiste am oberen Rand, die beim Herunterscrollen schrumpft: oben 62 px für
eine große Trefferfläche, ab 40 px Scrollweg 44 px mit weißem Hintergrund,
damit sie wenig vom Inhalt verdeckt. Die Leiste selbst hat
`pointer-events: none` — nur der Knopf fängt Klicks ab, sonst läge ein
unsichtbarer Streifen über der Seite.

---

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
Optionen:       rfat_notify_to, rfat_notify_log, rfat_release_log,
                rfat_gate_entfernt, rfat_gate_hinweis, rfat_gate_datenschutz,
                rfat_haltung_seite, rfat_beispielseite
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
