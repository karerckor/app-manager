module Fastlane
  module Actions
    class AppManagerDownloadFirebaseConfigAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        run_credential_action(
          params,
          type: "firebase_config",
          platform: current_mobile_platform
        )
      end

      def self.description
        "Downloads a Firebase HTTP v1 service-account JSON file"
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

      def self.is_supported?(_platform)
        true
      end
    end
  end
end
