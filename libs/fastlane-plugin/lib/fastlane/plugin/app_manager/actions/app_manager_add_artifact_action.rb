module Fastlane
  module Actions
    class AppManagerAddArtifactAction < Action
      def self.run(params)
        build_id = Helper::AppManagerHelper.resolve_build_id(params[:build_id])
        file = params[:file]
        UI.user_error!("file #{file} does not exist") unless File.file?(file.to_s)
        name = params[:name] || File.basename(file.to_s)

        artifact = Helper::AppManagerHelper.add_artifact(
          build_id: build_id,
          file_path: file,
          name: name,
          metadata: params[:metadata] || {}
        )
        UI.success("Attached artifact #{name} to App Manager build #{build_id}")
        artifact
      end

      def self.description
        "Uploads a build artifact and attaches it to an App Manager build"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(key: :file, description: "Path to the artifact file to upload", type: String),
          FastlaneCore::ConfigItem.new(key: :name, description: "Artifact name; defaults to the file basename", type: String, optional: true),
          FastlaneCore::ConfigItem.new(key: :metadata, description: "JSON-compatible metadata hash", type: Hash, optional: true),
          FastlaneCore::ConfigItem.new(key: :build_id, description: "Build UUID; defaults to APP_MANAGER_BUILD_ID", type: String, optional: true)
        ]
      end

      def self.return_value
        "The artifact response returned by App Manager"
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
