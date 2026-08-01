module Fastlane
  module Actions
    class AppManagerDownloadFileAction < Action
      def self.run(params)
        name = params[:name]
        UI.user_error!("name is required") if name.to_s.strip.empty?

        path = Helper::AppManagerHelper.download_file(
          name: name,
          output_path: params[:output_path],
          overwrite: params[:overwrite].nil? ? true : params[:overwrite]
        )
        UI.success("Downloaded #{name} to #{path}")
        path
      end

      def self.description
        "Downloads a file attached to the configured app"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :name,
            description: "File name as stored by App Manager",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :output_path,
            description: "Local path where the file will be saved",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :overwrite,
            description: "Overwrite an existing file at output_path",
            type: Boolean,
            default_value: true
          )
        ]
      end

      def self.return_value
        "Absolute path to the downloaded file"
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
