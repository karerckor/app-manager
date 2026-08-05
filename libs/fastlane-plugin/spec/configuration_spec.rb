require "spec_helper"

RSpec.describe Fastlane::AppManager::Configuration do
  describe ".configure" do
    it "normalizes the server URL and builds the API root" do
      configuration = described_class.configure(
        api_key: "secret",
        base_url: "http://localhost:8000/",
        team: "mobile-team",
        app: "customer-app"
      )

      expect(configuration.api_root).to eq("http://localhost:8000/api/v1")
      expect(configuration.team).to eq("mobile-team")
      expect(configuration.app).to eq("customer-app")
    end

    it "accepts a base URL that already contains the API version" do
      configuration = described_class.configure(
        api_key: "secret",
        base_url: "https://manager.example/api/v1/",
        team: "mobile-team",
        app: "customer-app"
      )

      expect(configuration.api_root).to eq("https://manager.example/api/v1")
    end

    it "supports a self-hosted instance mounted below a URL path" do
      configuration = described_class.configure(
        api_key: "secret",
        base_url: "https://example.net/internal/app-manager",
        team: "mobile-team",
        app: "customer-app"
      )

      expect(configuration.api_root).to eq("https://example.net/internal/app-manager/api/v1")
    end

    it "rejects missing values and invalid URLs" do
      expect do
        described_class.configure(api_key: "", base_url: "localhost:8000", team: "", app: "")
      end.to raise_error(Fastlane::AppManager::Error, /api_key/)

      expect do
        described_class.configure(api_key: "secret", base_url: "localhost:8000", team: "team", app: "app")
      end.to raise_error(Fastlane::AppManager::Error, /absolute HTTP/)

      expect do
        described_class.configure(
          api_key: "secret",
          base_url: "https://manager.example?token=secret",
          team: "team",
          app: "app"
        )
      end.to raise_error(Fastlane::AppManager::Error, /absolute HTTP/)

      expect do
        described_class.configure(
          api_key: "secret",
          base_url: "https://manager.example",
          team: "team",
          app: ""
        )
      end.to raise_error(Fastlane::AppManager::Error, /app/)
    end
  end
end
