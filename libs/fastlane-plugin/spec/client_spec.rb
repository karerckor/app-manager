require "spec_helper"
require "tmpdir"

RSpec.describe Fastlane::AppManager::Client do
  let(:configuration) do
    Fastlane::AppManager::Configuration.new(
      api_key: "api-secret",
      base_url: "https://manager.example",
      team: "mobile-team",
      app: "customer-app"
    )
  end
  let(:client) { described_class.new(configuration) }

  let(:credentials) do
    [
      {
        "id" => "certificate-1",
        "type" => "ios_certificate",
        "name" => "App Store certificate",
        "payload" => { "password" => "p12-secret" },
        "profiles" => [
          {
            "type" => "appstore",
            "app_identifier" => { "identifier" => "com.example.ios" }
          }
        ],
        "app_identifiers" => []
      },
      {
        "id" => "play-1",
        "type" => "google_play_service_account",
        "name" => "Play submission",
        "payload" => nil,
        "profiles" => [],
        "app_identifiers" => [
          { "identifier" => "com.example.android" }
        ]
      }
    ]
  end

  describe "#app_info" do
    it "combines app metadata with identifiers and marks the first per platform as default" do
      allow(client).to receive(:get_json).and_return(
        {
          "data" => {
            "id" => "app-1",
            "slug" => "customer-app",
            "name" => "Customer App",
            "description" => "White-label customer app",
            "assets" => { "icon" => { "name" => "icon.png" } },
            "created_at" => "2026-07-14T00:00:00Z",
            "updated_at" => "2026-07-14T01:00:00Z"
          }
        },
        {
          "data" => [
            { "id" => "ios-1", "platform" => "ios", "identifier" => "com.example.ios", "name" => "iOS", "version" => "2.3.0" },
            { "id" => "android-1", "platform" => "android", "identifier" => "com.example.android", "name" => "Android", "version" => "2.3.0", "version_code" => 20300 },
            { "id" => "ios-2", "platform" => "ios", "identifier" => "com.example.ios.beta", "name" => "iOS Beta" },
            { "id" => "android-2", "platform" => "android", "identifier" => "com.example.android.beta", "name" => "Android Beta" }
          ]
        }
      )

      info = client.app_info

      expect(info[:name]).to eq("Customer App")
      expect(info[:app_identifiers].map { |identifier| identifier[:default] }).to eq([true, true, false, false])
      expect(info[:default_app_identifiers][:ios][:identifier]).to eq("com.example.ios")
      expect(info[:default_app_identifiers][:android][:identifier]).to eq("com.example.android")
      expect(info[:default_app_identifiers][:ios][:version]).to eq("2.3.0")
      expect(info[:default_app_identifiers][:android][:version_code]).to eq(20_300)
    end
  end

  describe "#find_credential" do
    before do
      allow(client).to receive(:get_json).and_return("data" => credentials)
    end

    it "selects profile-scoped signing credentials" do
      credential = client.find_credential(
        type: "ios_certificate",
        app_identifier: "com.example.ios",
        profile_type: "appstore"
      )

      expect(credential["id"]).to eq("certificate-1")
    end

    it "selects identifier-scoped service credentials" do
      credential = client.find_credential(
        type: "google_play_service_account",
        app_identifier: "com.example.android"
      )

      expect(credential["id"]).to eq("play-1")
    end

    it "fails when a match is ambiguous" do
      allow(client).to receive(:get_json).and_return(
        "data" => [credentials.last, credentials.last.merge("id" => "play-2", "name" => "Play backup")]
      )

      expect do
        client.find_credential(
          type: "google_play_service_account",
          app_identifier: "com.example.android"
        )
      end.to raise_error(Fastlane::AppManager::Error, /credential_id or credential_name/)
    end

    it "supports explicit credential selection" do
      allow(client).to receive(:get_json).and_return(
        "data" => [credentials.last, credentials.last.merge("id" => "play-2", "name" => "Play backup")]
      )

      credential = client.find_credential(
        type: "google_play_service_account",
        app_identifier: "com.example.android",
        credential_id: "play-2"
      )

      expect(credential["name"]).to eq("Play backup")
    end

    it "uses the first app identifier for the requested platform when none is provided" do
      identifiers = [
        { "platform" => "android", "identifier" => "com.example.android" },
        { "platform" => "ios", "identifier" => "com.example.ios" },
        { "platform" => "ios", "identifier" => "com.example.second-ios" }
      ]
      allow(client).to receive(:get_json).and_return(
        { "data" => identifiers },
        { "data" => credentials }
      )

      credential = client.find_credential(
        type: "ios_certificate",
        platform: "ios",
        profile_type: "appstore"
      )

      expect(credential["id"]).to eq("certificate-1")
    end
  end

  describe "downloads" do
    it "sends authenticated requests to the team-scoped API endpoint" do
      response = Net::HTTPOK.new("1.1", "200", "OK")
      response.instance_variable_set(:@read, true)
      response.instance_variable_set(:@body, "image-bytes")
      http = instance_double(Net::HTTP)
      allow(Net::HTTP).to receive(:new).with("manager.example", 443).and_return(http)
      allow(http).to receive(:use_ssl=).with(true)
      allow(http).to receive(:open_timeout=).with(10)
      allow(http).to receive(:read_timeout=).with(60)
      allow(http).to receive(:request) do |request|
        expect(request.path).to eq("/api/v1/mobile-team/apps/customer-app/assets/icon")
        expect(request["Authorization"]).to eq("Bearer api-secret")
        expect(request["Accept"]).to eq("application/json")
        response
      end

      Dir.mktmpdir do |directory|
        client.download_asset(
          asset: "icon",
          output_path: File.join(directory, "icon.png")
        )
      end
    end

    it "writes assets to the exact requested path and creates parent directories" do
      response = Struct.new(:body).new("image-bytes")
      allow(client).to receive(:request).and_return(response)

      Dir.mktmpdir do |directory|
        output = File.join(directory, "nested", "icon.png")
        result = client.download_asset(
          asset: "icon",
          output_path: output
        )

        expect(result).to eq(output)
        expect(File.binread(output)).to eq("image-bytes")
        expect(File.stat(output).mode & 0o777).to eq(0o644)
      end
    end

    it "returns credential metadata and protects downloaded credential files" do
      response = Struct.new(:body).new("certificate-bytes")
      allow(client).to receive(:get_json).and_return("data" => credentials)
      allow(client).to receive(:request).and_return(response)

      Dir.mktmpdir do |directory|
        output = File.join(directory, "certificate.p12")
        result = client.download_credential(
          type: "ios_certificate",
          app_identifier: "com.example.ios",
          profile_type: "appstore",
          output_path: output
        )

        expect(result[:path]).to eq(output)
        expect(result[:payload]).to eq("password" => "p12-secret")
        expect(result[:app_identifier]).to eq("com.example.ios")
        expect(File.stat(output).mode & 0o777).to eq(0o600)
      end
    end

    it "does not overwrite files when overwrite is false" do
      Dir.mktmpdir do |directory|
        output = File.join(directory, "existing.json")
        File.write(output, "keep-me")

        expect do
          client.download_asset(
            asset: "icon",
            output_path: output,
            overwrite: false
          )
        end.to raise_error(Fastlane::AppManager::Error, /Refusing to overwrite/)
        expect(File.read(output)).to eq("keep-me")
      end
    end
  end
end
