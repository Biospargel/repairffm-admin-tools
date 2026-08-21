# Referenz: Buchungs-Plugin des Kern-Systems

Kopie von `/wp-content/mu-plugins/repairffm-core.php`, Stand 21.08.2026.
**Nur zum Nachschlagen.** Aenderungen hier wirken auf der Website nicht -
die Live-Datei liegt auf dem Umbrel-Server unter
`/home/umbrel/umbrel/app-data/wordpress/data/wordpress/wp-content/mu-plugins/`.

Ausgeliefert wird sie nicht: Der Release-Workflow packt ausschliesslich
`repairffm-admin-tools.php` in die Zip.

## Bewusst NICHT enthalten

`repairffm-gate.php` (Kennwortschutz). Darin steht zwar kein Klartext-
Passwort, aber ein SHA-256-Hash mit fest eingebautem, bekanntem Salt.
Beides zusammen laesst sich offline durchprobieren - in einem
oeffentlichen Repo hat das nichts zu suchen.

## Was daraus folgt

| Meta-Key | Inhalt |
|---|---|
| `_rc_slot` | z. B. `it_2026-08-29_1430` |
| `_rc_code` | Buchungscode, z. B. `RC-AB12C` |
| `_rc_cat`  | `it` oder `ebike` |
| `_rc_date` | `2026-08-29` |
| `_rc_time` | `14:30` |
| `_rc_note` | freiwillige Problembeschreibung |

Slots: die naechsten vier Samstage. IT halbstuendlich 14:00-16:30,
E-Bike stuendlich 14:00-16:00. Belegt ist ein Slot nur, solange eine
Buchung den Status `publish` hat - Papierkorb gibt ihn also frei.
