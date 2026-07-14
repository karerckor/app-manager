import assert from 'node:assert/strict';
import { stat } from 'node:fs/promises';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { prepare } from '../scripts/prepare.mjs';

test('prepare builds Expo config and Android/iOS credentials from App Manager', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-'));
  const requests = [];
  const keystore = Buffer.from('demo-pkcs12-keystore');
  const certificate = Buffer.from('demo-ios-pkcs12-certificate');
  const provisioningProfile = Buffer.from('demo-mobileprovision');
  const fetchImpl = async (url, options) => {
    const requestUrl = new URL(url);
    requests.push({ authorization: options.headers.Authorization, url: requestUrl.pathname });

    if (requestUrl.pathname === '/api/v1/demo-users-team/apps/example-app') {
      return json({
        data: {
          description: 'Demo application for local development.',
          name: 'Example App',
          slug: 'example-app',
        },
      });
    }

    if (requestUrl.pathname === '/api/v1/demo-users-team/apps/example-app/identifiers') {
      return json({
        data: [
          {
            id: 'identifier-1',
            identifier: 'com.example.app',
            platform: 'android',
            version: '2.3.0',
            version_code: 20300,
          },
          {
            id: 'identifier-2',
            identifier: 'com.example.app',
            platform: 'ios',
            version: '2.3.0',
            version_code: null,
          },
        ],
      });
    }

    if (requestUrl.pathname === '/api/v1/demo-users-team/apps/example-app/credentials') {
      return json({
        data: [
          {
            file: { name: 'production.keystore' },
            id: 'credential-1',
            name: 'Production Keystore',
            payload: {
              key_alias: 'production',
              key_password: 'key-secret',
              store_password: 'store-secret',
            },
            profiles: [
              {
                app_identifier: { identifier: 'com.example.app' },
                type: 'production',
              },
            ],
            type: 'android_keystore',
          },
          {
            file: { name: 'distribution.p12' },
            id: 'credential-2',
            name: 'App Store Certificate',
            payload: { password: 'certificate-secret' },
            profiles: [
              {
                app_identifier: { identifier: 'com.example.app' },
                type: 'appstore',
              },
            ],
            type: 'ios_certificate',
          },
          {
            file: { name: 'appstore.mobileprovision' },
            id: 'credential-3',
            name: 'App Store Provisioning Profile',
            payload: null,
            profiles: [
              {
                app_identifier: { identifier: 'com.example.app' },
                type: 'appstore',
              },
            ],
            type: 'provisioning_profile',
          },
        ],
      });
    }

    if (
      requestUrl.pathname ===
      '/api/v1/demo-users-team/apps/example-app/credentials/credential-1/file'
    ) {
      return new Response(keystore, {
        headers: { 'Content-Type': 'application/octet-stream' },
        status: 200,
      });
    }

    if (
      requestUrl.pathname ===
      '/api/v1/demo-users-team/apps/example-app/credentials/credential-2/file'
    ) {
      return new Response(certificate, {
        headers: { 'Content-Type': 'application/x-pkcs12' },
        status: 200,
      });
    }

    if (
      requestUrl.pathname ===
      '/api/v1/demo-users-team/apps/example-app/credentials/credential-3/file'
    ) {
      return new Response(provisioningProfile, {
        headers: { 'Content-Type': 'application/octet-stream' },
        status: 200,
      });
    }

    return new Response(null, { status: 404, statusText: 'Not Found' });
  };

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: {
      APP_MANAGER_BASE_URL: 'http://platform.test:8000',
    },
    fetchImpl,
    logger: { log() {} },
  });

  const appJson = JSON.parse(await readFile(join(workspace, 'app.json'), 'utf8'));
  const credentialsJson = JSON.parse(
    await readFile(join(workspace, 'credentials.json'), 'utf8'),
  );
  const downloadedKeystore = await readFile(
    join(workspace, 'credentials/android/production.keystore'),
  );
  const downloadedCertificate = await readFile(
    join(workspace, 'credentials/ios/distribution.p12'),
  );
  const downloadedProvisioningProfile = await readFile(
    join(workspace, 'credentials/ios/profile.mobileprovision'),
  );

  assert.deepEqual(appJson, {
    expo: {
      android: { package: 'com.example.app', versionCode: 20300 },
      description: 'Demo application for local development.',
      name: 'Example App',
      orientation: 'portrait',
      ios: { bundleIdentifier: 'com.example.app' },
      slug: 'example-app',
      userInterfaceStyle: 'automatic',
      version: '2.3.0',
    },
  });
  assert.deepEqual(credentialsJson, {
    android: {
      keystore: {
        keyAlias: 'production',
        keyPassword: 'key-secret',
        keystorePassword: 'store-secret',
        keystorePath: 'credentials/android/production.keystore',
      },
    },
    ios: {
      distributionCertificate: {
        password: 'certificate-secret',
        path: 'credentials/ios/distribution.p12',
      },
      provisioningProfilePath: 'credentials/ios/profile.mobileprovision',
    },
  });
  assert.deepEqual(downloadedKeystore, keystore);
  assert.deepEqual(downloadedCertificate, certificate);
  assert.deepEqual(downloadedProvisioningProfile, provisioningProfile);
  assert.equal((await stat(join(workspace, 'credentials.json'))).mode & 0o777, 0o600);
  assert.equal(
    (await stat(join(workspace, 'credentials/android/production.keystore'))).mode & 0o777,
    0o600,
  );
  assert.equal(
    (await stat(join(workspace, 'credentials/ios/distribution.p12'))).mode & 0o777,
    0o600,
  );
  assert.equal(
    (await stat(join(workspace, 'credentials/ios/profile.mobileprovision'))).mode &
      0o777,
    0o600,
  );
  assert.equal(requests.length, 6);
  assert.ok(requests.every((request) => request.authorization === 'Bearer demo-api-key'));
  assert.equal('owner' in appJson.expo, false);
  assert.equal('updates' in appJson.expo, false);
});

