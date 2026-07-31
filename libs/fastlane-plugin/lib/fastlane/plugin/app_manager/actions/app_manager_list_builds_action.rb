module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_BUILDS = :APP_MANAGER_BUILDS
    end

    class AppManagerListBuildsAction < Action
      def self.run(params)
        builds = Helper::AppManagerHelper.list_builds(
          status: params[:status],
          limit: params[:limit]
        )
        Actions.lane_context[SharedValues::APP_MANAGER_BUILDS] = builds
        print_builds_table(builds)
        builds
      end

      def self.description
        "Lists builds for the configured app"
      end

      def self.details
        "Fetches builds from App Manager with optional status filter. " \
        "Stores the result in APP_MANAGER_BUILDS lane context."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :status,
            description: "Filter by build status (pending, running, completed, failed, cancelled)",
            type: String,
            optional: true
          ),
          FastlaneCore::ConfigItem.new(
            key: :limit,
            description: "Maximum number of builds to return",
            type: Integer,
            optional: true
          )
        ]
      end

      def self.output
        [["APP_MANAGER_BUILDS", "Array of build hashes returned by App Manager"]]
      end

      def self.return_value
        "An array of build hashes"
      end

      def self.authors
        ["App Manager contributors"]
      end

      def self.is_supported?(_platform)
        true
      end

      def self.print_builds_table(builds)
        return if builds.empty?

        UI.message("")
        UI.message("  Builds (#{builds.length}):")
        UI.message("  #{'ID'.ljust(38)} #{'Status'.ljust(20)} #{'Profile'.ljust(15)} #{'Created'}")
        UI.message("  #{'-' * 38} #{'-' * 20} #{'-' * 15} #{'-' * 24}")
        builds.each do |build|
          id = build["id"].to_s.ljust(38)
          status = build["status"].to_s.ljust(20)
          profile = build["profile_type"].to_s.ljust(15)
          created = build["created_at"].to_s[0, 19]
          UI.message("  #{id} #{status} #{profile} #{created}")
        end
        UI.message("")
      end
    end
  end
end
