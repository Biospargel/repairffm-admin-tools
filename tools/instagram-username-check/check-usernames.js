#!/usr/bin/env node
/**
 * Instagram-Handle-Check via Chromium (Playwright).
 *
 * Geprueft wird die Embed-Ansicht `https://www.instagram.com/<handle>/embed/`.
 * Sie ist fuer die Einbindung auf fremden Seiten gedacht und deshalb – anders
 * als die normale Profilseite – ohne Login abrufbar. Die Seite rendert ihren
 * Inhalt per JavaScript, ein reiner HTTP-Abruf reicht also nicht:
 *
 *   "<handle> <Name> 1,2M followers ..."                   -> vergeben
 *   "The link to this profile may be broken, or the        -> kein Profil
 *    profile may have been removed."                          vorhanden
 *   HTTP 429 / Weiterleitung auf /accounts/login/          -> Rate-Limit
 *
 * Bei Rate-Limit wird mit exponentiellem Backoff erneut versucht. Ergebnisse
 * landen in results.json und werden nach jedem Kandidaten geschrieben, ein
 * erneuter Lauf setzt also dort fort, wo der vorige aufgehoert hat.
 *
 * Aufruf:
 *   node check-usernames.js [kandidaten-datei]
 *
 * Umgebungsvariablen:
 *   BASE_DELAY   Pause zwischen zwei Kandidaten in ms (Standard 8000)
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
const BASE_DELAY = Number(process.env.BASE_DELAY || 8000);
const LIST = process.argv[2] || path.join(__dirname, 'candidates.txt');
const OUT = path.join(__dirname, 'results.json');

const candidates = fs
  .readFileSync(LIST, 'utf8')
  .split('\n')
  .map((s) => s.trim())
  .filter((s) => s && !s.startsWith('#'));

const results = fs.existsSync(OUT) ? JSON.parse(fs.readFileSync(OUT, 'utf8')) : {};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const GONE = /link to this profile may be broken|profile may have been removed/i;

async function classify(page, user) {
  let docStatus = null;
  const onResp = (r) => {
    if (r.request().resourceType() === 'document') docStatus = r.status();
  };
  page.on('response', onResp);
  try {
    await page.goto('https://www.instagram.com/' + user + '/embed/', {
      waitUntil: 'networkidle',
      timeout: 45000,
    });
  } catch (e) {
    // Chromium wirft bei 4xx ohne Body ERR_HTTP_RESPONSE_CODE_FAILURE;
    // die Einordnung passiert unten ueber docStatus und Seiteninhalt.
  } finally {
    page.off('response', onResp);
  }

  let text = '';
  try {
    text = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').trim();
  } catch (e) {}

  if (docStatus === 429 || page.url().includes('/accounts/login')) {
    return { state: 'ratelimited', docStatus, text: text.slice(0, 120) };
  }
  if (GONE.test(text)) return { state: 'free', docStatus };
  if (new RegExp('^' + user.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i').test(text)) {
    return { state: 'taken', docStatus, text: text.slice(0, 120) };
  }
  if (/followers|Posts|Beitr/i.test(text)) return { state: 'taken', docStatus, text: text.slice(0, 120) };
  return { state: 'unknown', docStatus, text: text.slice(0, 200) };
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
        console.log(`${user.padEnd(10)} ${res.state.padEnd(7)} ${(res.text || '').slice(0, 60)}`);
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
    await sleep(BASE_DELAY + Math.random() * 4000);
  }

  await browser.close();

  const free = Object.entries(results)
    .filter(([, v]) => v.state === 'free')
    .map(([k]) => k)
    .sort((a, b) => a.length - b.length || a.localeCompare(b));
  console.log('\nOhne Profil (Kandidaten):', free.join(', ') || '(keine)');
})();
