import { randomUUID } from 'node:crypto';
import { chmod, mkdir, readFile, rename, rm, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const GENERATED_DIRECTORY = '.app-manager';

export async function prepare({
  cwd = process.cwd(),
  input,
  inputPath = join(cwd, GENERATED_DIRECTORY, 'adapter-input.json'),
  logger = console,
} = {}) {
  const value = input ?? JSON.parse(await readFile(inputPath, 'utf8'));
  const definition = expectObject(value, 'The Expo adapter input must be an object.');
  const platform = expectPlatform(definition.platform);
  const context = expectObject(definition.context, 'The build context is missing.');
  const files = expectObject(definition.files ?? {}, 'The downloaded files map is invalid.');

  if (context.schema_version !== 1) {
    throw new Error(`Unsupported App Manager build context schema: ${context.schema_version}.`);
  }

  const app = expectObject(context.app, 'The build context app is missing.');
  const target = expectObject(context.target, 'The build context target is missing.');
  const profile = expectObject(context.profile, 'The build context profile is missing.');
  const targetPlatform = String(target.platform ?? '');

  if (targetPlatform !== platform || String(profile.platform ?? '') !== platform) {
    throw new Error(`The build context does not match the requested ${platform} platform.`);
  }

  const name = requiredString(app.name, 'The App Manager app name is missing.');
  const slug = requiredString(app.slug, 'The App Manager app slug is missing.');
  const identifier = requiredString(
    target.identifier,
    `The App Manager ${platform} identifier is missing.`,
  );
  const version = requiredString(target.version, 'The App Manager app version is missing.');
  const profileType = requiredString(
    profile.value,
    `The App Manager ${platform} profile type is missing.`,
  );

  const expo = {
    name,
    slug,
    version,
    orientation: 'portrait',
    userInterfaceStyle: 'automatic',
  };

  if (typeof app.description === 'string' && app.description.trim()) {
    expo.description = app.description.trim();
  }
  if (files.icon) {
    expo.icon = expoPath(expectPath(files.icon, 'icon'));
  }
  if (files.splashscreen) {
    expo.splash = {
      image: expoPath(expectPath(files.splashscreen, 'splash screen')),
      resizeMode: 'contain',
      backgroundColor: '#ffffff',
    };
  }

  let credentials;
  let buildContext;

  if (platform === 'android') {
    const versionCode = target.version_code;
    if (!Number.isInteger(versionCode) || versionCode <= 0) {
      throw new Error('The App Manager Android version_code must be a positive integer.');
    }

    const keystore = expectFile(files.keystore, 'Android keystore');
    const payload = expectObject(keystore.payload, 'The Android keystore payload is missing.');
    const storePassword = requiredString(
      payload.store_password,
      'The Android keystore store_password is missing.',
    );
    const keyAlias = requiredString(
      payload.key_alias,
      'The Android keystore key_alias is missing.',
    );
    const keyPassword = requiredString(
      payload.key_password,
      'The Android keystore key_password is missing.',
    );

    expo.android = { package: identifier, versionCode };
    if (files.google_services) {
      expo.android.googleServicesFile = expoPath(
        expectPath(files.google_services, 'google-services.json'),
      );
    }

    credentials = {
      android: {
        keystore: {
          keystorePath: keystore.path,
          keystorePassword: storePassword,
          keyAlias,
          keyPassword,
        },
      },
    };
    buildContext = {
      app: slug,
      platform,
      version,
      android: {
        package: identifier,
        versionCode,
        profileType,
        googlePlayJsonKeyPath: files.google_play
          ? expectPath(files.google_play, 'Google Play service account')
          : null,
      },
    };
  } else {
    const buildNumber = requiredString(
      target.build_number,
      'The App Manager iOS build_number is missing.',
    );
    const certificate = expectFile(files.certificate, 'iOS certificate');
    const certificatePayload = expectObject(
      certificate.payload,
      'The iOS certificate payload is missing.',
    );
    const certificatePassword = requiredString(
      certificatePayload.password,
      'The iOS certificate password is missing.',
    );
    const provisioningProfile = expectFile(
      files.provisioning_profile,
      'iOS provisioning profile',
    );

    expo.ios = { bundleIdentifier: identifier, buildNumber };
    if (files.google_service_info) {
      expo.ios.googleServicesFile = expoPath(
        expectPath(files.google_service_info, 'GoogleService-Info.plist'),
      );
    }

    credentials = {
      ios: {
        provisioningProfilePath: provisioningProfile.path,
        distributionCertificate: {
          path: certificate.path,
          password: certificatePassword,
        },
      },
    };

    let appStoreApiKey = null;
    if (files.apple_api_key) {
      const apiKey = expectFile(files.apple_api_key, 'App Store Connect API key');
      const payload = expectObject(apiKey.payload, 'The App Store Connect API key payload is missing.');
      appStoreApiKey = {
        path: apiKey.path,
        keyId: requiredString(payload.key_id, 'The App Store Connect key_id is missing.'),
        issuerId: requiredString(
          payload.issuer_id,
          'The App Store Connect issuer_id is missing.',
        ),
      };
    }

    buildContext = {
      app: slug,
      platform,
      version,
      ios: {
        bundleIdentifier: identifier,
        buildNumber,
        profileType,
        appStoreApiKey,
      },
    };
  }

  const expoConfigPath = join(cwd, GENERATED_DIRECTORY, 'expo.json');
  const credentialsPath = join(cwd, GENERATED_DIRECTORY, 'credentials.json');
  const contextPath = join(cwd, GENERATED_DIRECTORY, 'context.json');

  await Promise.all([
    writeJsonAtomically(expoConfigPath, { expo }, 0o644),
    writeJsonAtomically(credentialsPath, credentials, 0o600),
    writeJsonAtomically(contextPath, buildContext, 0o600),
  ]);

  logger.log(`Prepared ${name} for ${platform} from the Fastlane plugin output.`);
  logger.log(`  Identifier: ${identifier}`);
  logger.log(`  Generated config: ${expoConfigPath}`);

  return { buildContext, contextPath, credentials, credentialsPath, expo: { expo }, expoConfigPath };
}

function expectPlatform(value) {
  const platform = String(value ?? '').trim();
  if (!['android', 'ios'].includes(platform)) {
    throw new Error('platform must be android or ios.');
  }
  return platform;
}

function expectFile(value, label) {
  const file = expectObject(value, `${label} download result is missing.`);
  expectPath(file, label);
  return file;
}

function expectPath(value, label) {
  const path = typeof value === 'string' ? value : value?.path;
  return requiredString(path, `${label} path is missing.`);
}

function expoPath(path) {
  return path.startsWith('./') || path.startsWith('../') ? path : `./${path}`;
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

function requiredString(value, message) {
  if (typeof value !== 'string' || !value.trim()) {
    throw new Error(message);
  }
  return value.trim();
}

function readArgument(name) {
  const index = process.argv.indexOf(name);
  return index === -1 ? undefined : process.argv[index + 1];
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  prepare({
    cwd: readArgument('--cwd') ?? process.cwd(),
    inputPath: readArgument('--input'),
  }).catch((error) => {
    console.error(`prepare failed: ${error.message}`);
    process.exitCode = 1;
  });
}
