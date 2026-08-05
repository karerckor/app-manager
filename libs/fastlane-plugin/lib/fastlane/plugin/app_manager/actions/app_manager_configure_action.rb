module Fastlane
  module Actions
    class AppManagerConfigureAction < Action
      def self.run(params)
        configuration = Helper::AppManagerHelper.configure(
          api_key: params[:api_key],
          base_url: params[:base_url],
          team: params[:team],
          app: params[:app]
        )
        UI.success(
          "Configured App Manager at #{configuration.base_url} " \
          "for #{configuration.team}/#{configuration.app}"
        )
        true
      rescue Fastlane::AppManager::Error => e
        UI.user_error!(e.message)
      end

      def self.description
        "Configures the App Manager API for subsequent actions"
      end

      def self.details
        "Stores the API key, API base URL, team slug and app slug for the current Fastlane process."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :api_key,
            env_name: "APP_MANAGER_API_KEY",
            description: "App Manager API key",
            type: String,
            optional: true,
            sensitive: true
          ),
          FastlaneCore::ConfigItem.new(
            key: :base_url,
            env_name: "APP_MANAGER_BASE_URL",
            description: "Self-hosted App Manager instance URL or exact API v1 URL",
            type: String,
            default_value: Fastlane::AppManager::Configuration::DEFAULT_BASE_URL
          ),
          FastlaneCore::ConfigItem.new(
            key: :team,
            env_name: "APP_MANAGER_TEAM",
            description: "App Manager team slug",
            type: String,
            optional: true
          ),
          FastlaneCore::ConfigItem.new(
            key: :app,
            env_name: "APP_MANAGER_APP",
            description: "App slug in App Manager",
            type: String,
            optional: true
          )
        ]
      end

      def self.return_value
        "true when configuration succeeds"
      end

      def self.authors
        ["App Manager contributors"]
      end

      def self.is_supported?(_platform)
        true
      end
    end
  end
end
