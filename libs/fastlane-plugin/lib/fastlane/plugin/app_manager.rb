require "fastlane/action"
require "fastlane/boolean"
require "fastlane_core"
require "fastlane/plugin/app_manager/version"
require "fastlane/plugin/app_manager/configuration"
require "fastlane/plugin/app_manager/client"

module Fastlane
  module AppManager
    # Fastlane's plugin loader uses this list both to require plugin classes and
    # to associate actions with the gem in its startup summary.
    def self.all_classes
      root = File.expand_path("app_manager", __dir__)
      Dir[File.join(root, "helper", "*.rb")].sort +
        Dir[File.join(root, "actions", "*.rb")].sort
    end
  end
end

Fastlane::AppManager.all_classes.each { |current| require current }
