module Fastlane
  module Actions
    class AppManagerDownloadApnsKeyAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        run_credential_action(params, type: "apns_key", platform: "ios")
      end

      def self.description
        "Downloads an APNs authentication key"
      end

      def self.available_options
        credential_options
      end

      def self.return_value
        "A hash containing path and APNs key_id/team_id in payload"
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
