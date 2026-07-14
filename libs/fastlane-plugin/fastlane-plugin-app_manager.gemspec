$LOAD_PATH.unshift File.expand_path("lib", __dir__)
require "fastlane/plugin/app_manager/version"

Gem::Specification.new do |spec|
  spec.name = "fastlane-plugin-app_manager"
  spec.version = Fastlane::AppManager::VERSION
  spec.authors = ["App Manager contributors"]
  spec.summary = "Composable Fastlane actions for App Manager"
  spec.description = "Downloads application assets, signing credentials and submission keys from App Manager."
  spec.homepage = "https://rubygems.org/gems/fastlane-plugin-app_manager"
  spec.license = "MIT"
  spec.required_ruby_version = ">= 3.1"
  spec.files = Dir["lib/**/*", "README.md", "LICENSE"]
  spec.require_paths = ["lib"]
  spec.add_dependency "fastlane", ">= 2.220", "< 3"
end
