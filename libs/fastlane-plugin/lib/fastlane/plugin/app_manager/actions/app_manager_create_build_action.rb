require "securerandom"

module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_BUILD_ID = :APP_MANAGER_BUILD_ID
    end

    class AppManagerCreateBuildAction < Action
      def self.run(params)
        build = Helper::AppManagerHelper.create_build(
          request_uuid: params[:request_uuid] || SecureRandom.uuid,
          profile_type: params[:profile_type],
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
