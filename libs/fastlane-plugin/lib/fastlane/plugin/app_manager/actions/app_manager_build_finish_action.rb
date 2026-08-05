module Fastlane
  module Actions
    class AppManagerBuildFinishAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        current = Actions.lane_context[SharedValues::APP_MANAGER_BUILD]
        build_id = params[:build_id] || current&.fetch(:id, nil)
        UI.user_error!("build_id is required; call app_manager_build_start first") unless build_id

        build = Helper::AppManagerHelper.finish_build(
          build_id: build_id,
          status: params[:status],
          artifact_url: params[:artifact_url]
        )
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD] = build
        UI.success("App Manager build finished as #{build[:status]}")
        build
      end

      def self.description
        "Finishes the current App Manager build with a terminal status"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :status,
            description: "Terminal status (completed, failed or cancelled)",
            type: String,
            default_value: "completed",
            verify_block: lambda do |value|
              UI.user_error!("invalid terminal build status") unless %w[completed failed cancelled].include?(value)
            end
          ),
          string_option(:artifact_url, "Published artifact URL", optional: true),
          string_option(:build_id, "Internal App Manager build UUID", optional: true)
        ]
      end

      def self.return_value
        "The terminal App Manager build hash"
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
