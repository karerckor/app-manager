module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_FILES = :APP_MANAGER_FILES
    end

    class AppManagerListFilesAction < Action
      def self.run(_params)
        files = Helper::AppManagerHelper.list_files
        Actions.lane_context[SharedValues::APP_MANAGER_FILES] = files
        files.each do |file|
          UI.message("#{file['name']} (#{file['size']} bytes, #{file['mime_type']})")
        end
        files
      end

      def self.description
        "Lists files attached to the configured app"
      end

      def self.details
        "Fetches the app file library from App Manager and stores it in APP_MANAGER_FILES."
      end

      def self.available_options
        []
      end

      def self.output
        [["APP_MANAGER_FILES", "Array of file hashes returned by App Manager"]]
      end

      def self.return_value
        "An array of file hashes"
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
