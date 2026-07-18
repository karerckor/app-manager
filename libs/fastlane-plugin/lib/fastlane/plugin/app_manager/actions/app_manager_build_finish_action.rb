module Fastlane
  module Actions
    class AppManagerBuildFinishAction < Action
      STATUSES = %w[completed failed cancelled].freeze

      def self.run(params)
        build_id = Helper::AppManagerHelper.resolve_build_id(params[:build_id])
        status = params[:status].to_s
        UI.user_error!("status must be completed, failed, or cancelled") unless STATUSES.include?(status)

        build = Helper::AppManagerHelper.update_build(build_id: build_id, status: status)
        UI.success("Finished App Manager build #{build_id} with status #{status}")
        build
      end

      def self.description
        "Finishes a manually reported App Manager build"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(key: :build_id, description: "Build UUID; defaults to APP_MANAGER_BUILD_ID", type: String, optional: true),
          FastlaneCore::ConfigItem.new(key: :status, description: "completed, failed, or cancelled", type: String, default_value: "completed")
        ]
      end

      def self.return_value
        "The finished build response"
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
