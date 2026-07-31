module Fastlane
  module Actions
    class AppManagerCancelBuildAction < Action
      def self.run(params)
        build_id = Helper::AppManagerHelper.resolve_build_id(params[:build_id])
        build = Helper::AppManagerHelper.cancel_build(build_id)
        UI.success("Cancelled App Manager build #{build_id}")
        build
      end

      def self.description
        "Requests cancellation of an App Manager build"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :build_id,
            description: "Build UUID; defaults to APP_MANAGER_BUILD_ID",
            type: String,
            optional: true
          )
        ]
      end

      def self.return_value
        "The updated build response"
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
