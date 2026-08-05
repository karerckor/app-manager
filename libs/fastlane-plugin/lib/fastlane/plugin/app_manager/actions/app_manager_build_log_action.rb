module Fastlane
  module Actions
    class AppManagerBuildLogAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        build = Actions.lane_context[SharedValues::APP_MANAGER_BUILD]
        build_id = params[:build_id] || build&.fetch(:id, nil)
        UI.user_error!("build_id is required; call app_manager_build_start first") unless build_id

        current = Actions.lane_context[SharedValues::APP_MANAGER_BUILD_LOG_SEQUENCE] || 0
        sequence = params[:sequence] || current + 1
        result = Helper::AppManagerHelper.append_build_logs(
          build_id: build_id,
          entries: [{
            sequence: sequence,
            stream: params[:stream],
            level: params[:level],
            message: params[:message],
            logged_at: Time.now.utc.iso8601
          }]
        )
        Actions.lane_context[SharedValues::APP_MANAGER_BUILD_LOG_SEQUENCE] = [
          current,
          result[:last_sequence] || sequence
        ].max
        result
      end

      def self.description
        "Appends an idempotent sequenced log entry to the current App Manager build"
      end

      def self.available_options
        [
          string_option(:message, "Log message"),
          FastlaneCore::ConfigItem.new(
            key: :stream,
            description: "Log stream (stdout, stderr or system)",
            type: String,
            default_value: "stdout",
            verify_block: ->(value) { UI.user_error!("invalid stream") unless %w[stdout stderr system].include?(value) }
          ),
          FastlaneCore::ConfigItem.new(
            key: :level,
            description: "Log level",
            type: String,
            default_value: "info",
            verify_block: ->(value) { UI.user_error!("invalid level") unless %w[debug info warning error].include?(value) }
          ),
          string_option(:build_id, "Internal App Manager build UUID", optional: true),
          FastlaneCore::ConfigItem.new(
            key: :sequence,
            description: "Explicit idempotency sequence",
            type: Integer,
            optional: true
          )
        ]
      end

      def self.return_value
        "Accepted count and last sequence"
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
