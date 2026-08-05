module Fastlane
  module Actions
    class AppManagerBuildStatusAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        current = Actions.lane_context[SharedValues::APP_MANAGER_BUILD]
        build_id = params[:build_id] || current&.fetch(:id, nil)
        UI.user_error!("build_id is required; call app_manager_build_start first") unless build_id

        build = Helper::AppManagerHelper.build_status(build_id: build_id)
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD] = build
        if build[:cancelled] && params.fetch(:stop_on_cancel, true)
          UI.user_error!("App Manager build #{build[:external_id] || build[:id]} was cancelled; stopping the runner")
        end
        build
      end

      def self.description
        "Checks the current build status and stops Fastlane when App Manager cancelled it"
      end

      def self.available_options
        [
          string_option(:build_id, "Internal App Manager build UUID", optional: true),
          FastlaneCore::ConfigItem.new(
            key: :stop_on_cancel,
            description: "Stop the lane when the build is cancelled",
            type: Boolean,
            default_value: true
          )
        ]
      end

      def self.return_value
        "The refreshed App Manager build hash"
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
