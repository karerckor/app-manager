import { SafeAreaView, StatusBar, StyleSheet, Text, View } from 'react-native';

import appConfig from './app.json';

export default function App() {
  return (
    <SafeAreaView style={styles.screen}>
      <StatusBar barStyle="dark-content" />
      <View style={styles.card}>
        <Text style={styles.eyebrow}>{appConfig.expo.name}</Text>
        <Text style={styles.title}>Hello, world!</Text>
        <Text style={styles.body}>
          Expo config and Android/iOS credentials came from App Manager.
        </Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: {
    alignItems: 'center',
    backgroundColor: '#f2f5ff',
    flex: 1,
    justifyContent: 'center',
    padding: 24,
  },
  card: {
    backgroundColor: '#ffffff',
    borderRadius: 24,
    maxWidth: 420,
    padding: 32,
    shadowColor: '#172554',
    shadowOffset: { height: 12, width: 0 },
    shadowOpacity: 0.12,
    shadowRadius: 24,
    width: '100%',
  },
  eyebrow: {
    color: '#4f46e5',
    fontSize: 14,
    fontWeight: '700',
    letterSpacing: 1.2,
    marginBottom: 12,
    textTransform: 'uppercase',
  },
  title: {
    color: '#172554',
    fontSize: 36,
    fontWeight: '800',
    marginBottom: 12,
  },
  body: {
    color: '#475569',
    fontSize: 17,
    lineHeight: 26,
  },
});
