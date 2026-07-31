module Fastlane
  module Actions
    class AppManagerDownloadArtifactAction < Action
      def self.run(params)
        build_id = Helper::AppManagerHelper.resolve_build_id(params[:build_id])
        artifact_id = params[:artifact_id]
        UI.user_error!("artifact_id is required") if artifact_id.to_s.strip.empty?

        path = Helper::AppManagerHelper.download_artifact(
          build_id: build_id,
          artifact_id: artifact_id,
          output_path: params[:output_path],
          overwrite: params[:overwrite].nil? ? true : params[:overwrite]
        )
        UI.success("Downloaded artifact #{artifact_id} to #{path}")
        path
      end

      def self.description
        "Downloads a build artifact from App Manager"
      end

      def self.available_options
        [
          FastlaneCore::ConfigItem.new(
            key: :artifact_id,
            description: "Artifact UUID to download",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :output_path,
            description: "Local path where the artifact file will be saved",
            type: String
          ),
          FastlaneCore::ConfigItem.new(
            key: :build_id,
            description: "Build UUID; defaults to APP_MANAGER_BUILD_ID",
            type: String,
            optional: true
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
        "Absolute path to the downloaded artifact"
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
