module Fastlane
  module Actions
    class AppManagerDownloadAppleApiKeyAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        run_credential_action(params, type: "apple_api_key", platform: "ios")
      end

      def self.description
        "Downloads an App Store Connect API key for TestFlight or App Store submission"
      end

      def self.available_options
        credential_options
      end

      def self.return_value
        "A hash containing path and App Store Connect key_id/issuer_id in payload"
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
