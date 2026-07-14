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

function json(body) {
  return Response.json(body);
}
