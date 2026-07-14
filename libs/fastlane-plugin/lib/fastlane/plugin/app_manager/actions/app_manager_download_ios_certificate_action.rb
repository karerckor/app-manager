module Fastlane
  module Actions
    class AppManagerDownloadIosCertificateAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        run_credential_action(
          params,
          type: "ios_certificate",
          platform: "ios",
          profile_types: Helper::AppManagerActionSupport::IOS_PROFILE_TYPES
        )
      end

      def self.description
        "Downloads an iOS signing certificate for a build profile"
      end

      def self.available_options
        credential_options(profile_types: Helper::AppManagerActionSupport::IOS_PROFILE_TYPES)
      end

      def self.return_value
        "A hash containing path, credential metadata and certificate password in payload"
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
