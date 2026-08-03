#!/usr/bin/env node

/*
 * Capture Web UI screenshots for the documentation.
 *
 * The docs show the real interface, so the images are recorded here rather than
 * pasted in by hand. Re-run this after a UI change and the diff shows exactly which
 * pages went stale.
 *
 * Every capture runs against the seeded documentation team, never a working one:
 * a screenshot of an app catalog publishes every app name in it, and an image
 * cannot be redacted after it ships. Seed it first with
 * scripts/docs/seed-capture-data.php.
 *
 * Usage:
 *   APP_MANAGER_URL=http://localhost:8000 \
 *   DOCS_USER_EMAIL=docs-capture@example.com \
 *   DOCS_USER_PASSWORD='...' \
 *   pnpm --filter apps-docs capture:screenshots -- [slug ...]
 *   # or: node apps/docs/scripts/capture-ui-screenshots.mjs [slug ...]
 *
 * Passing slugs captures only those screens.
 */

import { chromium } from 'playwright';
import { mkdir, readdir, unlink } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// This script lives under apps/docs rather than next to the CLI capture script
// because it imports playwright, and node resolves imports from the script's own
// directory. The CLI harness is dependency-free bash, so it stays in scripts/docs.
const DOCS_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT_DIR = join(DOCS_ROOT, 'public', 'screenshots');

const BASE_URL = (process.env.APP_MANAGER_URL ?? 'http://localhost:8000').replace(/\/$/, '');
const EMAIL = process.env.DOCS_USER_EMAIL ?? 'docs-capture@example.com';
const PASSWORD = process.env.DOCS_USER_PASSWORD;
const EXPECTED_TEAM = process.env.DOCS_TEAM_SLUG ?? 'acme-mobile';

// A fixed viewport keeps images comparable between runs and machines. Full-page
// captures would vary in height with the seeded row count, so screens are shot at
// viewport size unless a screen is explicitly marked tall.
const VIEWPORT = { width: 1440, height: 900 };

if (!PASSWORD) {
  console.error('DOCS_USER_PASSWORD is required');
  process.exit(1);
}

/**
 * Screens to capture. `path` is relative to the team, `wait` is text that must be
 * present before the shot is taken, so a screen that failed to load cannot be
 * recorded as a blank page.
 */
const SCREENS = [
  { slug: 'applications', path: '/apps', wait: 'Applications' },
  { slug: 'application-overview', path: '/apps/customer-app', wait: 'Customer App' },
  { slug: 'identifiers', path: '/apps/customer-app/identifiers', wait: 'com.acme.customer' },
  { slug: 'files-and-assets', path: '/apps/customer-app/media', wait: 'release-checklist.md' },
  { slug: 'builds', path: '/apps/customer-app/builds', wait: 'production' },
  { slug: 'credentials-vault', path: '/credentials', wait: 'Keystore' },
  { slug: 'api-keys', path: '/api-keys', wait: 'CI build reporter' },
  { slug: 'audit-log', path: '/audit', wait: 'Audit' },
  { slug: 'team-access', path: '/team', wait: 'Maria Lopez' },
  { slug: 'integrations-cicd', path: '/integrations', wait: 'acme/customer-app' },
  { slug: 'integrations-eas', path: '/integrations/eas', wait: 'Expo' },
  { slug: 'integrations-webhooks', path: '/integrations/webhooks', wait: 'hooks.acme.example' },
  { slug: 'integrations-notifications', path: '/integrations/notifications', wait: 'mobile-releases' },
  { slug: 'push-notifications', path: '/tools/push-notifications', wait: 'Push' },
];

// Not captured: /apps/{slug}/expo, the per-application Expo screen. It 404s unless
// the app has a linked Expo project, and rendering it calls Expo's API, so it needs
// real Expo credentials that the documentation data deliberately does not carry.


const only = process.argv.slice(2);
const selected = only.length > 0 ? SCREENS.filter((s) => only.includes(s.slug)) : SCREENS;

if (selected.length === 0) {
  console.error(`No screens matched: ${only.join(', ')}`);
  process.exit(1);
}

await mkdir(OUT_DIR, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: VIEWPORT,
  deviceScaleFactor: 2,
  colorScheme: 'light',
});
const page = await context.newPage();

const failures = [];

try {
  // --- sign in -------------------------------------------------------------
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
  await page.getByRole('textbox', { name: 'Email' }).fill(EMAIL);
  await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL(/\/accounts\//, { timeout: 15000 });

  const landedTeam = new URL(page.url()).pathname.split('/')[2];

  // Capturing the wrong team would publish another team's data, so this is a hard
  // stop rather than a warning.
  if (landedTeam !== EXPECTED_TEAM) {
    throw new Error(
      `signed in to team "${landedTeam}" but expected "${EXPECTED_TEAM}". ` +
        'Refusing to capture: screenshots of another team would leak its data.'
    );
  }
  console.log(`signed in as ${EMAIL}, team ${landedTeam}`);

  // --- capture -------------------------------------------------------------
  for (const screen of selected) {
    const url = `${BASE_URL}/accounts/${EXPECTED_TEAM}${screen.path}`;

    try {
      await page.goto(url, { waitUntil: 'networkidle' });
      await page.getByText(screen.wait, { exact: false }).first().waitFor({ timeout: 10000 });
      // Inertia pages animate in; settle before shooting so nothing is mid-fade.
      await page.waitForTimeout(400);

      const file = join(OUT_DIR, `${screen.slug}.png`);
      await page.screenshot({ path: file, type: 'png' });
      console.log(`  ok   ${screen.slug.padEnd(28)} ${screen.path}`);
    } catch (error) {
      failures.push({ slug: screen.slug, path: screen.path, message: error.message.split('\n')[0] });
      console.error(`  FAIL ${screen.slug.padEnd(28)} ${screen.path}`);
      console.error(`       ${error.message.split('\n')[0]}`);
    }
  }
} finally {
  await browser.close();
}

// A screen that could not be captured must not silently keep an old image, which
// would show as documentation quietly describing a screen that no longer exists.
for (const failure of failures) {
  await unlink(join(OUT_DIR, `${failure.slug}.png`)).catch(() => {});
}

const written = (await readdir(OUT_DIR)).filter((f) => f.endsWith('.png'));
console.log(`\n${written.length} screenshots in apps/docs/public/screenshots`);

if (failures.length > 0) {
  console.error(`${failures.length} screen(s) failed to capture`);
  process.exit(1);
}
