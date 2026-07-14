require "fastlane"
require "fastlane/plugin/app_manager"

RSpec.describe Fastlane::Actions::AppManagerAction do
  it "returns a formatted message" do
    expect(described_class.run(message: "ready")).to eq("App Manager: ready")
  end
end

