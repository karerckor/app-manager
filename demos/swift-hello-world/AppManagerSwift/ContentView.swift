import SwiftUI
import UIKit

struct ContentView: View {
    private var displayName: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleDisplayName") as? String ?? "App Manager Swift"
    }

    private var bundleIdentifier: String {
        Bundle.main.bundleIdentifier ?? "unknown.bundle"
    }

    var body: some View {
        VStack(spacing: 24) {
            if UIImage(named: "AppManagerSplash") != nil {
                Image("AppManagerSplash")
                    .resizable()
                    .scaledToFit()
                    .frame(maxHeight: 280)
            }
            Text(displayName)
                .font(.largeTitle)
                .multilineTextAlignment(.center)
            Text(bundleIdentifier)
                .font(.footnote)
                .foregroundStyle(.secondary)
        }
        .padding(32)
    }
}
