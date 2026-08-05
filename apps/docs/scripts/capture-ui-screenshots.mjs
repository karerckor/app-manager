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
import { mkdir, readdir, readFile, unlink } from 'node:fs/promises';
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

// Build detail requires the UUID of the completed build, which is auto-generated
// by the seed script. Read it from the file the seed writes.
let buildDetailPath = '/apps/customer-app/builds';
try {
  const buildIds = JSON.parse(await readFile(join(DOCS_ROOT, 'src', 'data', 'build-ids.json'), 'utf8'));
  // The first build UUID in the seed is the completed manual build.
  const completedBuildId = Object.values(buildIds)[0];
  if (completedBuildId) {
    buildDetailPath = `/apps/customer-app/builds/${completedBuildId}`;
  }
} catch {
  // If the file is missing, build-detail will be skipped at capture time.
}

const SCREENS = [
  { slug: 'applications', path: '/apps', wait: 'Applications' },
  { slug: 'new-application-modal', path: '/apps', wait: 'Applications', click: 'New application' },
  { slug: 'application-overview', path: '/apps/customer-app', wait: 'Customer App' },
  { slug: 'identifiers', path: '/apps/customer-app/identifiers', wait: 'com.acme.customer' },
  { slug: 'add-identifier-modal', path: '/apps/customer-app/identifiers', wait: 'com.acme.customer', click: 'Add' },
  { slug: 'identifier-detail', path: '/apps/customer-app/identifiers', wait: 'com.acme.customer', click: 'com.acme.customer' },
  { slug: 'add-credential-existing', path: '/apps/customer-app/identifiers', wait: 'com.acme.customer', clickSequence: ['com.acme.customer', 'Add credential'] },
  { slug: 'add-credential-new', path: '/apps/customer-app/identifiers', wait: 'com.acme.customer', clickSequence: ['com.acme.customer', 'Add credential', 'Create new'] },
  { slug: 'files-and-assets', path: '/apps/customer-app/media', wait: 'release-checklist.md' },
  { slug: 'files-metadata', path: '/apps/customer-app/media', wait: 'release-checklist.md', click: 'Metadata' },
  { slug: 'app-assets', path: '/apps/customer-app/media', wait: 'release-checklist.md', click: 'App assets' },
  { slug: 'upload-file-modal', path: '/apps/customer-app/media', wait: 'release-checklist.md', click: 'Upload file' },
  { slug: 'builds', path: '/apps/customer-app/builds', wait: 'production' },
  { slug: 'build-detail', path: buildDetailPath, wait: 'All builds' },
  { slug: 'credentials-vault', path: '/credentials', wait: 'Team credentials' },
  { slug: 'credentials-vault-external', path: '/credentials?storage=external', wait: 'Team credentials' },
  { slug: 'credential-detail-expanded', path: '/credentials', wait: 'Team credentials', click: 'Customer App Keystore' },
  { slug: 'api-keys', path: '/api-keys', wait: 'CI build reporter' },
  { slug: 'audit-log', path: '/audit', wait: 'Audit' },
  { slug: 'team-access', path: '/team', wait: 'Maria Lopez' },
  { slug: 'integrations-cicd', path: '/integrations', wait: 'acme/customer-app' },
  { slug: 'integrations-eas', path: '/integrations/eas', wait: 'Expo' },
  { slug: 'integrations-eas-linked', path: '/integrations/eas', wait: 'Customer App' },
  { slug: 'expo-app-setup', path: '/apps/delivery-driver/expo', wait: 'Set up on Expo' },
  { slug: 'expo-app-linked', path: '/apps/customer-app/expo', wait: 'LINKED' },
  { slug: 'integrations-webhooks', path: '/integrations/webhooks', wait: 'hooks.acme.example' },
  { slug: 'integrations-webhooks-new', path: '/integrations/webhooks', wait: 'hooks.acme.example', click: 'New endpoint' },
  { slug: 'integrations-webhooks-delivery', path: '/integrations/webhooks', wait: 'hooks.acme.example', click: 'exhausted' },
  { slug: 'integrations-notifications', path: '/integrations/notifications', wait: 'mobile-releases' },
  { slug: 'notifications-destination-form', path: '/integrations/notifications', wait: 'Destinations' },
  { slug: 'notifications-template-editor', path: '/integrations/notifications', wait: 'Destinations', clickSequence: ['Build completed'] },
  { slug: 'integrations-sentry', path: '/integrations/sentry', wait: 'Customer telemetry relay' },
  { slug: 'telemetry-errors-traces', path: '/apps/customer-app/telemetry', wait: 'Payment authorization failed' },
  { slug: 'telemetry-issue-detail', path: '/apps/customer-app/telemetry', wait: 'Payment authorization failed', click: 'Payment authorization failed' },
  { slug: 'push-notifications', path: '/tools/push-notifications', wait: 'Push' },
];


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
const overlays = [];

/**
 * Remove Vite's dev error overlay if present, returning its first line so the run
 * can report what was hidden.
 */
async function dismissViteOverlay(page) {
  return page.evaluate(() => {
    const overlay = document.querySelector('vite-error-overlay');
    if (!overlay) {
      return null;
    }
    const message = overlay.shadowRoot?.querySelector('.message')?.textContent?.trim() ?? 'unknown Vite error';
    overlay.remove();
    return message.split('\n')[0];
  });
}

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

      // Vite injects a full-screen error overlay on a dev build failure. It covers
      // the page and prints local filesystem paths, so it must never end up in a
      // screenshot — but it is a dev-server artifact rather than a product screen,
      // so it is removed and reported instead of failing an unrelated capture.
      const overlayError = await dismissViteOverlay(page);
      if (overlayError) {
        overlays.push({ slug: screen.slug, message: overlayError });
      }

      await page.getByText(screen.wait, { exact: false }).first().waitFor({ timeout: 10000 });
      // Inertia pages animate in; settle before shooting so nothing is mid-fade.
      await page.waitForTimeout(400);

      // Some screens require clicking a button to open a modal or navigate
      // deeper before the screenshot is taken.
      if (screen.clickSequence) {
        for (const text of screen.clickSequence) {
          await page.getByText(text, { exact: false }).first().click();
          await page.waitForTimeout(300);
        }
      } else if (screen.click) {
        await page.getByText(screen.click, { exact: false }).first().click();
        await page.waitForTimeout(300);
      }

      if (screen.scrollY) {
        await page.evaluate((y) => window.scrollTo({ top: y, behavior: 'instant' }), screen.scrollY);
        await page.waitForTimeout(200);
      }

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

if (overlays.length > 0) {
  console.warn(`\nA Vite dev error overlay was covering ${overlays.length} screen(s) and was removed:`);
  for (const overlay of overlays) {
    console.warn(`  ${overlay.slug}: ${overlay.message}`);
  }
  console.warn('Fix the dev build for screens that actually depend on the broken module.');
}

if (failures.length > 0) {
  console.error(`${failures.length} screen(s) failed to capture`);
  process.exit(1);
}
