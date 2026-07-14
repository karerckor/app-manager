import { randomUUID } from 'node:crypto';
import { chmod, mkdir, rename, rm, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const DEFAULTS = Object.freeze({
  apiKey: 'demo-api-key',
  appSlug: 'example-app',
  androidIdentifier: 'com.example.app',
  baseUrl: 'http://localhost:8000',
  androidProfileType: 'production',
  iosIdentifier: 'com.example.app',
  iosProfileType: 'appstore',
  teamSlug: 'demo-users-team',
  timeoutMs: 10_000,
});

export async function prepare({
  cwd = process.cwd(),
  env = process.env,
  fetchImpl = globalThis.fetch,
  logger = console,
} = {}) {
  if (typeof fetchImpl !== 'function') {
    throw new Error('This script requires Node.js with the global fetch API.');
  }

  const config = readConfig(env);
  const appEndpoint = endpoint(config, 'apps', config.appSlug);
  const request = (url) => authorizedRequest(url, config, fetchImpl);

  const [appResponse, identifiersResponse, credentialsResponse] = await Promise.all([
    request(appEndpoint).then((response) => readJson(response, 'app')),
    request(`${appEndpoint}/identifiers`).then((response) => readJson(response, 'app identifiers')),
    request(`${appEndpoint}/credentials`).then((response) => readJson(response, 'credentials')),
  ]);

  const app = expectObject(appResponse.data, 'App Manager returned an invalid app response.');
  const identifiers = expectArray(
    identifiersResponse.data,
    'App Manager returned an invalid app identifiers response.',
  );
  const credentials = expectArray(
    credentialsResponse.data,
    'App Manager returned an invalid credentials response.',
  );
  const androidIdentifier = selectIdentifier(
    identifiers,
    'android',
    config.androidIdentifier,
  );
  const iosIdentifier = selectIdentifier(identifiers, 'ios', config.iosIdentifier);
  const androidCredential = selectProfileCredential(
    credentials,
    'android_keystore',
    androidIdentifier.identifier,
    config.androidProfileType,
    config.androidCredentialId,
    'Android keystore',
  );
  const iosCertificate = selectProfileCredential(
    credentials,
    'ios_certificate',
    iosIdentifier.identifier,
    config.iosProfileType,
    config.iosCertificateId,
    'iOS certificate',
  );
  const provisioningProfile = selectProfileCredential(
    credentials,
    'provisioning_profile',
    iosIdentifier.identifier,
    config.iosProfileType,
    config.iosProvisioningProfileId,
    'iOS provisioning profile',
  );
  const androidPayload = validateKeystorePayload(androidCredential.payload);
  const iosCertificatePayload = validateIosCertificatePayload(iosCertificate.payload);

  const [keystore, certificate, provisioningProfileContents] = await Promise.all([
    downloadCredential(request, appEndpoint, androidCredential, 'Android keystore'),
    downloadCredential(request, appEndpoint, iosCertificate, 'iOS certificate'),
    downloadCredential(
      request,
      appEndpoint,
      provisioningProfile,
      'iOS provisioning profile',
    ),
  ]);

  const keystoreRelativePath = 'credentials/android/production.keystore';
  const certificateRelativePath = 'credentials/ios/distribution.p12';
  const provisioningProfileRelativePath = 'credentials/ios/profile.mobileprovision';

  const androidFirebase = selectIdentifierCredential(
    credentials,
    'google_services_json',
    androidIdentifier.identifier,
    config.androidGoogleServicesId,
    'Android google-services.json',
  );
  const iosFirebase = selectIdentifierCredential(
    credentials,
    'google_service_info_plist',
    iosIdentifier.identifier,
    config.iosGoogleServicesId,
    'iOS GoogleService-Info.plist',
  );

  const firebaseWiring = {};
  if (androidFirebase) {
    const contents = await downloadCredential(request, appEndpoint, androidFirebase, 'Android google-services.json');
    await writeAtomically(join(cwd, 'google-services.json'), contents, 0o600);
    firebaseWiring.androidGoogleServicesFile = './google-services.json';
  }
  if (iosFirebase) {
    const contents = await downloadCredential(request, appEndpoint, iosFirebase, 'iOS GoogleService-Info.plist');
    await writeAtomically(join(cwd, 'GoogleService-Info.plist'), contents, 0o600);
    firebaseWiring.iosGoogleServicesFile = './GoogleService-Info.plist';
  }

  const assets = app.assets && typeof app.assets === 'object' ? app.assets : {};
  const assetWiring = {};
  if (assets.icon && typeof assets.icon.url === 'string') {
    const contents = await downloadAsset(request, assets.icon, 'App icon');
    const relativePath = `assets/icon.${assetExtension(assets.icon.mime_type)}`;
    await writeAtomically(join(cwd, ...relativePath.split('/')), contents, 0o644);
    assetWiring.icon = `./${relativePath}`;
  }
  if (assets.splashscreen && typeof assets.splashscreen.url === 'string') {
    const contents = await downloadAsset(request, assets.splashscreen, 'Splash screen');
    const relativePath = `assets/splash.${assetExtension(assets.splashscreen.mime_type)}`;
    await writeAtomically(join(cwd, ...relativePath.split('/')), contents, 0o644);
    assetWiring.splash = `./${relativePath}`;
  }

  const googlePlayKey = selectIdentifierCredential(
    credentials,
    'google_play_service_account',
    androidIdentifier.identifier,
    config.googlePlayCredentialId,
    'Google Play service account',
  );
  const appleApiKey = selectIdentifierCredential(
    credentials,
    'apple_api_key',
    iosIdentifier.identifier,
    config.appleApiKeyCredentialId,
    'App Store Connect API key',
  );

  let googlePlayKeyPath = null;
  if (googlePlayKey) {
    const contents = await downloadCredential(request, appEndpoint, googlePlayKey, 'Google Play service account');
    googlePlayKeyPath = 'credentials/android/google-play-service-account.json';
    await writeAtomically(join(cwd, ...googlePlayKeyPath.split('/')), contents, 0o600);
  }

  let appleApiKeyContext = null;
  if (appleApiKey) {
    const payload = expectObject(appleApiKey.payload, 'The App Store Connect API key payload is missing.');
    if (typeof payload.key_id !== 'string' || typeof payload.issuer_id !== 'string') {
      throw new Error('The App Store Connect API key payload is missing key_id or issuer_id.');
    }
    const contents = await downloadCredential(request, appEndpoint, appleApiKey, 'App Store Connect API key');
    const path = 'credentials/ios/appstore-api-key.p8';
    await writeAtomically(join(cwd, ...path.split('/')), contents, 0o600);
    appleApiKeyContext = { path, keyId: payload.key_id, issuerId: payload.issuer_id };
  }

  const appJson = buildAppJson(app, androidIdentifier, iosIdentifier, { ...firebaseWiring, ...assetWiring });
  const credentialsJson = {
    android: {
      keystore: {
        keystorePath: keystoreRelativePath,
        keystorePassword: androidPayload.store_password,
        keyAlias: androidPayload.key_alias,
        keyPassword: androidPayload.key_password,
      },
    },
    ios: {
      provisioningProfilePath: provisioningProfileRelativePath,
      distributionCertificate: {
        path: certificateRelativePath,
        password: iosCertificatePayload.password,
      },
    },
  };
  const appJsonPath = join(cwd, 'app.json');
  const credentialsJsonPath = join(cwd, 'credentials.json');
  const keystorePath = join(cwd, ...keystoreRelativePath.split('/'));
  const certificatePath = join(cwd, ...certificateRelativePath.split('/'));
  const provisioningProfilePath = join(
    cwd,
    ...provisioningProfileRelativePath.split('/'),
  );

  await writeAtomically(keystorePath, keystore, 0o600);
  await writeAtomically(certificatePath, certificate, 0o600);
  await writeAtomically(provisioningProfilePath, provisioningProfileContents, 0o600);
  await writeJsonAtomically(credentialsJsonPath, credentialsJson, 0o600);
  await writeJsonAtomically(appJsonPath, appJson, 0o644);

  const context = {
    version: appJson.expo.version,
    android: {
      package: androidIdentifier.identifier,
      versionCode: appJson.expo.android.versionCode,
      profileType: config.androidProfileType,
      googlePlayJsonKeyPath: googlePlayKeyPath,
    },
    ios: {
      bundleIdentifier: iosIdentifier.identifier,
      profileType: config.iosProfileType,
      appStoreApiKey: appleApiKeyContext,
    },
  };
  await writeJsonAtomically(join(cwd, 'fastlane', 'context.json'), context, 0o600);

  logger.log(`Prepared ${appJson.expo.name} from App Manager.`);
  logger.log(`  Android: ${androidIdentifier.identifier}`);
  logger.log(`  iOS: ${iosIdentifier.identifier}`);
  logger.log(`  app.json: ${appJsonPath}`);
  logger.log(`  credentials.json: ${credentialsJsonPath}`);
  logger.log(`  Android keystore: ${keystorePath}`);
  logger.log(`  iOS certificate: ${certificatePath}`);
  logger.log(`  iOS provisioning profile: ${provisioningProfilePath}`);

  return {
    appJson,
    appJsonPath,
    certificatePath,
    credentialsJson,
    credentialsJsonPath,
    keystorePath,
    provisioningProfilePath,
  };
}

function readConfig(env) {
  const baseUrl = valueOrDefault(env.APP_MANAGER_BASE_URL, DEFAULTS.baseUrl).replace(/\/+$/, '');
  const parsedBaseUrl = new URL(baseUrl);

  if (!['http:', 'https:'].includes(parsedBaseUrl.protocol)) {
    throw new Error('APP_MANAGER_BASE_URL must use http or https.');
  }

  const rawTimeout = valueOrDefault(
    env.APP_MANAGER_REQUEST_TIMEOUT_MS,
    String(DEFAULTS.timeoutMs),
  );
  const timeoutMs = Number(rawTimeout);

  if (!Number.isInteger(timeoutMs) || timeoutMs <= 0) {
    throw new Error('APP_MANAGER_REQUEST_TIMEOUT_MS must be a positive integer.');
  }

  return {
    apiKey: valueOrDefault(env.APP_MANAGER_API_KEY, DEFAULTS.apiKey),
    apiRoot: baseUrl.endsWith('/api/v1') ? baseUrl : `${baseUrl}/api/v1`,
    appSlug: valueOrDefault(env.APP_MANAGER_APP, DEFAULTS.appSlug),
    androidIdentifier: valueOrDefault(
      env.APP_MANAGER_ANDROID_IDENTIFIER,
      DEFAULTS.androidIdentifier,
    ),
    androidCredentialId:
      optionalValue(env.APP_MANAGER_ANDROID_CREDENTIAL_ID) ??
      optionalValue(env.APP_MANAGER_CREDENTIAL_ID),
    androidGoogleServicesId: optionalValue(env.APP_MANAGER_ANDROID_GOOGLE_SERVICES_ID),
    androidProfileType: valueOrDefault(
      env.APP_MANAGER_ANDROID_PROFILE_TYPE ?? env.APP_MANAGER_PROFILE_TYPE,
      DEFAULTS.androidProfileType,
    ),
    appleApiKeyCredentialId: optionalValue(env.APP_MANAGER_APPLE_API_KEY_CREDENTIAL_ID),
    googlePlayCredentialId: optionalValue(env.APP_MANAGER_GOOGLE_PLAY_CREDENTIAL_ID),
    iosCertificateId: optionalValue(env.APP_MANAGER_IOS_CERTIFICATE_ID),
    iosGoogleServicesId: optionalValue(env.APP_MANAGER_IOS_GOOGLE_SERVICES_ID),
    iosIdentifier: valueOrDefault(env.APP_MANAGER_IOS_IDENTIFIER, DEFAULTS.iosIdentifier),
    iosProfileType: valueOrDefault(
      env.APP_MANAGER_IOS_PROFILE_TYPE,
      DEFAULTS.iosProfileType,
    ),
    iosProvisioningProfileId: optionalValue(
      env.APP_MANAGER_IOS_PROVISIONING_PROFILE_ID,
    ),
    teamSlug: valueOrDefault(env.APP_MANAGER_TEAM, DEFAULTS.teamSlug),
    timeoutMs,
  };
}

function endpoint(config, ...segments) {
  const encodedSegments = [config.teamSlug, ...segments].map((segment) =>
    encodeURIComponent(String(segment)),
  );

  return `${config.apiRoot}/${encodedSegments.join('/')}`;
}

async function authorizedRequest(url, config, fetchImpl) {
  let response;

  try {
    response = await fetchImpl(url, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${config.apiKey}`,
        'User-Agent': 'expo-hello-world-prepare/1.0',
      },
      signal: AbortSignal.timeout(config.timeoutMs),
    });
  } catch (error) {
    throw new Error(`Could not connect to App Manager at ${config.apiRoot}: ${error.message}`);
  }

  if (response.ok) {
    return response;
  }

  let message = response.statusText || 'request failed';

  try {
    const body = await response.json();
    const details = Object.values(body.errors ?? {}).flat().filter(Boolean).join(', ');
    message = body.message || message;
    message = details ? `${message}: ${details}` : message;
  } catch {
    // The HTTP status below is still useful when the server returns a non-JSON error page.
  }

  throw new Error(`App Manager API request failed (HTTP ${response.status}): ${message}`);
}

async function readJson(response, resourceName) {
  try {
    return await response.json();
  } catch {
    throw new Error(`App Manager returned invalid JSON for ${resourceName}.`);
  }
}

function selectIdentifier(identifiers, platform, requestedIdentifier) {
  const platformIdentifiers = identifiers.filter(
    (identifier) => identifier && identifier.platform === platform,
  );
  const identifier = requestedIdentifier
    ? platformIdentifiers.find((candidate) => candidate.identifier === requestedIdentifier)
    : platformIdentifiers[0];

  if (!identifier || typeof identifier.identifier !== 'string' || !identifier.identifier.trim()) {
    const suffix = requestedIdentifier ? ` matching ${requestedIdentifier}` : '';
    throw new Error(
      `No ${platformLabel(platform)} app identifier${suffix} is configured in App Manager.`,
    );
  }

  return identifier;
}

function selectProfileCredential(
  credentials,
  type,
  appIdentifier,
  profileType,
  requestedCredentialId,
  label,
) {
  const matches = credentials.filter(
    (credential) =>
      credential &&
      credential.type === type &&
      (!requestedCredentialId || String(credential.id) === requestedCredentialId) &&
      Array.isArray(credential.profiles) &&
      credential.profiles.some(
        (profile) =>
          profile &&
          profile.type === profileType &&
          profile.app_identifier?.identifier === appIdentifier,
      ),
  );

  if (matches.length === 0) {
    const idFilter = requestedCredentialId ? `, credential_id=${requestedCredentialId}` : '';
    throw new Error(
      `No App Manager credential matches type=${type}, app_identifier=${appIdentifier}, profile_type=${profileType}${idFilter}.`,
    );
  }

  if (matches.length > 1) {
    const choices = matches.map((credential) => `${credential.name} (${credential.id})`).join(', ');
    throw new Error(
      `Multiple ${label} credentials match; select one with its APP_MANAGER_*_ID variable. Candidates: ${choices}`,
    );
  }

  return matches[0];
}

function selectIdentifierCredential(credentials, type, appIdentifier, requestedCredentialId, label) {
  const matches = credentials.filter(
    (credential) =>
      credential &&
      credential.type === type &&
      (!requestedCredentialId || String(credential.id) === requestedCredentialId) &&
      Array.isArray(credential.app_identifiers) &&
      credential.app_identifiers.some(
        (identifier) => identifier && identifier.identifier === appIdentifier,
      ),
  );

  if (matches.length === 0) {
    return null;
  }

  if (matches.length > 1) {
    const choices = matches.map((credential) => `${credential.name} (${credential.id})`).join(', ');
    throw new Error(
      `Multiple ${label} credentials match; select one with its APP_MANAGER_*_ID variable. Candidates: ${choices}`,
    );
  }

  return matches[0];
}

function validateKeystorePayload(payload) {
  const value = expectObject(payload, 'The Android keystore payload is missing.');

  for (const field of ['store_password', 'key_alias', 'key_password']) {
    if (typeof value[field] !== 'string' || !value[field]) {
      throw new Error(`The Android keystore payload is missing ${field}.`);
    }
  }

  return value;
}

function validateIosCertificatePayload(payload) {
  const value = expectObject(payload, 'The iOS certificate payload is missing.');

  if (typeof value.password !== 'string' || !value.password) {
    throw new Error('The iOS certificate payload is missing password.');
  }

  return value;
}

async function downloadCredential(request, appEndpoint, credential, label) {
  if (
    typeof credential.id !== 'string' ||
    !credential.id ||
    !credential.file ||
    typeof credential.file !== 'object'
  ) {
    throw new Error(`${label} does not have a downloadable credential file.`);
  }

  const response = await request(
    `${appEndpoint}/credentials/${encodeURIComponent(credential.id)}/file`,
  );
  const contents = Buffer.from(await response.arrayBuffer());

  if (contents.byteLength === 0) {
    throw new Error(`${label} ${credential.id} returned an empty file.`);
  }

  return contents;
}

function buildAppJson(app, androidIdentifier, iosIdentifier, extras = {}) {
  if (typeof app.name !== 'string' || !app.name.trim()) {
    throw new Error('App Manager returned an app without a name.');
  }

  if (typeof app.slug !== 'string' || !app.slug.trim()) {
    throw new Error('App Manager returned an app without a slug.');
  }

  const configuredVersions = [androidIdentifier.version, iosIdentifier.version]
    .filter((version) => typeof version === 'string' && version.trim())
    .map((version) => version.trim());
  const versions = [...new Set(configuredVersions)];

  if (versions.length > 1) {
    throw new Error(
      `Android and iOS versions must match because Expo uses one app version; got ${versions.join(' and ')}.`,
    );
  }

  const version = versions[0] ?? '1.0.0';
  const versionCode =
    Number.isInteger(androidIdentifier.version_code) && androidIdentifier.version_code > 0
      ? androidIdentifier.version_code
      : 1;
  const expo = {
    name: app.name,
    slug: app.slug,
    version,
    orientation: 'portrait',
    userInterfaceStyle: 'automatic',
    android: {
      package: androidIdentifier.identifier,
      versionCode,
    },
    ios: {
      bundleIdentifier: iosIdentifier.identifier,
    },
  };

  if (extras.androidGoogleServicesFile) {
    expo.android.googleServicesFile = extras.androidGoogleServicesFile;
  }
  if (extras.iosGoogleServicesFile) {
    expo.ios.googleServicesFile = extras.iosGoogleServicesFile;
  }

  if (extras.icon) {
    expo.icon = extras.icon;
  }
  if (extras.splash) {
    expo.splash = { image: extras.splash, resizeMode: 'contain', backgroundColor: '#ffffff' };
  }

  if (typeof app.description === 'string' && app.description.trim()) {
    expo.description = app.description;
  }

  return { expo };
}

const ASSET_EXTENSIONS = Object.freeze({
  'image/png': 'png',
  'image/jpeg': 'jpg',
  'image/webp': 'webp',
});

async function downloadAsset(request, asset, label) {
  const response = await request(asset.url);
  const contents = Buffer.from(await response.arrayBuffer());

  if (contents.byteLength === 0) {
    throw new Error(`${label} returned an empty file.`);
  }

  return contents;
}

function assetExtension(mimeType) {
  return ASSET_EXTENSIONS[mimeType] ?? 'png';
}

function platformLabel(platform) {
  return platform === 'ios' ? 'iOS' : 'Android';
}

async function writeJsonAtomically(path, value, mode) {
  await writeAtomically(path, `${JSON.stringify(value, null, 2)}\n`, mode);
}

async function writeAtomically(path, contents, mode) {
  await mkdir(dirname(path), { recursive: true });
  const temporaryPath = `${path}.${process.pid}.${randomUUID()}.tmp`;

  try {
    await writeFile(temporaryPath, contents, { mode });
    await chmod(temporaryPath, mode);
    await rename(temporaryPath, path);
    await chmod(path, mode);
  } catch (error) {
    await rm(temporaryPath, { force: true });
    throw error;
  }
}

function expectObject(value, message) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(message);
  }

  return value;
}

function expectArray(value, message) {
  if (!Array.isArray(value)) {
    throw new Error(message);
  }

  return value;
}

function optionalValue(value) {
  return typeof value === 'string' && value.trim() ? value.trim() : undefined;
}

function valueOrDefault(value, fallback) {
  return optionalValue(value) ?? fallback;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  prepare().catch((error) => {
    console.error(`prepare failed: ${error.message}`);
    process.exitCode = 1;
  });
}
