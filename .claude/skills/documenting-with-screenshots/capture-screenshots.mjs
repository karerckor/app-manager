#!/usr/bin/env node

/*
 * Screenshot capture template for documentation.
 *
 * Copy into the docs app (so `playwright` resolves from its node_modules), edit the
 * CONFIG and SCREENS blocks, and commit it. The point of committing it is that the
 * next person regenerates images instead of redrawing them, and a UI change shows up
 * as an image diff rather than as documentation that quietly stopped being true.
 *
 *   npm i -D playwright && npx playwright install chromium
 *
 *   APP_URL=http://localhost:8000 \
 *   DOCS_USER_EMAIL=docs-capture@example.com \
 *   DOCS_USER_PASSWORD='...' \
 *   DOCS_TENANT=acme-mobile \
 *   node capture-screenshots.mjs [slug ...]
 *
 * Passing slugs captures only those screens.
 */

import { chromium } from 'playwright';
import { mkdir, readdir, unlink } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// --- config ---------------------------------------------------------------

const HERE = dirname(fileURLToPath(import.meta.url));

const CONFIG = {
  baseUrl: (process.env.APP_URL ?? 'http://localhost:8000').replace(/\/$/, ''),
  email: process.env.DOCS_USER_EMAIL,
  password: process.env.DOCS_USER_PASSWORD,

  // The fixture tenant these screenshots must come from. Capturing anything else is
  // a hard failure: a list screen publishes every row in it, and an image cannot be
  // redacted after it ships.
  tenant: process.env.DOCS_TENANT,

  // Where captured images land, relative to this file.
  outDir: join(HERE, 'public', 'screenshots'),

  // A fixed viewport keeps images comparable between runs and machines.
  viewport: { width: 1440, height: 900 },
  deviceScaleFactor: 2,
  colorScheme: 'light',
};

/**
 * Screens to capture.
 *
 * `wait` is text that must be visible before the shot is taken. Choose body or
 * heading text that proves the data rendered — not a control's label, which may come
 * from an aria-label that text matching cannot see.
 */
const SCREENS = [
  { slug: 'dashboard', path: '/', wait: 'Applications' },
  // { slug: 'settings', path: '/settings', wait: 'Workspace name' },
];

// --- guards ---------------------------------------------------------------

for (const [key, value] of Object.entries({
  DOCS_USER_EMAIL: CONFIG.email,
  DOCS_USER_PASSWORD: CONFIG.password,
  DOCS_TENANT: CONFIG.tenant,
})) {
  if (!value) {
    console.error(`${key} is required`);
    process.exit(1);
  }
}

const only = process.argv.slice(2);
const selected = only.length ? SCREENS.filter((s) => only.includes(s.slug)) : SCREENS;

if (!selected.length) {
  console.error(`No screens matched: ${only.join(', ')}`);
  process.exit(1);
}

/**
 * Remove a dev-server error overlay if present and return its first line.
 *
 * Overlays cover the page and print local filesystem paths, so one must never reach a
 * screenshot. It is a dev-server artifact rather than a product screen, so it is
 * removed and reported instead of failing an unrelated capture. Extend the selector
 * list for your stack.
 */
async function dismissDevOverlay(page) {
  return page.evaluate(() => {
    const overlay = document.querySelector('vite-error-overlay, #webpack-dev-server-client-overlay, [data-nextjs-dialog-overlay]');
    if (!overlay) return null;
    const text = (overlay.shadowRoot ?? overlay).textContent?.trim() ?? 'unknown dev error';
    overlay.remove();
    return text.split('\n')[0].slice(0, 200);
  });
}

// --- run ------------------------------------------------------------------

await mkdir(CONFIG.outDir, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: CONFIG.viewport,
  deviceScaleFactor: CONFIG.deviceScaleFactor,
  colorScheme: CONFIG.colorScheme,
});
const page = await context.newPage();

const failures = [];
const overlays = [];

try {
  await page.goto(`${CONFIG.baseUrl}/login`, { waitUntil: 'networkidle' });
  await page.getByRole('textbox', { name: /email/i }).fill(CONFIG.email);
  await page.getByRole('textbox', { name: /password/i }).fill(CONFIG.password);
  await page.getByRole('button', { name: /sign in|log in/i }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15000 });

  // Refuse to capture another tenant's data. This is the guard that matters most:
  // everything else in this script is about image quality, this one is about not
  // publishing someone else's information.
  if (!page.url().includes(CONFIG.tenant)) {
    throw new Error(
      `signed in to ${page.url()} which does not contain the expected tenant ` +
        `"${CONFIG.tenant}". Refusing to capture: screenshots of another tenant would leak its data.`
    );
  }
  console.log(`signed in as ${CONFIG.email}, tenant ${CONFIG.tenant}`);

  for (const screen of selected) {
    const url = `${CONFIG.baseUrl}${screen.path}`;

    try {
      await page.goto(url, { waitUntil: 'networkidle' });

      const overlay = await dismissDevOverlay(page);
      if (overlay) overlays.push({ slug: screen.slug, overlay });

      // Separate from goto() on purpose: a page can answer 200 and render nothing.
      await page.getByText(screen.wait, { exact: false }).first().waitFor({ timeout: 10000 });
      await page.waitForTimeout(400); // let entry animations settle

      await page.screenshot({ path: join(CONFIG.outDir, `${screen.slug}.png`), type: 'png' });
      console.log(`  ok   ${screen.slug.padEnd(28)} ${screen.path}`);
    } catch (error) {
      failures.push({ slug: screen.slug, message: error.message.split('\n')[0] });
      console.error(`  FAIL ${screen.slug.padEnd(28)} ${screen.path}`);
      console.error(`       ${error.message.split('\n')[0]}`);
    }
  }
} finally {
  await browser.close();
}

// A screen that could not be captured must not keep its old image: documentation that
// shows a screen which no longer exists is worse than documentation with a gap.
for (const failure of failures) {
  await unlink(join(CONFIG.outDir, `${failure.slug}.png`)).catch(() => {});
}

const written = (await readdir(CONFIG.outDir)).filter((f) => f.endsWith('.png'));
console.log(`\n${written.length} screenshots in ${CONFIG.outDir}`);

if (overlays.length) {
  console.warn(`\nA dev error overlay covered ${overlays.length} screen(s) and was removed:`);
  for (const { slug, overlay } of overlays) console.warn(`  ${slug}: ${overlay}`);
  console.warn('Fix the dev build for screens that actually depend on the broken module.');
}

if (failures.length) {
  console.error(`\n${failures.length} screen(s) failed to capture`);
  process.exit(1);
}
