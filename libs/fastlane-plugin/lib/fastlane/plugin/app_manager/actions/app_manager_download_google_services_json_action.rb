module Fastlane
  module Actions
    class AppManagerDownloadGoogleServicesJsonAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        run_credential_action(params, type: "google_services_json", platform: "android")
      end

      def self.description
        "Downloads the Android Firebase client google-services.json file"
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
        platform == :android
      end
    end
  end
end