test('prepare downloads client Firebase files and wires app.json', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-fb-'));
  const googleServices = Buffer.from('{"project_info":{},"client":[]}');
  const googlePlist = Buffer.from('<plist></plist>');
  const fetchImpl = buildFetch({
    googleServices,
    googlePlist,
  });

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const appJson = JSON.parse(await readFile(join(workspace, 'app.json'), 'utf8'));
  assert.equal(appJson.expo.android.googleServicesFile, './google-services.json');
  assert.equal(appJson.expo.ios.googleServicesFile, './GoogleService-Info.plist');
  assert.deepEqual(await readFile(join(workspace, 'google-services.json')), googleServices);
  assert.deepEqual(await readFile(join(workspace, 'GoogleService-Info.plist')), googlePlist);
  assert.equal(
    (await stat(join(workspace, 'google-services.json'))).mode & 0o777,
    0o600,
  );
});

test('prepare downloads icon and splash and wires app.json', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-assets-'));
  const icon = Buffer.from('icon-bytes');
  const splash = Buffer.from('splash-bytes');
  const fetchImpl = buildFetch({
    assets: {
      icon: { mime_type: 'image/png', url: 'http://platform.test:8000/assets/icon' },
      splashscreen: { mime_type: 'image/png', url: 'http://platform.test:8000/assets/splash' },
    },
    assetBody: { icon, splash },
  });

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const appJson = JSON.parse(await readFile(join(workspace, 'app.json'), 'utf8'));
  assert.equal(appJson.expo.icon, './assets/icon.png');
  assert.equal(appJson.expo.splash.image, './assets/splash.png');
  assert.equal(appJson.expo.splash.resizeMode, 'contain');
  assert.deepEqual(await readFile(join(workspace, 'assets/icon.png')), icon);
  assert.deepEqual(await readFile(join(workspace, 'assets/splash.png')), splash);
});

test('prepare downloads store keys and writes fastlane/context.json', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-store-'));
  const googlePlay = Buffer.from('{"type":"service_account"}');
  const appleKey = Buffer.from('-----BEGIN PRIVATE KEY-----demo-----END PRIVATE KEY-----');
  const fetchImpl = buildFetch({ googlePlay, appleKey });

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const context = JSON.parse(await readFile(join(workspace, 'fastlane/context.json'), 'utf8'));
  assert.equal(context.version, '2.3.0');
  assert.equal(context.android.package, 'com.example.app');
  assert.equal(context.android.versionCode, 20300);
  assert.equal(context.android.profileType, 'production');
  assert.equal(context.android.googlePlayJsonKeyPath, 'credentials/android/google-play-service-account.json');
  assert.equal(context.ios.bundleIdentifier, 'com.example.app');
  assert.equal(context.ios.profileType, 'appstore');
  assert.deepEqual(context.ios.appStoreApiKey, {
    path: 'credentials/ios/appstore-api-key.p8',
    keyId: 'ABCDE12345',
    issuerId: '11111111-1111-4111-8111-111111111111',
  });
  assert.deepEqual(await readFile(join(workspace, 'credentials/android/google-play-service-account.json')), googlePlay);
  assert.deepEqual(await readFile(join(workspace, 'credentials/ios/appstore-api-key.p8')), appleKey);
  assert.equal((await stat(join(workspace, 'fastlane/context.json'))).mode & 0o777, 0o600);
});

