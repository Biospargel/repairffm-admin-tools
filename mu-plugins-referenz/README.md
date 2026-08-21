# Referenz: Buchungs-Plugin des Kern-Systems

> **Ueberholt seit Plugin 1.12.0.** Die Buchung steckt jetzt in
> `repairffm-admin-tools.php` und aktualisiert sich ueber GitHub mit.
> Die Datei `/wp-content/mu-plugins/repairffm-core.php` gehoert
> **geloescht** - solange sie liegen bleibt, hat sie Vorrang und
> Aenderungen am Buchungsablauf kommen nicht an.
>
> Warum der Umzug: mu-Plugins kennt der WordPress-Updater nicht. Sie
> haben keine Version, tauchen unter "Plugins" nicht auf und lassen sich
> nicht aktualisieren - jede Aenderung musste von Hand auf den Server.
> Genau daran liefen die beiden Haelften am 21.08.2026 auseinander:
> Das Begleit-Plugin war aktuell, das mu-Plugin nicht, und dazwischen
> verschwand das E-Mail-Feld.
>
> Die Dateien hier bleiben als Nachschlagewerk und als Beleg, wie der
> Ablauf vor dem Umzug aussah.

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


## repairffm-core-NEU.php

Vorgeschlagene Fassung zum Einspielen (Stand 21.08.2026). Geaendert sind
**ausschliesslich Texte** - Buchungslogik, Slot-Berechnung und AJAX sind
Zeile fuer Zeile identisch.

| Was | Warum |
|---|---|
| Impressum mit echten Daten | Platzhalter `[Name]`, `[Strasse]` usw. waren nie ersetzt |
| Haftung bei Reparaturen | Der einzige Haftungsabschnitt, der hier wirklich etwas bewirkt |
| Datenschutz: E-Mail-Abschnitt | Freiwillige Adresse seit Plugin 1.8.4 |
| "weder Name noch E-Mail" entfernt | Stimmte mit dem E-Mail-Feld nicht mehr |
| Dialog: "Termin vorgemerkt" | Termine gelten erst nach Zusage |
| `rc_setup_version` 3 -> 4 | **Ohne diesen Sprung passiert gar nichts** |

Einspielen: Datei auf dem Server nach `repairffm-core.php` kopieren, dann
eine beliebige Seite aufrufen. Das Hochzaehlen der Setup-Version sorgt
dafuer, dass Impressum und Datenschutz neu geschrieben werden.

> Achtung: Dabei werden die WordPress-Seiten **ueberschrieben**. Eigene
> Aenderungen, die dort direkt vorgenommen wurden, gehen verloren - genau
> deshalb gehoeren die Texte hierher und nicht in den Seiteneditor.
