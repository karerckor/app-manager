require "fastlane/action"
require "fastlane/boolean"
require "fastlane_core"
require "fastlane/plugin/app_manager/version"

module Fastlane
  module AppManager
    def self.all_classes
      Dir[File.expand_path("app_manager/{actions,helper}/*.rb", __dir__)]
    end
  end
end

require "fastlane/plugin/app_manager/configuration"
require "fastlane/plugin/app_manager/client"
require "fastlane/plugin/app_manager/build_runner"
require "fastlane/plugin/app_manager/helper/app_manager_helper"
require "fastlane/plugin/app_manager/helper/action_support"
require "fastlane/plugin/app_manager/actions/app_manager_configure_action"
require "fastlane/plugin/app_manager/actions/app_manager_app_info_action"
require "fastlane/plugin/app_manager/actions/app_manager_build_context_action"
require "fastlane/plugin/app_manager/actions/app_manager_build_start_action"
require "fastlane/plugin/app_manager/actions/app_manager_build_log_action"
require "fastlane/plugin/app_manager/actions/app_manager_build_status_action"
require "fastlane/plugin/app_manager/actions/app_manager_build_finish_action"
require "fastlane/plugin/app_manager/actions/app_manager_run_build_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_icon_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_splashscreen_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_ios_certificate_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_provisioning_profile_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_android_keystore_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_firebase_config_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_apns_key_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_google_play_service_account_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_apple_api_key_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_google_services_json_action"
require "fastlane/plugin/app_manager/actions/app_manager_download_google_service_info_plist_action"
