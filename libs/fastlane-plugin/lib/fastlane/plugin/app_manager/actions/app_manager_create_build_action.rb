require "securerandom"

module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_BUILD_ID = :APP_MANAGER_BUILD_ID
    end

    class AppManagerCreateBuildAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        platform = params[:platform] || current_mobile_platform
        UI.user_error!("platform must be ios or android") if platform && !%w[ios android].include?(platform.to_s)

        build = Helper::AppManagerHelper.create_build(
          request_uuid: params[:request_uuid] || SecureRandom.uuid,
          profile_type: params[:profile_type],
          platform: platform&.to_s,
          app_identifier_id: params[:app_identifier_id],
          context: params[:context] || {}
        )
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD_ID] = build.fetch("id")
        UI.success("Created App Manager build #{build.fetch('id')}")
        build
      end

      def self.description
        "Creates a manually reported build in pending status"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(key: :request_uuid, description: "Idempotency UUID", type: String, optional: true),
          FastlaneCore::ConfigItem.new(key: :profile_type, description: "App Manager build profile", type: String),
          string_option(:platform, "Build platform; defaults to the current platform lane", optional: true),
          FastlaneCore::ConfigItem.new(key: :app_identifier_id, description: "Optional App Manager identifier UUID", type: String, optional: true),
          FastlaneCore::ConfigItem.new(key: :context, description: "JSON-compatible build context", type: Hash, optional: true)
        ]
      end

      def self.output
        [["APP_MANAGER_BUILD_ID", "UUID of the created App Manager build"]]
      end

      def self.return_value
        "The build response returned by App Manager"
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