test('prepare writes context.json with null store keys when absent', async (t) => {
  const workspace = await mkdtemp(join(tmpdir(), 'expo-hello-world-nostore-'));
  const fetchImpl = buildFetch({});

  t.after(async () => {
    await rm(workspace, { force: true, recursive: true });
  });

  await prepare({
    cwd: workspace,
    env: { APP_MANAGER_BASE_URL: 'http://platform.test:8000' },
    fetchImpl,
    logger: { log() {} },
  });

  const context = JSON.parse(await readFile(join(workspace, 'fastlane/context.json'), 'utf8'));
  assert.equal(context.android.googlePlayJsonKeyPath, null);
  assert.equal(context.ios.appStoreApiKey, null);
});

function json(body) {
  return Response.json(body);
}

function buildFetch(extras = {}) {
  const keystore = Buffer.from('demo-pkcs12-keystore');
  const certificate = Buffer.from('demo-ios-pkcs12-certificate');
  const provisioningProfile = Buffer.from('demo-mobileprovision');
  const base = '/api/v1/demo-users-team/apps/example-app';
  const identifierCredential = (id, type) => ({
    app_identifiers: [{ identifier: 'com.example.app' }],
    file: { name: `${id}.bin` },
    id,
    name: id,
    payload: extras[`${id}Payload`] ?? null,
    type,
  });

  return async (url) => {
    const { pathname } = new URL(url);

    if (pathname === base) {
      return json({
        data: {
          assets: extras.assets ?? { icon: null, splashscreen: null },
          description: 'Demo application for local development.',
          name: 'Example App',
          slug: 'example-app',
        },
      });
    }

    if (pathname === `${base}/identifiers`) {
      return json({
        data: [
          { id: 'identifier-1', identifier: 'com.example.app', platform: 'android', version: '2.3.0', version_code: 20300 },
          { id: 'identifier-2', identifier: 'com.example.app', platform: 'ios', version: '2.3.0', version_code: null },
        ],
      });
    }

    if (pathname === `${base}/credentials`) {
      const data = [
        { file: { name: 'production.keystore' }, id: 'credential-1', name: 'Production Keystore', payload: { key_alias: 'production', key_password: 'key-secret', store_password: 'store-secret' }, profiles: [{ app_identifier: { identifier: 'com.example.app' }, type: 'production' }], type: 'android_keystore' },
        { file: { name: 'distribution.p12' }, id: 'credential-2', name: 'App Store Certificate', payload: { password: 'certificate-secret' }, profiles: [{ app_identifier: { identifier: 'com.example.app' }, type: 'appstore' }], type: 'ios_certificate' },
        { file: { name: 'appstore.mobileprovision' }, id: 'credential-3', name: 'App Store Provisioning Profile', payload: null, profiles: [{ app_identifier: { identifier: 'com.example.app' }, type: 'appstore' }], type: 'provisioning_profile' },
      ];
      if (extras.googleServices) data.push(identifierCredential('google-services-json', 'google_services_json'));
      if (extras.googlePlist) data.push(identifierCredential('google-service-info-plist', 'google_service_info_plist'));
      if (extras.googlePlay) data.push(identifierCredential('google-play', 'google_play_service_account'));
      if (extras.appleKey) data.push({ ...identifierCredential('apple-api-key', 'apple_api_key'), payload: { key_id: 'ABCDE12345', issuer_id: '11111111-1111-4111-8111-111111111111' } });
      return json({ data });
    }

    const files = {
      'credential-1/file': keystore,
      'credential-2/file': certificate,
      'credential-3/file': provisioningProfile,
      'google-services-json/file': extras.googleServices,
      'google-service-info-plist/file': extras.googlePlist,
      'google-play/file': extras.googlePlay,
      'apple-api-key/file': extras.appleKey,
    };
    for (const [suffix, body] of Object.entries(files)) {
      if (body && pathname === `${base}/credentials/${suffix}`) {
        return new Response(body, { headers: { 'Content-Type': 'application/octet-stream' }, status: 200 });
      }
    }
    if (extras.assetBody && pathname === '/assets/icon') {
      return new Response(extras.assetBody.icon, { headers: { 'Content-Type': 'image/png' }, status: 200 });
    }
    if (extras.assetBody && pathname === '/assets/splash') {
      return new Response(extras.assetBody.splash, { headers: { 'Content-Type': 'image/png' }, status: 200 });
    }

    return new Response(null, { status: 404, statusText: 'Not Found' });
  };
}
