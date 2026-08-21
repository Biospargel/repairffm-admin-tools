# Dateizugriff auf `mu-plugins` einrichten

Kurzanleitung, um Claude Code Zugriff auf den Quellcode des Buchungs-Plugins
zu geben. Ergänzung zum Projekt-Handoff (`HANDOFF.md`).

---

## Worum es geht

Die Buchungslogik von biospargel.org steckt in einem **Must-Use-Plugin**:

```
/wp-content/mu-plugins/   →  „Repair FFM – Core (Buchung & Seiten)" v1.0
                             „Repair FFM – Kennwortschutz (WordPress)" v1.0
```

Dieser Code war bisher **nie einsehbar**. Alles, was über das Datenmodell
bekannt ist, wurde aus dem gerenderten HTML rekonstruiert — mit entsprechenden
Umwegen im Companion-Plugin (siehe `HANDOFF.md`, Abschnitte 3.1 und 3.3).

**Ohne Lesezugriff auf dieses Verzeichnis** bleiben die empfohlenen ersten
Schritte aus dem Handoff blockiert. Zum reinen *Verstehen* genügt Lesezugriff;
Schreibzugriff auf die Live-Seite ist dafür nicht nötig.

---

## Ausgangslage

- Website läuft **selbst gehostet** auf einem **Umbrel-Server**
- Umbrel-Apps laufen in **Docker**
- Claude Code läuft auf dem PC → braucht die Dateien lokal oder per SSH

---

## Weg A (empfohlen): Claude Code direkt auf dem Server

Kein Kopieren, kein Sync — die Dateien liegen einfach vor.

### 1. Auf den Server

```bash
ssh umbrel@umbrel.local          # oder die lokale IP, z. B. 192.168.1.42
```

### 2. Verzeichnis finden

App-Daten liegen typischerweise unter `~/umbrel/app-data/`:

```bash
ls ~/umbrel/app-data/wordpress/data/
```

Falls die Struktur abweicht, findet dieser Befehl den Pfad zuverlässig:

```bash
find ~/umbrel/app-data -type d -name mu-plugins 2>/dev/null
```

Alternativ direkt im Container nachsehen:

```bash
docker ps | grep -i wordpress
docker exec -it <container-name> ls /var/www/html/wp-content/mu-plugins/
```

### 3. Claude Code dort starten

Im gefundenen Verzeichnis (bzw. im `wp-content`-Elternordner) starten.
Voraussetzung: Node.js auf dem Server — Umbrel läuft auf Linux, das passt.

---

## Weg B: Verzeichnis lokal mounten

`wp-content` per SFTP/SSHFS auf dem PC einbinden und Claude Code auf diesen
Ordner zeigen lassen.

Funktioniert, ist aber langsamer und bei Verbindungsabbrüchen unangenehm.

---

## Weg C: Nur zum Lesen herunterkopieren

Wenn es zunächst nur ums **Verstehen** geht — der schnellste Einstieg:

```bash
scp -r umbrel@umbrel.local:<gefundener-pfad>/mu-plugins ./mu-plugins-copy
```

Damit lassen sich Datenmodell und Slot-Logik analysieren, ohne Schreibzugriff
auf die Live-Seite zu riskieren. Für die Handoff-Punkte **1, 2, 3 und 5**
(Abschnitt 8) reicht das vollständig aus.

---

## ⚠️ Sicherheitshinweis

**SMB (Port 445) oder SSH (Port 22) nicht ins offene Internet weiterleiten.**
Offenes SMB ist einer der bekanntesten Angriffsvektoren überhaupt.

Für Fernzugriff von außerhalb des Heimnetzes:

- **Tailscale** – einfachstes sicheres Setup, als Umbrel-App verfügbar
- **Cloudflare Tunnel** – etwas mehr Konfiguration, dafür sehr flexibel

Beides erspart offene Ports und ist in ~15 Minuten eingerichtet.

---

## Bereits geprüfte Wege, die nicht funktionieren

| Weg | Ergebnis |
|---|---|
| WordPress-Plugin-Editor (wp-admin) | Must-Use-Plugins sind dort grundsätzlich **nicht** editierbar — auch nicht lesbar |
| WordPress.com-MCP über Jetpack | Jetpack ist verbunden, aber MCP-Schreibzugriff verlangt **kostenpflichtigen** Plan (Jetpack AI / Complete) |
| GitHub-Connector in Claude | Existiert nicht im Connector-Verzeichnis |
| Direkter Netzwerkzugriff aus der Cloud-Sandbox | Firewall blockiert `biospargel.org` |

> **Korrektur (21.08.2026):** `github.com` ist aus der Cloud-Sandbox sehr wohl
> erreichbar — die gesamte Plugin-Entwicklung bis 1.9.6 lief darüber. Blockiert
> ist nur `biospargel.org`. Genau deshalb funktioniert **Weg C**: Dateien einmal
> ins Repo legen, und die Sandbox kann sie lesen.

Diese Liste vermeidet, dass jemand die Sackgassen erneut abläuft.

---

## Was danach als Erstes drankommt

Aus `HANDOFF.md`, Abschnitt 8 — sobald der Code lesbar ist:

1. **mu-plugins lesen** – Datenmodell und Slot-Logik verstehen
2. **Feld-Erkennung ersetzen** – `rfat_analyse_booking()` rät Felder anhand
   ihrer Wertform; durch direkte Meta-Keys (`_rc_slot`, `_rc_cat`) ablösen
3. **Verschieben-Flow vereinfachen** – aktuell zweistufig, weil die Slot-Regeln
   unbekannt waren; mit Codezugriff wird daraus direktes Umbuchen
4. **Mobile Darstellung** – `theme.json` des Themes lesen statt CSS-Selektoren
   zu raten
5. **Buchungscode** – wird derzeit aus dem Post-Titel geparst; falls das
   mu-Plugin ihn als Meta-Feld speichert, direkt daher nehmen
