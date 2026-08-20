# v1.8.0 – Handy-Menü repariert, Updater gehärtet

## Handy-Menü (Hamburger)

**Problem:** Auf dem Handy erschien überhaupt kein Hamburger-Button.

**Ursache:** WordPress rendert das Overlay-Markup samt Button ausschließlich
für `core/navigation`-Blöcke. Ist die Kopf-Navigation ein anderer Block oder
ein klassisches Menü, entsteht gar kein Button — der `overlayMenu`-Filter aus
1.6.0 lief ins Leere.

**Lösung:** Ein eigenständiges Handy-Menü, das nicht vom Theme abhängt.
Button und Overlay werden serverseitig ausgegeben, die Menüpunkte kommen aus
echten WordPress-Daten (klassisches Menü → `wp_navigation`-Post → Seiten).
Bringt das Theme doch ein funktionierendes Overlay mit, entfernt sich unseres
beim Laden selbst — zwei Hamburger sind ausgeschlossen.

Das Menü ist barrierefrei: `aria-expanded`, Escape schließt, Fokus bleibt im
offenen Menü gefangen und kehrt beim Schließen zurück.

## Weitere Korrekturen

- **Breakpoint korrigiert:** Menü-Styling lief bis 782px (der wp-admin-Wert),
  WordPress schaltet aber bei 600px auf Overlay um. Zwischen 600 und 782px traf
  Mobil-Styling auf eine Desktop-Navigation.
- **`overflow-x: hidden` von `html` auf `body` verschoben** — auf `html`
  schaltet es `position: sticky` für alle Kindelemente ab.
- **Overlay scrollt jetzt** (`overflow-y: auto`); bei vielen Menüpunkten waren
  die unteren vorher nicht erreichbar.
- **Adminleiste berücksichtigt** — eingeloggt verdeckte sie den Button.
- **TypeError behoben:** ein `<li>` ohne Link ließ `clone.querySelector('a')`
  auf `null` laufen und riss den Rest des Skripts mit.

## GitHub-Updater

- **Slug-Fix:** `dirname()` liefert `.`, wenn das Plugin als Einzeldatei direkt
  in `/wp-content/plugins/` liegt — der Slug war dann kaputt.
- **Vollständige Update-Felder** (`id`, `plugin`, `new_version`, `tested`,
  `requires_php`); ohne sie blieb die Zeile auf der Plugin-Seite leer.
- **Ordnername wird beim Update korrigiert** (`upgrader_source_selection`).
  Vorher hätte ein abweichender Ordnername in der Zip das Plugin unter neuem
  Namen installiert und deaktiviert zurückgelassen.
- **Asset-Auswahl** bevorzugt jetzt die Zip, deren Name zum Plugin passt.

## Installation

Zip `repairffm-admin-tools-1.8.0.zip` als Release-Asset anhängen (Tag `v1.8.0`).
Ab dieser Version meldet sich das nächste Update von selbst im Dashboard.
