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
            { "id" => "ios-1", "platform" => "ios", "identifier" => "com.example.ios", "name" => "iOS", "version" => "2.3.0", "is_default" => false },
            { "id" => "android-1", "platform" => "android", "identifier" => "com.example.android", "name" => "Android", "version" => "2.3.0", "version_code" => 20300, "is_default" => true },
            { "id" => "ios-2", "platform" => "ios", "identifier" => "com.example.ios.beta", "name" => "iOS Beta", "build_number" => "42", "is_default" => true },
            { "id" => "android-2", "platform" => "android", "identifier" => "com.example.android.beta", "name" => "Android Beta", "is_default" => false }
          ]
        }
      )

      info = client.app_info

      expect(info[:name]).to eq("Customer App")
      expect(info[:app_identifiers].map { |identifier| identifier[:default] }).to eq([false, true, true, false])
      expect(info[:default_app_identifiers][:ios][:identifier]).to eq("com.example.ios.beta")
      expect(info[:default_app_identifiers][:android][:identifier]).to eq("com.example.android")
      expect(info[:default_app_identifiers][:ios][:build_number]).to eq("42")
      expect(info[:default_app_identifiers][:android][:version_code]).to eq(20_300)
    end

    it "keeps legacy first-per-platform defaults when the API has no explicit default field" do
      allow(client).to receive(:get_json).and_return(
        { "data" => { "name" => "Legacy App" } },
        {
          "data" => [
            { "platform" => "ios", "identifier" => "com.example.ios" },
            { "platform" => "ios", "identifier" => "com.example.ios.beta" }
          ]
        }
      )

      info = client.app_info

      expect(info[:default_app_identifiers][:ios][:identifier]).to eq("com.example.ios")
    end
  end

  describe "#build_context" do
    it "loads and normalizes the versioned API contract" do
      path = "https://manager.example/api/v1/mobile-team/apps/customer-app/build-context?" \
             "platform=ios&profile_type=appstore&identifier=com.example.ios"
      fixture_path = File.expand_path("../../../contracts/api-v1/build-context-v1.json", __dir__)
      fixture = JSON.parse(File.read(fixture_path))
      allow(client).to receive(:get_json).with(path).and_return(fixture)

      context = client.build_context(
        platform: "ios",
        profile_type: "appstore",
        app_identifier: "com.example.ios"
      )

      expect(context[:schema_version]).to eq(1)
      expect(context[:app][:name]).to eq("Customer App")
      expect(context[:target][:platform]).to eq(:ios)
      expect(context[:target][:build_number]).to eq("42")
      expect(context[:profile][:value]).to eq("appstore")
      expect(context[:credentials].first["id"]).to eq("certificate-1")
    end

    it "rejects unsupported schema versions" do
      allow(client).to receive(:get_json).and_return(
        "data" => { "schema_version" => 2 }
      )

      expect do
        client.build_context(platform: "android", profile_type: "production")
      end.to raise_error(Fastlane::AppManager::Error, /unsupported build context schema/)
    end
  end

  describe "build lifecycle" do
    let(:build_response) do
      {
        "id" => "build-uuid",
        "external_id" => "customer-build-42",
        "status" => "pending",
        "context_snapshot" => {
          "target" => {
            "id" => "target-1",
            "platform" => "android",
            "identifier" => "com.example.android",
            "version" => "2.3.0",
            "version_code" => 20_300
          },
          "profile" => {
            "id" => "profile-1",
            "value" => "production",
            "platform" => "android",
            "artifact" => ".aab"
          }
        },
        "ci" => {},
        "last_log_sequence" => 7
      }
    end

    it "resolves an external build and exposes its immutable target versions" do
      path = "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/external/customer-build-42"
      allow(client).to receive(:request_json).with(:get, path).and_return("data" => build_response)

      build = client.build(external_id: "customer-build-42")

      expect(build[:status]).to eq(:pending)
      expect(build[:target][:identifier]).to eq("com.example.android")
      expect(build[:target][:version]).to eq("2.3.0")
      expect(build[:target][:version_code]).to eq(20_300)
      expect(build[:last_log_sequence]).to eq(7)
    end

    it "atomically starts a pending build with CI identity" do
      lookup_path = "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/external/customer-build-42"
      start_path = "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-uuid/start"
      allow(client).to receive(:request_json).with(:get, lookup_path).and_return("data" => build_response)
      allow(client).to receive(:request_json).with(
        :post,
        start_path,
        {
          ci_provider: "github",
          ci_run_id: "12345",
          ci_run_url: "https://github.example/runs/12345"
        }
      ).and_return("data" => build_response.merge("status" => "running"))

      build = client.start_build(
        external_id: "customer-build-42",
        ci_provider: "github",
        ci_run_id: "12345",
        ci_run_url: "https://github.example/runs/12345"
      )

      expect(build[:status]).to eq(:running)
    end

    it "does not send a start command when the build was cancelled" do
      allow(client).to receive(:request_json).and_return(
        "data" => build_response.merge("status" => "cancelled")
      )

      build = client.start_build(
        external_id: "customer-build-42",
        ci_provider: "github",
        ci_run_id: "12345"
      )

      expect(build[:cancelled]).to be(true)
      expect(client).to have_received(:request_json).once
    end

    it "posts idempotent sequenced log batches" do
      entries = [{ sequence: 8, stream: "stdout", message: "Compiling" }]
      path = "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-uuid/logs"
      allow(client).to receive(:request_json).with(
        :post,
        path,
        entries: entries
      ).and_return("data" => { "accepted" => 1, "last_sequence" => 8 })

      result = client.append_build_logs(build_id: "build-uuid", entries: entries)

      expect(result).to eq(accepted: 1, last_sequence: 8)
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

    it "ignores expired candidates when a current credential exists" do
      allow(client).to receive(:get_json).and_return(
        "data" => [
          credentials.last.merge("id" => "play-expired", "expires_at" => "2020-01-01T00:00:00Z"),
          credentials.last.merge("id" => "play-current", "expires_at" => "2999-01-01T00:00:00Z")
        ]
      )

      credential = client.find_credential(
        type: "google_play_service_account",
        app_identifier: "com.example.android"
      )

      expect(credential["id"]).to eq("play-current")
    end

    it "rejects an expired selected credential by default" do
      allow(client).to receive(:get_json).and_return(
        "data" => [credentials.last.merge("expires_at" => "2020-01-01T00:00:00Z")]
      )

      expect do
        client.find_credential(
          type: "google_play_service_account",
          app_identifier: "com.example.android"
        )
      end.to raise_error(Fastlane::AppManager::Error, /credentials are expired/)
    end

    it "can explicitly allow an expired credential" do
      allow(client).to receive(:get_json).and_return(
        "data" => [credentials.last.merge("expires_at" => "2020-01-01T00:00:00Z")]
      )

      credential = client.find_credential(
        type: "google_play_service_account",
        app_identifier: "com.example.android",
        allow_expired: true
      )

      expect(credential["id"]).to eq("play-1")
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

    it "uses the declared platform default when none is provided" do
      identifiers = [
        { "platform" => "ios", "identifier" => "com.example.first", "is_default" => false },
        { "platform" => "ios", "identifier" => "com.example.ios", "is_default" => true }
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

    it "requires an explicit identifier when multiple targets have no default" do
      identifiers = [
        { "platform" => "ios", "identifier" => "com.example.first", "is_default" => false },
        { "platform" => "ios", "identifier" => "com.example.second", "is_default" => false }
      ]
      allow(client).to receive(:get_json).and_return("data" => identifiers)

      expect do
        client.find_credential(
          type: "ios_certificate",
          platform: "ios",
          profile_type: "appstore"
        )
      end.to raise_error(Fastlane::AppManager::Error, /set app_identifier explicitly/)
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

    it "sends JSON lifecycle commands" do
      response = Net::HTTPOK.new("1.1", "200", "OK")
      response.instance_variable_set(:@read, true)
      response.instance_variable_set(:@body, '{"data":{}}')
      http = instance_double(Net::HTTP)
      allow(Net::HTTP).to receive(:new).with("manager.example", 443).and_return(http)
      allow(http).to receive(:use_ssl=).with(true)
      allow(http).to receive(:open_timeout=).with(10)
      allow(http).to receive(:read_timeout=).with(60)
      allow(http).to receive(:request) do |request|
        expect(request).to be_a(Net::HTTP::Patch)
        expect(request["Authorization"]).to eq("Bearer api-secret")
        expect(request["Content-Type"]).to eq("application/json")
        expect(JSON.parse(request.body)).to eq("status" => "completed")
        response
      end

      client.send(
        :request_json,
        :patch,
        "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-uuid",
        status: "completed"
      )
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

  describe "request caching" do
    it "reuses parsed JSON responses within one client" do
      response = Struct.new(:body).new('{"data":[]}')
      allow(client).to receive(:request).and_return(response)
      path = "https://manager.example/api/v1/mobile-team/apps/customer-app/identifiers"

      2.times { client.send(:get_json, path) }

      expect(client).to have_received(:request).once
    end
  end
end
