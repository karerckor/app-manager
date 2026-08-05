module Fastlane
  module Actions
    class AppManagerDownloadGoogleServiceInfoPlistAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        run_credential_action(params, type: "google_service_info_plist", platform: "ios")
      end

      def self.description
        "Downloads the iOS Firebase client GoogleService-Info.plist file"
      end

      def self.available_options
        credential_options
      end

      def self.return_value
        "A hash containing the downloaded path and credential metadata"
      end

      def self.authors
        plugin_authors
      end

      def self.is_supported?(platform)
        platform == :ios
      end
    end
  end
end
