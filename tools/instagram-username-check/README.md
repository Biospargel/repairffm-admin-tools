# Instagram-Handle-Check

Kleines Hilfsskript, das prueft, ob kurze Instagram-Handles noch frei sind. Es
laedt mit Chromium (Playwright) die oeffentliche Profilseite und wertet den
Seitentitel aus.

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
| `BASE_DELAY`  | Pause zwischen zwei Kandidaten in ms (Standard `25000`) |
| `CHROME_PATH` | Pfad zum Chromium-Binary                                |
| `PLAYWRIGHT`  | Pfad zum `playwright`-Modul                             |
| `HTTPS_PROXY` | wird an Chromium durchgereicht, falls gesetzt           |

## Einordnung der Ergebnisse

| `state`       | Bedeutung                                                    |
|---------------|--------------------------------------------------------------|
| `taken`       | Profilseite existiert, Titel enthaelt `(@handle)`             |
| `free`        | Instagram liefert "Page Not Found" – kein Profil vorhanden    |
| `ratelimited` | Weiterleitung auf `/accounts/login/` bzw. HTTP 429            |
| `unknown`     | Seite passte in kein Muster, manuell nachsehen                |

`free` heisst **nicht** automatisch registrierbar: geloeschte, gesperrte und
von Instagram reservierte Handles liefern dieselbe Seite. Verbindlich ist nur
das Registrierungsformular.

## Grenzen

Instagram drosselt Profilaufrufe ohne Login sehr frueh – von Rechenzentrums-IPs
kommen oft nur wenige Abrufe durch, danach kommt dauerhaft die Weiterleitung
auf die Login-Seite. Das Skript wartet dann mit exponentiellem Backoff
(60 s bis 10 min). Fuer laengere Listen die `BASE_DELAY` hochsetzen und den
Lauf ueber mehrere Sitzungen verteilen; `results.json` haelt den Fortschritt.

Wer Chromium hinter einem inspizierenden Proxy betreibt: Chromes
TLS-1.3-ClientHello (ECH-GREASE, ML-KEM-Keyshare) wird von manchen Proxies
zurueckgesetzt (`ERR_CONNECTION_RESET`). Deshalb startet das Skript Chromium mit
`--ssl-version-max=tls1.2` und ohne ECH/ML-KEM.
