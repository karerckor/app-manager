const fs = require('node:fs');
const path = require('node:path');

const generatedPath = path.join(__dirname, '.app-manager', 'expo.json');

function mergeConfig(config, generated) {
  const merged = {
    ...config,
    ...generated,
    android: generated.android
      ? { ...(config.android ?? {}), ...generated.android }
      : config.android,
    ios: generated.ios ? { ...(config.ios ?? {}), ...generated.ios } : config.ios,
  };

  if (generated.android && !generated.ios) {
    delete merged.ios;
  }
  if (generated.ios && !generated.android) {
    delete merged.android;
  }

  return merged;
}

module.exports = ({ config }) => {
  if (!fs.existsSync(generatedPath)) {
    return config;
  }

  const generated = JSON.parse(fs.readFileSync(generatedPath, 'utf8')).expo;

  return mergeConfig(config, generated);
};

module.exports.mergeConfig = mergeConfig;
