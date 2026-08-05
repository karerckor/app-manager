module Fastlane
  module Actions
    class AppManagerRunBuildAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        build = AppManagerBuildStartAction.run(
          external_id: params[:external_id],
          ci_provider: params[:ci_provider],
          ci_run_id: params[:ci_run_id],
          ci_run_url: params[:ci_run_url]
        )
        runner = Fastlane::AppManager::BuildRunner.new(
          client: Helper::AppManagerHelper.client,
          build: build,
          poll_interval: params[:poll_interval],
          redact_values: [Fastlane::AppManager::Configuration.current.api_key],
          environment: build_environment(build),
          upload_logs: params.fetch(:upload_logs, true),
          check_cancellation: params.fetch(:check_cancellation, true)
        )
        result = runner.run(command: params[:command])

        if result[:cancelled]
          UI.user_error!("App Manager cancelled build #{build[:external_id]}; command was stopped")
        end

        status = result[:success] ? "completed" : "failed"
        terminal = Helper::AppManagerHelper.finish_build(
          build_id: build.fetch(:id),
          status: status,
          artifact_url: result[:success] ? params[:artifact_url] : nil
        )
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD] = terminal
        UI.user_error!("Build command failed with status #{result[:exit_status]}") unless result[:success]

        terminal
      end

      def self.description
        "Runs a tracked build with optional App Manager logs and remote cancellation"
      end

      def self.details
        "Claims and finishes an external build. Log upload and cancellation polling can be enabled independently."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :external_id,
            env_name: "APP_MANAGER_BUILD_EXTERNAL_ID",
            description: "External build ID registered in App Manager",
            type: String
          ),
          string_option(:command, "Shell command that performs the build"),
          string_option(:artifact_url, "Published artifact URL", optional: true),
          string_option(:ci_provider, "CI provider", optional: true),
          string_option(:ci_run_id, "CI run ID", optional: true),
          string_option(:ci_run_url, "Link to the CI run", optional: true),
          FastlaneCore::ConfigItem.new(
            key: :upload_logs,
            description: "Upload command output and lifecycle messages to App Manager",
            type: Boolean,
            default_value: true
          ),
          FastlaneCore::ConfigItem.new(
            key: :check_cancellation,
            description: "Poll App Manager and stop the command after remote cancellation",
            type: Boolean,
            default_value: true
          ),
          FastlaneCore::ConfigItem.new(
            key: :poll_interval,
            description: "Cancellation poll interval in seconds",
            type: Integer,
            default_value: 5,
            verify_block: ->(value) { UI.user_error!("poll_interval must be positive") unless value.positive? }
          )
        ]
      end

      def self.return_value
        "The completed App Manager build hash"
      end

      def self.authors
        plugin_authors
      end

      def self.is_supported?(_platform)
        true
      end

      def self.build_environment(build)
        target = build.fetch(:target)
        profile = build.fetch(:profile)

        {
          "APP_MANAGER_BUILD_ID" => build[:id],
          "APP_MANAGER_BUILD_EXTERNAL_ID" => build[:external_id],
          "APP_MANAGER_BUILD_PLATFORM" => target[:platform],
          "APP_MANAGER_BUILD_IDENTIFIER" => target[:identifier],
          "APP_MANAGER_BUILD_VERSION" => target[:version],
          "APP_MANAGER_BUILD_VERSION_CODE" => target[:version_code],
          "APP_MANAGER_BUILD_NUMBER" => target[:build_number],
          "APP_MANAGER_BUILD_PROFILE" => profile[:value]
        }
      end
      private_class_method :build_environment
    end
  end
end
