module Fastlane
  module Actions
    class AppManagerUploadFileAction < Action
      def self.run(params)
        file_path = params[:file_path]
        UI.user_error!("file_path is required") if file_path.to_s.strip.empty?

        file = Helper::AppManagerHelper.upload_file(
          file_path: file_path,
          metadata: params[:metadata] || {}
        )
        UI.success("Attached #{file['name']} to the app")
        file
      end

      def self.description
        "Attaches a file to the configured app in App Manager"
      end

      def self.details
        "File names are unique per app. Delete the existing file first to replace it."
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :file_path,
            description: "Local path of the file to attach",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :metadata,
            description: "Hash of arbitrary metadata stored with the file",
            type: Hash,
            optional: true,
            default_value: {}
          )
        ]
      end

      def self.return_value
        "The created file hash"
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
