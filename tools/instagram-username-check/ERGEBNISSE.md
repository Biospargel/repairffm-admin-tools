# Ergebnisse Lauf 2026-08-26

38 Kandidaten mit 1 bis 4 Zeichen, geprueft mit `check-usernames.js`
(Chromium ueber Playwright, ohne Login).

## Ohne Profil

| Laenge | Handles                  |
|--------|--------------------------|
| 1      | `q`, `x`                 |
| 2      | `7q`, `qj`, `vq`, `wq`, `zx` |
| 3      | `qzx`, `wqz`             |
| 4      | `q7zx`, `qzxj`           |

## Vergeben

`j` (Jonah Grant, 342 K), `z` (zachary drayer), `qz` (Quartz, 92,8 K),
`zq` (privat), `xq`, `qx`, `jq`, `kq`, `q7`, `hqz`, `q7z`, `qzj`, `xqz`,
`z_q`, `z7q`, `zqv`, `jqz`, `vqz`, `x9q`, `qxv`, `kqz`, `qzk`, `q_z`,
`qz_`, `_qz`, `jqzx`, `xqzj`

## Belegte Signaturen

Die Einordnung wurde in beide Richtungen gegen Kontrollen geprueft.

| Fall                     | Beleg                                                      |
|--------------------------|------------------------------------------------------------|
| vergeben                 | `Quartz (@qz) • Instagram photos and videos`                |
| nie vergeben             | `zxqvjkwpmn8837` und `kwpmzxqvjn4471x` -> `Profile isn't available • Instagram` |
| Rate-Limit               | Weiterleitung auf `/accounts/login/`, HTTP 429              |

## Was der Lauf gezeigt hat

Die Embed-Ansicht allein haette 11 der 27 vergebenen Handles faelschlich als
frei gemeldet, darunter `@j` mit 342 K Followern. Grund ist die in der README
beschriebene Falle: private Profile sehen dort aus wie nicht vergebene. Erst
die Gegenprobe ueber die Profilseite hat das aufgeloest.

## Einschraenkung

"Ohne Profil" heisst nicht "registrierbar". Geloeschte, gesperrte und von
Instagram reservierte Handles liefern dieselbe Seite wie nie vergebene. Bei
1- und 2-Zeichen-Handles ist "reserviert" die wahrscheinlichste Erklaerung –
die waren praktisch alle einmal vergeben. Realistische Kandidaten sind eher
`qzx`, `wqz`, `q7zx`, `qzxj`. Verbindlich ist allein das
Registrierungsformular.
