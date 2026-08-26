#!/usr/bin/env node
/**
 * Instagram-Handle-Check via Chromium (Playwright), zweistufig.
 *
 * Stufe 1 – Embed-Ansicht `https://www.instagram.com/<handle>/embed/`
 *   Sie ist fuer die Einbindung auf fremden Seiten gedacht und deshalb ohne
 *   Login abrufbar, wird aber nur fuer *oeffentliche* Profile gefuellt:
 *
 *     "<handle> <Name> 1,2M followers ..."  -> sicher vergeben, fertig
 *     "profile may have been removed"       -> unklar, Stufe 2 noetig
 *     "Invalid Link"                        -> unklar, Stufe 2 noetig
 *
 *   Achtung: private Profile liefern hier dieselbe Meldung wie nicht
 *   vergebene Handles. Die Embed-Ansicht kann also nur "vergeben" beweisen,
 *   niemals "frei" – @zq (privat) und @j sahen hier beide aus wie frei,
 *   sind aber beide vergeben.
 *
 * Stufe 2 – Profilseite `https://www.instagram.com/<handle>/`
 *   Sie ist verbindlich, wird ohne Login aber frueh gedrosselt:
 *
 *     Titel "<Name> (@handle) • Instagram photos and videos" -> vergeben
 *     Titel "Page Not Found • Instagram"                     -> kein Profil
 *     HTTP 429 / Weiterleitung auf /accounts/login/          -> Rate-Limit
 *
 * Stufe 1 spart die knappe Ressource: alles, was dort schon als vergeben
 * feststeht, belastet die Profilseite nicht mehr.
 *
 * Bei Rate-Limit wird mit exponentiellem Backoff erneut versucht. Ergebnisse
 * landen in results.json und werden nach jedem Kandidaten geschrieben, ein
 * erneuter Lauf setzt also dort fort, wo der vorige aufgehoert hat.
 *
 * Aufruf:
 *   node check-usernames.js [kandidaten-datei]
 *
 * Umgebungsvariablen:
 *   BASE_DELAY   Pause zwischen zwei Kandidaten in ms (Standard 9000)
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
const BASE_DELAY = Number(process.env.BASE_DELAY || 9000);
const LIST = process.argv[2] || path.join(__dirname, 'candidates.txt');
const OUT = path.join(__dirname, 'results.json');

const candidates = fs
  .readFileSync(LIST, 'utf8')
  .split('\n')
  .map((s) => s.trim())
  .filter((s) => s && !s.startsWith('#'));

const results = fs.existsSync(OUT) ? JSON.parse(fs.readFileSync(OUT, 'utf8')) : {};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const NO_PUBLIC_PROFILE = /link to this profile may be broken|profile may have been removed|Invalid Link/i;

async function load(page, url, waitUntil) {
  let docStatus = null;
  const onResp = (r) => {
    if (r.request().resourceType() === 'document') docStatus = r.status();
  };
  page.on('response', onResp);
  try {
    await page.goto(url, { waitUntil, timeout: 45000 });
  } catch (e) {
    // Chromium wirft bei 4xx ohne Body ERR_HTTP_RESPONSE_CODE_FAILURE;
    // die Einordnung passiert ueber docStatus und Seiteninhalt.
  } finally {
    page.off('response', onResp);
  }
  await sleep(1500);

  let title = '', text = '';
  try {
    title = await page.title();
    text = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').trim();
  } catch (e) {}
  return { docStatus, title, text, url: page.url() };
}

const limited = (r) => r.docStatus === 429 || r.url.includes('/accounts/login');

/** Stufe 1: kann nur "vergeben" beweisen. */
async function checkEmbed(page, user) {
  const r = await load(page, `https://www.instagram.com/${user}/embed/`, 'networkidle');
  if (limited(r)) return { state: 'ratelimited' };
  if (NO_PUBLIC_PROFILE.test(r.text)) return { state: 'inconclusive' };
  if (/followers|Beitr|posts/i.test(r.text)) return { state: 'taken', via: 'embed', text: r.text.slice(0, 120) };
  return { state: 'inconclusive' };
}

/** Stufe 2: verbindlich. */
async function checkProfile(page, user) {
  const r = await load(page, `https://www.instagram.com/${user}/`, 'networkidle');
  if (limited(r)) return { state: 'ratelimited' };
  if (/\(@/.test(r.title)) return { state: 'taken', via: 'profile', title: r.title };
  if (/Page Not Found|Seite nicht gefunden/i.test(r.title) || /isn't available|nicht verf/i.test(r.text)) {
    return { state: 'free', via: 'profile' };
  }
  return { state: 'unknown', via: 'profile', title: r.title, text: r.text.slice(0, 200) };
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

  let backoff = 45000;
  const withBackoff = async (fn, user) => {
    for (let attempt = 0; attempt < 4; attempt++) {
      const res = await fn(page, user);
      if (res.state !== 'ratelimited') {
        backoff = Math.max(45000, backoff / 2);
        return res;
      }
      console.log(`${user.padEnd(10)} rate-limit -> warte ${Math.round(backoff / 1000)}s`);
      await sleep(backoff);
      backoff = Math.min(backoff * 2, 600000);
      await page.context().clearCookies();
    }
    return { state: 'ratelimited' };
  };

  for (const user of candidates) {
    const done = results[user];
    if (done && done.state !== 'ratelimited' && done.state !== 'unknown') continue;

    let res = await withBackoff(checkEmbed, user);
    if (res.state !== 'taken') {
      await sleep(BASE_DELAY);
      res = await withBackoff(checkProfile, user);
    }
    results[user] = res;
    console.log(`${user.padEnd(10)} ${res.state.padEnd(7)} ${(res.title || res.text || '').slice(0, 55)}`);

    fs.writeFileSync(OUT, JSON.stringify(results, null, 1));
    await sleep(BASE_DELAY + Math.random() * 4000);
  }

  await browser.close();

  const free = Object.entries(results)
    .filter(([, v]) => v.state === 'free')
    .map(([k]) => k)
    .sort((a, b) => a.length - b.length || a.localeCompare(b));
  console.log('\nOhne Profil (Kandidaten):', free.join(', ') || '(keine)');
})();
