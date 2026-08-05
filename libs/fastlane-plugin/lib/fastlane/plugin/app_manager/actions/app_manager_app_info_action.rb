module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_APP_INFO = :APP_MANAGER_APP_INFO
    end

    class AppManagerAppInfoAction < Action
      def self.run(_params)
        info = Helper::AppManagerHelper.app_info
        Actions.lane_context[SharedValues::APP_MANAGER_APP_INFO] = info
        UI.success(
          "Loaded App Manager info for #{info[:name]} " \
          "(#{info[:app_identifiers].length} identifiers)"
        )
        info
      end

      def self.description
        "Gets the configured application's name and identifiers from App Manager"
      end

      def self.details
        "Uses server-declared platform defaults and exposes the result through APP_MANAGER_APP_INFO."
      end

      def self.available_options
        []
      end

      def self.output
        [
          ["APP_MANAGER_APP_INFO", "App metadata and platform identifiers returned by App Manager"]
        ]
      end

      def self.return_value
        "A hash containing app metadata, identifiers and default identifiers by platform"
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
