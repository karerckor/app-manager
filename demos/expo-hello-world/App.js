import { SafeAreaView, ScrollView, StatusBar, StyleSheet, Text, View } from 'react-native';

import appConfig from './app.json';

let manifest = null;
let metadata = null;

try {
  manifest = require('./platform-manifest.json');
} catch {}
try {
  metadata = require('./platform-metadata.json');
} catch {}

export default function App() {
  if (!manifest) {
    return (
      <SafeAreaView style={styles.screen}>
        <StatusBar barStyle="dark-content" />
        <View style={styles.card}>
          <Text style={styles.title}>Not configured</Text>
          <Text style={styles.body}>
            Run prepare first to fetch config from Platform:{'\n\n'}
            npm run prepare
          </Text>
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.screen}>
      <StatusBar barStyle="dark-content" />
      <ScrollView contentContainerStyle={styles.scroll}>
        <Text style={styles.heading}>{manifest.app.name}</Text>
        <Text style={styles.subheading}>
          Powered by App Manager Platform
        </Text>

        <Section title="App">
          <Row label="Name" value={manifest.app.name} />
          <Row label="Slug" value={manifest.app.slug} />
          <Row label="Version" value={appConfig.expo.version} />
          {manifest.app.description && (
            <Row label="Description" value={manifest.app.description} />
          )}
        </Section>

        <Section title="Platform connection">
          <Row label="Team" value={manifest.team} />
          <Row label="API URL" value={manifest.apiUrl} />
        </Section>

        <Section title="Identifiers">
          <Row label="Android" value={manifest.identifiers.android.identifier} />
          <Row label="iOS" value={manifest.identifiers.ios.identifier} />
          {manifest.identifiers.android.version && (
            <Row label="Version" value={manifest.identifiers.android.version} />
          )}
        </Section>

        {metadata && (
          <Section title="Runtime metadata (from Platform)">
            {metadata.environment && (
              <Row label="Environment" value={metadata.environment} />
            )}
            {metadata.min_supported_version && (
              <Row label="Min version" value={metadata.min_supported_version} />
            )}
            {metadata.features && Object.entries(metadata.features).map(([key, value]) => (
              <Row key={key} label={key} value={String(value)} />
            ))}
            {metadata.remote_config && Object.entries(metadata.remote_config).map(([key, value]) => (
              <Row key={key} label={key} value={String(value)} />
            ))}
          </Section>
        )}

        <Section title="Downloaded files">
          <FileGroup label="Signing credentials" files={manifest.downloadedFiles.credentials} />
          <FileGroup label="Assets" files={manifest.downloadedFiles.assets} />
          {manifest.downloadedFiles.projectFiles.length > 0 && (
            <FileGroup label="Project files" files={manifest.downloadedFiles.projectFiles} />
          )}
        </Section>
      </ScrollView>
    </SafeAreaView>
  );
}

function Section({ title, children }) {
  return (
    <View style={styles.card}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {children}
    </View>
  );
}

function Row({ label, value }) {
  return (
    <View style={styles.row}>
      <Text style={styles.label}>{label}</Text>
      <Text style={styles.value} numberOfLines={3}>{value}</Text>
    </View>
  );
}

function FileGroup({ label, files }) {
  if (!files || files.length === 0) return null;
  return (
    <View style={styles.fileGroup}>
      <Text style={styles.fileGroupLabel}>{label}</Text>
      {files.map((file) => (
        <Text key={file} style={styles.fileItem}>{file}</Text>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    backgroundColor: '#f2f5ff',
    flex: 1,
  },
  scroll: {
    padding: 20,
    paddingTop: 12,
    paddingBottom: 40,
  },
  heading: {
    color: '#172554',
    fontSize: 28,
    fontWeight: '800',
    marginBottom: 4,
  },
  subheading: {
    color: '#64748b',
    fontSize: 15,
    marginBottom: 20,
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 16,
    marginBottom: 12,
    padding: 16,
    shadowColor: '#172554',
    shadowOffset: { height: 2, width: 0 },
    shadowOpacity: 0.06,
    shadowRadius: 8,
  },
  sectionTitle: {
    color: '#4f46e5',
    fontSize: 13,
    fontWeight: '700',
    letterSpacing: 1,
    marginBottom: 12,
    textTransform: 'uppercase',
  },
  row: {
    borderBottomColor: '#f1f5f9',
    borderBottomWidth: StyleSheet.hairlineWidth,
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingBottom: 8,
    paddingTop: 8,
  },
  label: {
    color: '#64748b',
    fontSize: 14,
    flexShrink: 0,
    marginRight: 12,
  },
  value: {
    color: '#1e293b',
    flex: 1,
    fontSize: 14,
    fontWeight: '600',
    textAlign: 'right',
  },
  fileGroup: {
    marginBottom: 8,
  },
  fileGroupLabel: {
    color: '#475569',
    fontSize: 12,
    fontWeight: '700',
    marginBottom: 4,
    textTransform: 'uppercase',
  },
  fileItem: {
    color: '#1e293b',
    fontSize: 13,
    fontFamily: 'Courier',
    paddingLeft: 8,
    paddingTop: 2,
  },
});
