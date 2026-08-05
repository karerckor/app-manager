import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { prepare } from '../scripts/prepare.mjs';

const require = createRequire(import.meta.url);
const { mergeConfig } = require('../app.config.js');

test('prepares Android only and leaves tracked app.json unchanged', async (t) => {
  const workspace = await temporaryWorkspace(t);
  const trackedConfig = '{"expo":{"name":"Tracked fallback"}}\n';
  await writeFile(join(workspace, 'app.json'), trackedConfig);

  const result = await prepare({
    cwd: workspace,
    input: androidInput('example-app'),
    logger: silentLogger,
  });

  assert.deepEqual(result.expo, {
    expo: {
      android: {
        googleServicesFile: './.app-manager/downloads/example-app/google-services.json',
        package: 'com.example.app',
        versionCode: 1,
      },
      description: 'Example brand',
      icon: './.app-manager/downloads/example-app/icon.png',
      name: 'Example App',
      orientation: 'portrait',
      slug: 'example-app',
      splash: {
        backgroundColor: '#ffffff',
        image: './.app-manager/downloads/example-app/splash.png',
        resizeMode: 'contain',
      },
      userInterfaceStyle: 'automatic',
      version: '1.0.0',
    },
  });
  assert.equal('ios' in result.expo.expo, false);
  assert.deepEqual(result.credentials, {
    android: {
      keystore: {
        keyAlias: 'example-app-release',
        keyPassword: 'example-app-key-secret',
        keystorePassword: 'example-app-store-secret',
        keystorePath: '.app-manager/downloads/example-app/production.keystore',
      },
    },
  });
  assert.equal(result.buildContext.android.googlePlayJsonKeyPath, '.app-manager/downloads/example-app/google-play.json');
  assert.equal(result.buildContext.app, 'example-app');
  assert.equal(await readFile(join(workspace, 'app.json'), 'utf8'), trackedConfig);
  assert.equal((await stat(result.expoConfigPath)).mode & 0o777, 0o644);
  assert.equal((await stat(result.credentialsPath)).mode & 0o777, 0o600);
  assert.equal((await stat(result.contextPath)).mode & 0o777, 0o600);
});

test('same Android source produces isolated white-label configuration', async (t) => {
  const workspace = await temporaryWorkspace(t);

  const first = await prepare({ cwd: workspace, input: androidInput('example-app'), logger: silentLogger });
  const blue = await prepare({ cwd: workspace, input: androidInput('example-app-blue'), logger: silentLogger });

  assert.equal(first.expo.expo.name, 'Example App');
  assert.equal(blue.expo.expo.name, 'Blue Example App');
  assert.equal(first.expo.expo.android.package, 'com.example.app');
  assert.equal(blue.expo.expo.android.package, 'com.example.blue');
  assert.notEqual(first.expo.expo.icon, blue.expo.expo.icon);
  assert.notEqual(first.expo.expo.android.googleServicesFile, blue.expo.expo.android.googleServicesFile);
  assert.notEqual(
    first.credentials.android.keystore.keystorePassword,
    blue.credentials.android.keystore.keystorePassword,
  );
  assert.notEqual(
    first.buildContext.android.googlePlayJsonKeyPath,
    blue.buildContext.android.googlePlayJsonKeyPath,
  );
});

test('prepares iOS only with build number, Firebase and signing data', async (t) => {
  const workspace = await temporaryWorkspace(t);
  const result = await prepare({ cwd: workspace, input: iosInput('example-app-blue'), logger: silentLogger });

  assert.deepEqual(result.expo.expo.ios, {
    buildNumber: '20000',
    bundleIdentifier: 'com.example.blue',
    googleServicesFile: './.app-manager/downloads/example-app-blue/GoogleService-Info.plist',
  });
  assert.equal('android' in result.expo.expo, false);
  assert.deepEqual(result.credentials, {
    ios: {
      distributionCertificate: {
        password: 'example-app-blue-certificate-secret',
        path: '.app-manager/downloads/example-app-blue/distribution.p12',
      },
      provisioningProfilePath: '.app-manager/downloads/example-app-blue/profile.mobileprovision',
    },
  });
  assert.deepEqual(result.buildContext.ios.appStoreApiKey, {
    issuerId: 'example-app-blue-issuer',
    keyId: 'example-app-blue-key',
    path: '.app-manager/downloads/example-app-blue/appstore.p8',
  });
});

