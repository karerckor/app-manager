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

    it "rejects plaintext HTTP for non-loopback hosts" do
      expect do
        described_class.configure(
          api_key: "secret",
          base_url: "http://manager.example",
          team: "mobile-team",
          app: "customer-app"
        )
      end.to raise_error(Fastlane::AppManager::Error, /must use HTTPS/)
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

    it "rejects invalid slug formats" do
      expect do
        described_class.configure(
          api_key: "secret",
          base_url: "https://manager.example",
          team: "Invalid Team!",
          app: "customer-app"
        )
      end.to raise_error(Fastlane::AppManager::Error, /team must be a lowercase slug/)

      expect do
        described_class.configure(
          api_key: "secret",
          base_url: "https://manager.example",
          team: "mobile-team",
          app: "UPPERCASE"
        )
      end.to raise_error(Fastlane::AppManager::Error, /app must be a lowercase slug/)
    end

    it "accepts custom timeout and retry settings" do
      configuration = described_class.configure(
        api_key: "secret",
        base_url: "https://manager.example",
        team: "mobile-team",
        app: "customer-app",
        connect_timeout: 30,
        read_timeout: 120,
        max_retries: 5
      )

      expect(configuration.connect_timeout).to eq(30)
      expect(configuration.read_timeout).to eq(120)
      expect(configuration.max_retries).to eq(5)
    end

    it "uses default timeout and retry settings" do
      configuration = described_class.configure(
        api_key: "secret",
        base_url: "https://manager.example",
        team: "mobile-team",
        app: "customer-app"
      )

      expect(configuration.connect_timeout).to eq(10)
      expect(configuration.read_timeout).to eq(60)
      expect(configuration.max_retries).to eq(3)
    end
  end
end
