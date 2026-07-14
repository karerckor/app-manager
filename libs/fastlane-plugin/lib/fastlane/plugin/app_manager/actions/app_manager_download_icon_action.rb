module Fastlane
  module Actions
    class AppManagerDownloadIconAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        path = Helper::AppManagerHelper.download_asset(
          asset: "icon",
          output_path: params[:output_path],
          overwrite: params.fetch(:overwrite, true)
        )
        UI.success("Downloaded app icon to #{path}")
        path
      end

      def self.description
        "Downloads an app icon from App Manager"
      end

      def self.available_options
        asset_options
      end

      def self.return_value
        "Absolute path to the downloaded icon"
      end

      def self.authors
        plugin_authors
      end

      def self.is_supported?(_platform)
        true
      end
    end
  end
end
