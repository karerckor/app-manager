require "fastlane"
require "fastlane/plugin/app_manager"

RSpec.configure do |config|
  config.before do
    Fastlane::AppManager::Configuration.reset!
    Fastlane::Helper::AppManagerHelper.reset!
  end

  config.after do
    Fastlane::AppManager::Configuration.reset!
    Fastlane::Helper::AppManagerHelper.reset!
  end
end
