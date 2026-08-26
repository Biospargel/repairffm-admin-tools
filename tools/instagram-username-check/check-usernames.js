#!/usr/bin/env node
/**
 * Instagram-Handle-Check via Chromium (Playwright).
 *
 * Laedt fuer jeden Kandidaten die oeffentliche Profilseite und wertet den
 * Seitentitel aus:
 *   "<Name> (@handle) • Instagram photos and videos"  -> vergeben
 *   "Page Not Found • Instagram"                      -> kein Profil vorhanden
 *   Weiterleitung auf /accounts/login/ bzw. HTTP 429   -> Rate-Limit
 *
 * Bei Rate-Limit wird mit exponentiellem Backoff erneut versucht. Ergebnisse
 * landen in results.json und werden nach jedem Kandidaten geschrieben, ein
 * erneuter Lauf setzt also dort fort, wo der vorige aufgehoert hat.
 *
 * Aufruf:
 *   node check-usernames.js [kandidaten-datei]
 *
 * Umgebungsvariablen:
 *   BASE_DELAY   Pause zwischen zwei Kandidaten in ms (Standard 25000)
 *   CHROME_PATH  Pfad zum Chromium-Binary
 *   PLAYWRIGHT   Pfad zum playwright-Modul
 *
 * Hinweis: "kein Profil vorhanden" ist nicht dasselbe wie "registrierbar".
 * Geloeschte, gesperrte und von Instagram reservierte Handles liefern
 * dieselbe Seite. Das letzte Wort hat immer das Registrierungsformular.
 */
const fs = require('fs');
const path = require('path');

const PLAYWRIGHT = process.env.PLAYWRIGHT || 'playwright';
const { chromium } = require(PLAYWRIGHT);

const CHROME_PATH = process.env.CHROME_PATH || undefined;
const BASE_DELAY = Number(process.env.BASE_DELAY || 25000);
const LIST = process.argv[2] || path.join(__dirname, 'candidates.txt');
const OUT = path.join(__dirname, 'results.json');

const candidates = fs
  .readFileSync(LIST, 'utf8')
  .split('\n')
  .map((s) => s.trim())
  .filter((s) => s && !s.startsWith('#'));

const results = fs.existsSync(OUT) ? JSON.parse(fs.readFileSync(OUT, 'utf8')) : {};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function classify(page, user) {
  let docStatus = null;
  const onResp = (r) => {
    if (r.request().resourceType() === 'document') docStatus = r.status();
  };
  page.on('response', onResp);
  try {
    await page.goto('https://www.instagram.com/' + user + '/', {
      waitUntil: 'domcontentloaded',
      timeout: 45000,
    });
  } catch (e) {
    // Chromium wirft bei 4xx ohne Body ERR_HTTP_RESPONSE_CODE_FAILURE;
    // die Einordnung passiert unten ueber docStatus und URL.
  } finally {
    page.off('response', onResp);
  }

  const url = page.url();
  let title = '';
  try {
    title = await page.title();
  } catch (e) {}

  if (docStatus === 429 || url.includes('/accounts/login')) return { state: 'ratelimited', title, docStatus };
  if (/\(@/.test(title)) return { state: 'taken', title, docStatus };
  if (/Page Not Found|Seite nicht gefunden/i.test(title)) return { state: 'free', title, docStatus };

  let body = '';
  try {
    body = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').slice(0, 200);
  } catch (e) {}
  if (/isn't available|nicht verf/i.test(body)) return { state: 'free', title, body, docStatus };
  if (/Log in|Anmelden/i.test(body)) return { state: 'ratelimited', title, body, docStatus };
  return { state: 'unknown', title, body, docStatus };
}

(async () => {
  const browser = await chromium.launch({
    executablePath: CHROME_PATH,
    args: [
      '--no-sandbox',
      '--disable-dev-shm-usage',
      // Manche inspizierenden Proxies setzen Chromes TLS-1.3-ClientHello
      // (ECH-GREASE, ML-KEM-Keyshare) zurueck; ohne diese beiden Schalter
      // scheitert jeder Aufruf an ERR_CONNECTION_RESET.
      '--ssl-version-max=tls1.2',
      '--disable-features=EncryptedClientHello,UseMLKEM,PostQuantumKyber',
    ],
    proxy: process.env.HTTPS_PROXY ? { server: process.env.HTTPS_PROXY } : undefined,
  });
  const page = await browser.newPage();

  let backoff = 60000;
  for (const user of candidates) {
    const done = results[user];
    if (done && done.state !== 'ratelimited' && done.state !== 'unknown') continue;

    for (let attempt = 0; attempt < 4; attempt++) {
      const res = await classify(page, user);
      if (res.state !== 'ratelimited') {
        results[user] = res;
        console.log(`${user.padEnd(10)} ${res.state.padEnd(6)} ${(res.title || '').slice(0, 60)}`);
        backoff = Math.max(60000, backoff / 2);
        break;
      }
      console.log(`${user.padEnd(10)} rate-limit -> warte ${Math.round(backoff / 1000)}s`);
      await sleep(backoff);
      backoff = Math.min(backoff * 2, 600000);
      await page.context().clearCookies();
    }
    if (!results[user]) results[user] = { state: 'ratelimited' };

    fs.writeFileSync(OUT, JSON.stringify(results, null, 1));
    await sleep(BASE_DELAY + Math.random() * 10000);
  }

  await browser.close();

  const free = Object.entries(results)
    .filter(([, v]) => v.state === 'free')
    .map(([k]) => k)
    .sort((a, b) => a.length - b.length || a.localeCompare(b));
  console.log('\nOhne Profil (Kandidaten):', free.join(', ') || '(keine)');
})();
