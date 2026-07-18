module Fastlane
  module Actions
    class AppManagerBuildLogAction < Action
      def self.run(params)
        build_id = Helper::AppManagerHelper.resolve_build_id(params[:build_id])
        content = params[:message].to_s
        content += "\n" if params[:newline] && !content.end_with?("\n")
        Helper::AppManagerHelper.append_build_log(build_id: build_id, content: content)
      end

      def self.description
        "Appends plain-text output to a manually reported App Manager build"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(key: :build_id, description: "Build UUID; defaults to APP_MANAGER_BUILD_ID", type: String, optional: true),
          FastlaneCore::ConfigItem.new(key: :message, description: "Plain-text log content", type: String),
          FastlaneCore::ConfigItem.new(key: :newline, description: "Append a trailing newline", type: Fastlane::Boolean, default_value: true)
        ]
      end

      def self.return_value
        "Build log metadata returned by App Manager"
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
