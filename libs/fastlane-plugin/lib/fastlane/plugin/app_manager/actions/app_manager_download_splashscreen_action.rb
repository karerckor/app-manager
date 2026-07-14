module Fastlane
  module Actions
    class AppManagerDownloadSplashscreenAction < Action
      extend Helper::AppManagerActionSupport

      def self.run(params)
        path = Helper::AppManagerHelper.download_asset(
          asset: "splashscreen",
          output_path: params[:output_path],
          overwrite: params.fetch(:overwrite, true)
        )
        UI.success("Downloaded splash screen to #{path}")
        path
      end

      def self.description
        "Downloads a splash screen from App Manager"
      end

      def self.available_options
        asset_options
      end

      def self.return_value
        "Absolute path to the downloaded splash screen"
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
