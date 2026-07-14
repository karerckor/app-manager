module Fastlane
  module Helper
    class AppManagerHelper
      class << self
        def configure(api_key:, base_url:, team:, app:)
          Fastlane::AppManager::Configuration.configure(
            api_key: api_key,
            base_url: base_url,
            team: team,
            app: app
          )
        end

        def download_asset(**options)
          client.download_asset(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def app_info
          client.app_info
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def download_credential(**options)
          client.download_credential(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def client
          Fastlane::AppManager::Client.new
        end
      end
    end
  end
end
