module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_BUILD_CONTEXT = :APP_MANAGER_BUILD_CONTEXT
    end

    class AppManagerBuildContextAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        platform = (params[:platform] || current_mobile_platform).to_s
        UI.user_error!("platform must be ios or android") unless %w[ios android].include?(platform)

        profile_types = platform == "ios" ?
          Helper::AppManagerActionSupport::IOS_PROFILE_TYPES :
          Helper::AppManagerActionSupport::ANDROID_PROFILE_TYPES
        validate_profile_type!(params[:profile_type], profile_types)

        context = Helper::AppManagerHelper.build_context(
          platform: platform,
          profile_type: params[:profile_type],
          app_identifier: params[:app_identifier]
        )
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD_CONTEXT] = context
        UI.success(
          "Loaded App Manager build context for " \
          "#{context.dig(:target, :identifier)} (#{context.dig(:profile, :value)})"
        )
        context
      end

      def self.description
        "Loads a deterministic App Manager build context for one platform and profile"
      end

      def self.details
        "Returns app metadata, the selected target, profile, assets and compatible credential candidates."
      end

      def self.available_options
        [
          string_option(:platform, "Build platform (ios or android); defaults to the current platform lane", optional: true),
          string_option(:profile_type, "Build profile type"),
          string_option(:app_identifier, "Exact bundle ID or Android package name", optional: true)
        ]
      end

      def self.output
        [
          ["APP_MANAGER_BUILD_CONTEXT", "Selected app, target, profile, assets and credential candidates"]
        ]
      end

      def self.return_value
        "A normalized hash containing the App Manager build context"
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
