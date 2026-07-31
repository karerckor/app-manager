module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_BUILD_INFO = :APP_MANAGER_BUILD_INFO
    end

    class AppManagerShowBuildAction < Action
      def self.run(params)
        build_id = Helper::AppManagerHelper.resolve_build_id(params[:build_id])
        build = Helper::AppManagerHelper.build(build_id)
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD_INFO] = build
        print_build_info(build)
        build
      end

      def self.description
        "Shows details of an App Manager build"
      end

      def self.details
        "Fetches the full build resource from App Manager. " \
        "Stores the result in APP_MANAGER_BUILD_INFO lane context."
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

      def self.output
        [["APP_MANAGER_BUILD_INFO", "Build hash returned by App Manager"]]
      end

      def self.return_value
        "The build hash"
      end

      def self.authors
        ["App Manager contributors"]
      end

      def self.is_supported?(_platform)
        true
      end

      def self.print_build_info(build)
        UI.message("")
        UI.message("  Build #{build['id']}")
        UI.message("  #{'-' * 40}")
        UI.message("  Status:       #{build['status']}")
        UI.message("  Profile:      #{build['profile_type']}") if build["profile_type"]
        UI.message("  Reporting:    #{build['reporting_mode']}") if build["reporting_mode"]
        UI.message("  Started:      #{build['started_at']}") if build["started_at"]
        UI.message("  Finished:     #{build['finished_at']}") if build["finished_at"]
        UI.message("  Created:      #{build['created_at']}")
        UI.message("  Updated:      #{build['updated_at']}")
        UI.message("")
      end
    end
  end
end
