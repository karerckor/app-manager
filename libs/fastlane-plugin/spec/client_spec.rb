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
            "metadata" => { "owner" => "mobile-team", "channel" => "production" },
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
      expect(info[:metadata]).to eq("owner" => "mobile-team", "channel" => "production")
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
      allow(client).to receive(:json_request).and_return(
        "data" => { "payload" => { "password" => "p12-secret" } }
      )
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

  describe "manual builds" do
    it "creates a build through the app-scoped endpoint" do
      response = Struct.new(:body).new(JSON.generate(
        "data" => { "id" => "build-1", "status" => "pending" }
      ))
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/builds",
        method: :post,
        body: {
          request_uuid: "request-1",
          profile_type: "production",
          context: { commit: "abc" }
        }
      ).and_return(response)

      build = client.create_build(
        request_uuid: "request-1",
        profile_type: "production",
        context: { commit: "abc" }
      )

      expect(build).to include("id" => "build-1", "status" => "pending")
    end

    it "updates status and appends exact log text" do
      status_response = Struct.new(:body).new(JSON.generate(
        "data" => { "id" => "build-1", "status" => "running" }
      ))
      log_response = Struct.new(:body).new(JSON.generate(
        "data" => { "build_id" => "build-1", "bytes" => 6 }
      ))
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-1",
        method: :patch,
        body: { status: "running" }
      ).and_return(status_response)
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-1/logs",
        method: :post,
        body: { content: "hello\n" }
      ).and_return(log_response)

      expect(client.update_build(build_id: "build-1", status: "running")["status"]).to eq("running")
      expect(client.append_build_log(build_id: "build-1", content: "hello\n")["bytes"]).to eq(6)
    end
  end

  describe "#upload_artifact" do
    it "posts a multipart request to the build artifacts endpoint" do
      Dir.mktmpdir do |dir|
        path = File.join(dir, "release.apk")
        File.binwrite(path, "APKBINARY")
        response = Struct.new(:body).new(JSON.generate(
          "data" => { "id" => "artifact-1", "name" => "release.apk" }
        ))

        expect(client).to receive(:request).with(
          "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-1/artifacts",
          method: :post,
          body: an_instance_of(String),
          content_type: a_string_matching(%r{\Amultipart/form-data; boundary=})
        ) do |_path, method:, body:, content_type:|
          expect(body).to include("release.apk")
          expect(body).to include("APKBINARY")
          expect(body).to include("git_sha")
          response
        end

        artifact = client.upload_artifact(
          build_id: "build-1",
          file_path: path,
          name: "release.apk",
          metadata: { "git_sha" => "abc" }
        )

        expect(artifact).to include("id" => "artifact-1", "name" => "release.apk")
      end
    end
  end

  describe "#list_builds" do
    it "fetches builds from the app endpoint" do
      builds = [
        { "id" => "build-1", "status" => "completed" },
        { "id" => "build-2", "status" => "running" }
      ]
      allow(client).to receive(:get_json).and_return("data" => builds)

      result = client.list_builds

      expect(result.length).to eq(2)
      expect(result.first["id"]).to eq("build-1")
    end

    it "passes status and limit query parameters" do
      allow(client).to receive(:get_json).and_return("data" => [])

      expect(client).to receive(:get_json).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/builds?status=failed&limit=5"
      ).and_return("data" => [])

      client.list_builds(status: "failed", limit: 5)
    end
  end

  describe "#cancel_build" do
    it "posts to the cancel endpoint" do
      response = Struct.new(:body).new(JSON.generate(
        "data" => { "id" => "build-1", "status" => "cancel_requested" }
      ))
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/builds/build-1/cancel",
        method: :post,
        body: {}
      ).and_return(response)

      result = client.cancel_build("build-1")

      expect(result["status"]).to eq("cancel_requested")
    end
  end

  describe "#download_artifact" do
    it "downloads an artifact file to the requested path" do
      response = Struct.new(:body).new("artifact-bytes")
      allow(client).to receive(:request).and_return(response)

      Dir.mktmpdir do |directory|
        output = File.join(directory, "release.apk")
        result = client.download_artifact(
          build_id: "build-1",
          artifact_id: "artifact-1",
          output_path: output
        )

        expect(result).to eq(output)
        expect(File.binread(output)).to eq("artifact-bytes")
      end
    end
  end

  describe "#list_credentials" do
    it "fetches credentials from the app endpoint" do
      allow(client).to receive(:get_json).and_return("data" => credentials)

      result = client.list_credentials

      expect(result.length).to eq(2)
      expect(result.first["type"]).to eq("ios_certificate")
    end
  end

  describe "#list_identifiers" do
    it "fetches identifiers from the app endpoint" do
      identifiers = [
        { "id" => "ios-1", "platform" => "ios", "identifier" => "com.example.ios" },
        { "id" => "android-1", "platform" => "android", "identifier" => "com.example.android" }
      ]
      allow(client).to receive(:get_json).and_return("data" => identifiers)

      result = client.list_identifiers

      expect(result.length).to eq(2)
      expect(result.first["platform"]).to eq("ios")
    end
  end

  describe "retry behavior" do
    it "retries on 429 responses" do
      retry_response = Net::HTTPTooManyRequests.new("1.1", "429", "Too Many Requests")
      retry_response.instance_variable_set(:@read, true)
      retry_response.instance_variable_set(:@body, "")

      ok_response = Net::HTTPOK.new("1.1", "200", "OK")
      ok_response.instance_variable_set(:@read, true)
      ok_response.instance_variable_set(:@body, JSON.generate("data" => { "id" => "app-1", "name" => "Test" }))

      http = instance_double(Net::HTTP)
      allow(Net::HTTP).to receive(:new).and_return(http)
      allow(http).to receive(:use_ssl=)
      allow(http).to receive(:open_timeout=)
      allow(http).to receive(:read_timeout=)
      allow(http).to receive(:request).and_return(retry_response, ok_response)
      allow(client).to receive(:sleep)

      result = client.send(:get_json, "https://manager.example/api/v1/mobile-team/apps/customer-app")

      expect(result).to include("data")
      expect(http).to have_received(:request).twice
    end

    it "retries on network errors" do
      ok_response = Net::HTTPOK.new("1.1", "200", "OK")
      ok_response.instance_variable_set(:@read, true)
      ok_response.instance_variable_set(:@body, JSON.generate("data" => []))

      http = instance_double(Net::HTTP)
      allow(Net::HTTP).to receive(:new).and_return(http)
      allow(http).to receive(:use_ssl=)
      allow(http).to receive(:open_timeout=)
      allow(http).to receive(:read_timeout=)
      call_count = 0
      allow(http).to receive(:request) do
        call_count += 1
        raise Errno::ECONNREFUSED if call_count == 1

        ok_response
      end
      allow(client).to receive(:sleep)

      result = client.send(:get_json, "https://manager.example/api/v1/mobile-team/apps/customer-app")

      expect(result).to include("data")
      expect(http).to have_received(:request).twice
    end
  end

  describe "#list_files" do
    it "fetches files from the app files endpoint" do
      files = [{ "name" => "release-notes.pdf" }, { "name" => "mapping.txt" }]
      expect(client).to receive(:get_json).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/files"
      ).and_return("data" => files)

      expect(client.list_files.length).to eq(2)
    end

    it "raises when the payload is not a list" do
      allow(client).to receive(:get_json).and_return("data" => {})

      expect { client.list_files }.to raise_error(Fastlane::AppManager::Error, /invalid files response/)
    end
  end

  describe "#upload_file" do
    it "posts a multipart request to the app files endpoint" do
      Dir.mktmpdir do |dir|
        path = File.join(dir, "release-notes.pdf")
        File.binwrite(path, "PDFBYTES")
        response = Struct.new(:body).new(JSON.generate(
          "data" => { "name" => "release-notes.pdf", "size" => 8 }
        ))

        expect(client).to receive(:request).with(
          "https://manager.example/api/v1/mobile-team/apps/customer-app/files",
          method: :post,
          body: an_instance_of(String),
          content_type: a_string_matching(%r{\Amultipart/form-data; boundary=})
        ) do |_path, method:, body:, content_type:|
          expect(body).to include("release-notes.pdf")
          expect(body).to include("PDFBYTES")
          expect(body).to include("channel")
          response
        end

        file = client.upload_file(file_path: path, metadata: { "channel" => "beta" })

        expect(file).to include("name" => "release-notes.pdf")
      end
    end
  end

  describe "#download_file" do
    it "downloads the file content to the requested path" do
      response = Struct.new(:body).new("pdf-bytes")
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/files/release-notes.pdf/content"
      ).and_return(response)

      Dir.mktmpdir do |directory|
        output = File.join(directory, "release-notes.pdf")

        expect(client.download_file(name: "release-notes.pdf", output_path: output)).to eq(output)
        expect(File.binread(output)).to eq("pdf-bytes")
      end
    end
  end

  describe "#delete_file" do
    it "deletes the file by name" do
      response = Struct.new(:body).new("")
      expect(client).to receive(:request).with(
        "https://manager.example/api/v1/mobile-team/apps/customer-app/files/release-notes.pdf",
        method: :delete,
        body: nil
      ).and_return(response)

      expect(client.delete_file(name: "release-notes.pdf")).to be(true)
    end
  end
end
