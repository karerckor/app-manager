module Fastlane
  module Helper
    class AppManagerHelper
      class << self
        def configure(api_key:, base_url:, team:, app:)
          configuration = Fastlane::AppManager::Configuration.configure(
            api_key: api_key,
            base_url: base_url,
            team: team,
            app: app
          )
          @client = Fastlane::AppManager::Client.new(configuration)
          configuration
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

        def build_context(**options)
          client.build_context(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def build(**options)
          client.build(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def build_status(**options)
          client.build_status(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def start_build(**options)
          client.start_build(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def append_build_logs(**options)
          client.append_build_logs(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def finish_build(**options)
          client.finish_build(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def download_credential(**options)
          client.download_credential(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def client
          @client ||= Fastlane::AppManager::Client.new
        end

        def reset!
          @client = nil
        end
      end
    end
  end
end