test('same iOS source produces isolated white-label configuration', async (t) => {
  const workspace = await temporaryWorkspace(t);

  const first = await prepare({ cwd: workspace, input: iosInput('example-app'), logger: silentLogger });
  const blue = await prepare({ cwd: workspace, input: iosInput('example-app-blue'), logger: silentLogger });

  assert.equal(first.expo.expo.name, 'Example App');
  assert.equal(blue.expo.expo.name, 'Blue Example App');
  assert.equal(first.expo.expo.ios.bundleIdentifier, 'com.example.app');
  assert.equal(blue.expo.expo.ios.bundleIdentifier, 'com.example.blue');
  assert.notEqual(first.expo.expo.ios.buildNumber, blue.expo.expo.ios.buildNumber);
  assert.notEqual(
    first.expo.expo.ios.googleServicesFile,
    blue.expo.expo.ios.googleServicesFile,
  );
  assert.notEqual(
    first.credentials.ios.distributionCertificate.password,
    blue.credentials.ios.distributionCertificate.password,
  );
  assert.notEqual(
    first.buildContext.ios.appStoreApiKey.path,
    blue.buildContext.ios.appStoreApiKey.path,
  );
});

test('fails before generation when required platform build data is absent', async (t) => {
  const workspace = await temporaryWorkspace(t);
  const input = androidInput('example-app');
  input.context.target.version_code = null;

  await assert.rejects(
    prepare({ cwd: workspace, input, logger: silentLogger }),
    /version_code must be a positive integer/,
  );
});

test('dynamic Expo config removes the unselected fallback platform', () => {
  const fallback = {
    android: { package: 'fallback.android' },
    ios: { bundleIdentifier: 'fallback.ios' },
    name: 'Fallback',
  };

  const android = mergeConfig(fallback, {
    android: { package: 'com.example.app', versionCode: 1 },
    name: 'Example App',
  });
  const ios = mergeConfig(fallback, {
    ios: { bundleIdentifier: 'com.example.app', buildNumber: '1' },
    name: 'Example App',
  });

  assert.equal(android.android.package, 'com.example.app');
  assert.equal('ios' in android, false);
  assert.equal(ios.ios.bundleIdentifier, 'com.example.app');
  assert.equal('android' in ios, false);
});

function androidInput(slug) {
  const blue = slug.endsWith('-blue');
  const identifier = blue ? 'com.example.blue' : 'com.example.app';

  return {
    platform: 'android',
    context: buildContext({
      slug,
      name: blue ? 'Blue Example App' : 'Example App',
      identifier,
      platform: 'android',
      profile: 'production',
      version: blue ? '2.0.0' : '1.0.0',
      versionCode: blue ? 20000 : 1,
    }),
    files: {
      icon: `.app-manager/downloads/${slug}/icon.png`,
      splashscreen: `.app-manager/downloads/${slug}/splash.png`,
      google_services: `.app-manager/downloads/${slug}/google-services.json`,
      google_play: `.app-manager/downloads/${slug}/google-play.json`,
      keystore: {
        path: `.app-manager/downloads/${slug}/production.keystore`,
        payload: {
          key_alias: `${slug}-release`,
          key_password: `${slug}-key-secret`,
          store_password: `${slug}-store-secret`,
        },
      },
    },
  };
}

function iosInput(slug) {
  const blue = slug.endsWith('-blue');

  return {
    platform: 'ios',
    context: buildContext({
      slug,
      name: blue ? 'Blue Example App' : 'Example App',
      identifier: blue ? 'com.example.blue' : 'com.example.app',
      platform: 'ios',
      profile: 'appstore',
      version: blue ? '2.0.0' : '1.0.0',
      buildNumber: blue ? '20000' : '1',
    }),
    files: {
      certificate: {
        path: `.app-manager/downloads/${slug}/distribution.p12`,
        payload: { password: `${slug}-certificate-secret` },
      },
      provisioning_profile: {
        path: `.app-manager/downloads/${slug}/profile.mobileprovision`,
      },
      google_service_info: `.app-manager/downloads/${slug}/GoogleService-Info.plist`,
      apple_api_key: {
        path: `.app-manager/downloads/${slug}/appstore.p8`,
        payload: { key_id: `${slug}-key`, issuer_id: `${slug}-issuer` },
      },
    },
  };
}

function buildContext({
  slug,
  name,
  identifier,
  platform,
  profile,
  version,
  versionCode = null,
  buildNumber = null,
}) {
  return {
    schema_version: 1,
    app: { slug, name, description: 'Example brand' },
    target: {
      platform,
      identifier,
      version,
      version_code: versionCode,
      build_number: buildNumber,
    },
    profile: { platform, value: profile },
  };
}

async function temporaryWorkspace(t) {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-app-manager-'));
  t.after(() => rm(workspace, { force: true, recursive: true }));
  return workspace;
}

const silentLogger = { log() {} };
