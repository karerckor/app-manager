module Fastlane
  module Actions
    class AppManagerDownloadAndroidKeystoreAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        run_credential_action(
          params,
          type: "android_keystore",
          platform: "android",
          profile_types: Helper::AppManagerActionSupport::ANDROID_PROFILE_TYPES
        )
      end

      def self.description
        "Downloads an Android keystore for a build profile"
      end

      def self.available_options
        credential_options(profile_types: Helper::AppManagerActionSupport::ANDROID_PROFILE_TYPES)
      end

      def self.return_value
        "A hash containing path, credential metadata and keystore secrets in payload"
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
