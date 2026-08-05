module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_BUILD = :APP_MANAGER_BUILD
      APP_MANAGER_BUILD_LOG_SEQUENCE = :APP_MANAGER_BUILD_LOG_SEQUENCE
    end

    class AppManagerBuildStartAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        external_id = params[:external_id]
        ci_provider = params[:ci_provider] || (ENV["GITHUB_ACTIONS"] == "true" ? "github" : "fastlane")
        ci_run_id = params[:ci_run_id] || ENV["GITHUB_RUN_ID"] || external_id
        ci_run_url = params[:ci_run_url] || github_run_url
        build = Helper::AppManagerHelper.start_build(
          external_id: external_id,
          ci_provider: ci_provider,
          ci_run_id: ci_run_id,
          ci_run_url: ci_run_url
        )

        if build[:cancelled]
          UI.user_error!("App Manager build #{external_id} was cancelled; stopping the runner")
        end

        Actions.lane_context[SharedValues::APP_MANAGER_BUILD] = build
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD_LOG_SEQUENCE] = build[:last_log_sequence] || 0
        UI.success("Started App Manager build #{external_id} for #{build.dig(:target, :identifier)}")
        build
      end

      def self.description
        "Claims a pending App Manager build by external ID and loads its immutable target versions"
      end

      def self.details
        "Stops immediately when the build was cancelled, otherwise changes pending to running atomically."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :external_id,
            env_name: "APP_MANAGER_BUILD_EXTERNAL_ID",
            description: "External build ID registered in App Manager",
            type: String
          ),
          string_option(:ci_provider, "CI provider; defaults to github or fastlane", optional: true),
          string_option(:ci_run_id, "CI run ID; defaults to GITHUB_RUN_ID or external_id", optional: true),
          string_option(:ci_run_url, "Link to the CI run", optional: true)
        ]
      end

      def self.output
        [["APP_MANAGER_BUILD", "Claimed build and its immutable context snapshot"]]
      end

      def self.return_value
        "A normalized App Manager build hash"
      end

      def self.authors
        plugin_authors
      end

      def self.is_supported?(_platform)
        true
      end

      def self.github_run_url
        return unless ENV["GITHUB_SERVER_URL"] && ENV["GITHUB_REPOSITORY"] && ENV["GITHUB_RUN_ID"]

        "#{ENV['GITHUB_SERVER_URL']}/#{ENV['GITHUB_REPOSITORY']}/actions/runs/#{ENV['GITHUB_RUN_ID']}"
      end
      private_class_method :github_run_url
    end
  end
end
