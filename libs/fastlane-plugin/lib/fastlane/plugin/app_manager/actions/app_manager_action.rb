module Fastlane
  module Actions
    class AppManagerAction < Action
      def self.run(params)
        message = Helper::AppManagerHelper.format_message(params[:message])
        UI.message(message)
        message
      end

      def self.description
        "Runs an App Manager integration step"
      end

      def self.details
        "Starter action ready to be connected to the App Manager API."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :message,
            description: "Message to emit",
            type: String,
            default_value: "plugin is ready"
          )
        ]
      end

      def self.return_value
        "The formatted message"
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

