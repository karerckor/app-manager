module Fastlane
  module Actions
    class AppManagerDeleteFileAction < Action
      def self.run(params)
        name = params[:name]
        UI.user_error!("name is required") if name.to_s.strip.empty?

        Helper::AppManagerHelper.delete_file(name: name)
        UI.success("Deleted #{name} from the app")
        true
      end

      def self.description
        "Deletes a file attached to the configured app"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :name,
            description: "File name as stored by App Manager",
            type: String
          )
        ]
      end

      def self.return_value
        "true when the file was deleted"
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
