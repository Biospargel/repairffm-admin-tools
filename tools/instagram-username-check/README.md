# Instagram-Handle-Check

Kleines Hilfsskript, das prueft, ob kurze Instagram-Handles noch frei sind. Es
laedt die Profilseiten mit Chromium (Playwright) und wertet sie aus.

## Verwendung

```bash
npm install playwright        # oder global vorhandenes Playwright nutzen
node check-usernames.js       # liest candidates.txt
node check-usernames.js meine-liste.txt
```

Ergebnisse landen in `results.json` und werden nach jedem Kandidaten
geschrieben. Ein erneuter Aufruf ueberspringt alles, was bereits eindeutig
eingeordnet wurde.

### Umgebungsvariablen

| Variable      | Bedeutung                                              |
|---------------|--------------------------------------------------------|
| `BASE_DELAY`  | Pause zwischen zwei Abrufen in ms (Standard `9000`)     |
| `CHROME_PATH` | Pfad zum Chromium-Binary                                |
| `PLAYWRIGHT`  | Pfad zum `playwright`-Modul                             |
| `HTTPS_PROXY` | wird an Chromium durchgereicht, falls gesetzt           |

## Wie geprueft wird

Zweistufig, weil die verbindliche Quelle die knappere ist.

**Stufe 1 – Embed-Ansicht** (`/<handle>/embed/`). Ohne Login abrufbar und kaum
gedrosselt, aber nur fuer *oeffentliche* Profile gefuellt. Zeigt sie eine
Profilkarte, ist der Handle sicher vergeben und Stufe 2 entfaellt.

**Stufe 2 – Profilseite** (`/<handle>/`). Verbindlich, aber ohne Login frueh
gedrosselt. Laeuft nur fuer alles, was Stufe 1 nicht klaeren konnte.

### Die Falle: private Profile

Die Embed-Ansicht liefert fuer private Profile **dieselbe** Meldung wie fuer
gar nicht vergebene Handles:

> The link to this profile may be broken, or the profile may have been removed.

Sie kann also nur „vergeben" beweisen, niemals „frei". Belegt an zwei Faellen:

| Handle | Embed-Ansicht        | Profilseite                        |
|--------|----------------------|------------------------------------|
| `@zq`  | „profile removed"    | Sadeq Taha (@zq), privates Profil  |
| `@j`   | „Invalid Link"       | Jonah Grant (@j), 342 K Follower   |

Wer das uebersieht, haelt jedes zweite private Kurzprofil faelschlich fuer
frei. Deshalb geht jeder nicht in Stufe 1 geklaerte Kandidat zwingend durch
Stufe 2.

## Einordnung der Ergebnisse

| `state`       | Bedeutung                                                    |
|---------------|--------------------------------------------------------------|
| `taken`       | Profil existiert                                              |
| `free`        | Profilseite liefert "Page Not Found" – kein Profil vorhanden  |
| `ratelimited` | Weiterleitung auf `/accounts/login/` bzw. HTTP 429            |
| `unknown`     | Seite passte in kein Muster, manuell nachsehen                |

`free` heisst **nicht** automatisch registrierbar: geloeschte, gesperrte und
von Instagram reservierte Handles liefern dieselbe Seite. Verbindlich ist nur
das Registrierungsformular.

## Grenzen

Instagram drosselt Profilaufrufe ohne Login sehr frueh – von Rechenzentrums-IPs
kommen oft nur wenige Abrufe durch, danach kommt die Weiterleitung auf die
Login-Seite. Das Skript wartet dann mit exponentiellem Backoff (45 s bis
10 min). Fuer laengere Listen `BASE_DELAY` hochsetzen und den Lauf ueber
mehrere Sitzungen verteilen; `results.json` haelt den Fortschritt.

Wer Chromium hinter einem inspizierenden Proxy betreibt: Chromes
TLS-1.3-ClientHello (ECH-GREASE, ML-KEM-Keyshare) wird von manchen Proxies
zurueckgesetzt (`ERR_CONNECTION_RESET`). Deshalb startet das Skript Chromium mit
`--ssl-version-max=tls1.2` und ohne ECH/ML-KEM.
