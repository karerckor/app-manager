module Fastlane
  module Helper
    class AppManagerHelper
      class << self
        def configure(api_key:, base_url:, team:, app:, connect_timeout: nil, read_timeout: nil, max_retries: nil)
          Fastlane::AppManager::Configuration.configure(
            api_key: api_key,
            base_url: base_url,
            team: team,
            app: app,
            connect_timeout: connect_timeout,
            read_timeout: read_timeout,
            max_retries: max_retries
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

        def list_builds(**options)
          client.list_builds(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def create_build(**options)
          client.create_build(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def build(build_id)
          client.build(build_id)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def cancel_build(build_id)
          client.cancel_build(build_id)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def update_build(**options)
          client.update_build(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def append_build_log(**options)
          client.append_build_log(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def resolve_build_id(value = nil)
          build_id = value || Actions.lane_context[Actions::SharedValues::APP_MANAGER_BUILD_ID] || ENV["APP_MANAGER_BUILD_ID"]
          normalized = build_id.to_s.strip
          raise Fastlane::AppManager::Error, "App Manager build_id or APP_MANAGER_BUILD_ID is required" if normalized.empty?

          normalized
        end

        def download_credential(**options)
          client.download_credential(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def download_artifact(**options)
          client.download_artifact(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def list_credentials
          client.list_credentials
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def list_identifiers
          client.list_identifiers
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def add_artifact(**options)
          client.upload_artifact(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def list_files
          client.list_files
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def upload_file(**options)
          client.upload_file(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def download_file(**options)
          client.download_file(**options)
        rescue Fastlane::AppManager::Error => e
          UI.user_error!(e.message)
        end

        def delete_file(**options)
          client.delete_file(**options)
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
