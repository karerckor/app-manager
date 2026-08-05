require "spec_helper"
require "tmpdir"

RSpec.describe "App Manager actions" do
  before do
    allow(Fastlane::UI).to receive(:success)
  end

  it "configures the API without returning the API key" do
    result = Fastlane::Actions::AppManagerConfigureAction.run(
      api_key: "secret",
      base_url: "http://localhost:8000",
      team: "mobile-team",
      app: "customer-app"
    )

    expect(result).to be(true)
    expect(Fastlane::AppManager::Configuration.current.team).to eq("mobile-team")
    expect(Fastlane::AppManager::Configuration.current.app).to eq("customer-app")
  end

  it "downloads an icon to the user-selected path" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_asset).and_return("/tmp/icon.png")

    result = Fastlane::Actions::AppManagerDownloadIconAction.run(
      output_path: "/tmp/icon.png",
      overwrite: true
    )

    expect(result).to eq("/tmp/icon.png")
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_asset).with(
      asset: "icon",
      output_path: "/tmp/icon.png",
      overwrite: true
    )
  end

  it "reads overwrite from Fastlane configuration without Hash-only fetch arguments" do
    params = instance_double(FastlaneCore::Configuration)
    allow(params).to receive(:[]).with(:output_path).and_return("/tmp/icon.png")
    allow(params).to receive(:[]).with(:overwrite).and_return(true)
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_asset).and_return("/tmp/icon.png")

    Fastlane::Actions::AppManagerDownloadIconAction.run(params)

    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_asset).with(
      asset: "icon",
      output_path: "/tmp/icon.png",
      overwrite: true
    )
  end

  it "exposes action files to Fastlane's plugin loader" do
    action_files = Fastlane::AppManager.all_classes.select { |path| File.dirname(path).end_with?("/actions") }

    expect(action_files).to include(a_string_ending_with("app_manager_app_info_action.rb"))
    expect(action_files).to include(a_string_ending_with("app_manager_download_icon_action.rb"))
  end

  it "returns app info and exposes it through lane context" do
    info = {
      name: "Customer App",
      app_identifiers: [
        { platform: :ios, identifier: "com.example.ios", default: true }
      ],
      default_app_identifiers: {
        ios: { platform: :ios, identifier: "com.example.ios", default: true }
      }
    }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:app_info).and_return(info)

    result = Fastlane::Actions::AppManagerAppInfoAction.run({})

    expect(result).to eq(info)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_APP_INFO]
    ).to eq(info)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_APP_INFO] = nil
  end

  it "creates a build and shares its id with lifecycle actions" do
    build = { "id" => "build-1", "status" => "pending" }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:create_build).and_return(build)
    allow(Fastlane::Helper::AppManagerHelper).to receive(:update_build)
      .and_return(build.merge("status" => "running"))

    result = Fastlane::Actions::AppManagerCreateBuildAction.run(
      request_uuid: "request-1",
      profile_type: "production",
      app_identifier_id: nil,
      context: { commit: "abc" }
    )
    Fastlane::Actions::AppManagerBuildStartAction.run(build_id: nil)

    expect(result).to eq(build)
    expect(Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_ID]).to eq("build-1")
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:update_build).with(
      build_id: "build-1",
      status: "running"
    )
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_ID] = nil
  end

  it "appends a newline and reports a terminal build status" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:append_build_log).and_return("bytes" => 6)
    allow(Fastlane::Helper::AppManagerHelper).to receive(:update_build).and_return(
      "id" => "build-1",
      "status" => "failed"
    )

    Fastlane::Actions::AppManagerBuildLogAction.run(
      build_id: "build-1",
      message: "hello",
      newline: true
    )
    Fastlane::Actions::AppManagerBuildFinishAction.run(
      build_id: "build-1",
      status: "failed"
    )

    expect(Fastlane::Helper::AppManagerHelper).to have_received(:append_build_log).with(
      build_id: "build-1",
      content: "hello\n"
    )
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:update_build).with(
      build_id: "build-1",
      status: "failed"
    )
  end

  it "passes an iOS profile type to the typed credential downloader" do
    downloaded = {
      path: "/tmp/certificate.p12",
      payload: { "password" => "secret" }
    }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_credential).and_return(downloaded)

    result = Fastlane::Actions::AppManagerDownloadIosCertificateAction.run(
      app_identifier: nil,
      profile_type: "appstore",
      output_path: "/tmp/certificate.p12",
      credential_id: nil,
      credential_name: nil,
      overwrite: true
    )

    expect(result).to eq(downloaded)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_credential).with(
      type: "ios_certificate",
      app_identifier: nil,
      platform: "ios",
      profile_type: "appstore",
      credential_id: nil,
      credential_name: nil,
      output_path: "/tmp/certificate.p12",
      overwrite: true,
      allow_expired: false
    )
  end

  it "uses the current platform lane to select a Firebase app identifier" do
    downloaded = { path: "/tmp/firebase.json", payload: {} }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_credential).and_return(downloaded)
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::PLATFORM_NAME] = :android

    result = Fastlane::Actions::AppManagerDownloadFirebaseConfigAction.run(
      app_identifier: nil,
      output_path: "/tmp/firebase.json",
      credential_id: nil,
      credential_name: nil,
      overwrite: true
    )

    expect(result).to eq(downloaded)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_credential).with(
      type: "firebase_config",
      app_identifier: nil,
      platform: "android",
      profile_type: nil,
      credential_id: nil,
      credential_name: nil,
      output_path: "/tmp/firebase.json",
      overwrite: true,
      allow_expired: false
    )
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::PLATFORM_NAME] = nil
  end

  it "rejects a profile type from the other platform" do
    expect do
      Fastlane::Actions::AppManagerDownloadAndroidKeystoreAction.run(
        app_identifier: "com.example.android",
        profile_type: "appstore",
        output_path: "/tmp/release.jks"
      )
    end.to raise_error(FastlaneCore::Interface::FastlaneError, /profile_type/)
  end

  it "app_manager_add_artifact uploads an artifact to the resolved build" do
    Dir.mktmpdir do |dir|
      path = File.join(dir, "release.apk")
      File.binwrite(path, "APK")
      allow(Fastlane::Helper::AppManagerHelper).to receive(:resolve_build_id).with("build-1").and_return("build-1")
      allow(Fastlane::Helper::AppManagerHelper).to receive(:add_artifact).and_return(
        "id" => "artifact-1",
        "name" => "release.apk"
      )

      result = Fastlane::Actions::AppManagerAddArtifactAction.run(
        build_id: "build-1",
        file: path,
        name: "release.apk",
        metadata: { "git_sha" => "abc" }
      )

      expect(result).to eq("id" => "artifact-1", "name" => "release.apk")
      expect(Fastlane::Helper::AppManagerHelper).to have_received(:add_artifact).with(
        build_id: "build-1",
        file_path: path,
        name: "release.apk",
        metadata: { "git_sha" => "abc" }
      )
    end
  end

  it "app_manager_add_artifact defaults the artifact name to the file basename and resolves build_id from context" do
    Dir.mktmpdir do |dir|
      path = File.join(dir, "app-release.aab")
      File.binwrite(path, "AAB")
      allow(Fastlane::Helper::AppManagerHelper).to receive(:resolve_build_id).with(nil).and_return("build-2")
      allow(Fastlane::Helper::AppManagerHelper).to receive(:add_artifact).and_return(
        "id" => "artifact-2",
        "name" => "app-release.aab"
      )

      Fastlane::Actions::AppManagerAddArtifactAction.run(
        build_id: nil,
        file: path,
        name: nil,
        metadata: nil
      )

      expect(Fastlane::Helper::AppManagerHelper).to have_received(:add_artifact).with(
        build_id: "build-2",
        file_path: path,
        name: "app-release.aab",
        metadata: {}
      )
    end
  end

  it "app_manager_add_artifact raises when the artifact file does not exist" do
    expect do
      Fastlane::Actions::AppManagerAddArtifactAction.run(
        build_id: "build-1",
        file: "/nonexistent/path/release.apk",
        name: nil,
        metadata: nil
      )
    end.to raise_error(FastlaneCore::Interface::FastlaneError, /does not exist/)
  end

  it "lists builds and exposes them through lane context" do
    builds = [
      { "id" => "build-1", "status" => "completed", "profile_type" => "production" },
      { "id" => "build-2", "status" => "running", "profile_type" => "development" }
    ]
    allow(Fastlane::Helper::AppManagerHelper).to receive(:list_builds).and_return(builds)

    result = Fastlane::Actions::AppManagerListBuildsAction.run(status: nil, limit: nil)

    expect(result).to eq(builds)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILDS]
    ).to eq(builds)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILDS] = nil
  end

  it "lists builds with status filter" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:list_builds).and_return([])

    Fastlane::Actions::AppManagerListBuildsAction.run(status: "failed", limit: 10)

    expect(Fastlane::Helper::AppManagerHelper).to have_received(:list_builds).with(
      status: "failed",
      limit: 10
    )
  end

  it "shows build details and exposes them through lane context" do
    build = { "id" => "build-1", "status" => "running", "profile_type" => "production" }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:resolve_build_id).with(nil).and_return("build-1")
    allow(Fastlane::Helper::AppManagerHelper).to receive(:build).with("build-1").and_return(build)

    result = Fastlane::Actions::AppManagerShowBuildAction.run(build_id: nil)

    expect(result).to eq(build)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_INFO]
    ).to eq(build)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_INFO] = nil
  end

  it "cancels a build" do
    build = { "id" => "build-1", "status" => "cancel_requested" }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:resolve_build_id).with("build-1").and_return("build-1")
    allow(Fastlane::Helper::AppManagerHelper).to receive(:cancel_build).with("build-1").and_return(build)

    result = Fastlane::Actions::AppManagerCancelBuildAction.run(build_id: "build-1")

    expect(result).to eq(build)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:cancel_build).with("build-1")
  end

  it "downloads an artifact" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:resolve_build_id).with(nil).and_return("build-1")
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_artifact).and_return("/tmp/release.apk")

    result = Fastlane::Actions::AppManagerDownloadArtifactAction.run(
      artifact_id: "artifact-1",
      output_path: "/tmp/release.apk",
      build_id: nil,
      overwrite: true
    )

    expect(result).to eq("/tmp/release.apk")
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_artifact).with(
      build_id: "build-1",
      artifact_id: "artifact-1",
      output_path: "/tmp/release.apk",
      overwrite: true
    )
  end

  it "download artifact requires artifact_id" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:resolve_build_id).with(nil).and_return("build-1")

    expect do
      Fastlane::Actions::AppManagerDownloadArtifactAction.run(
        artifact_id: "",
        output_path: "/tmp/release.apk",
        build_id: nil
      )
    end.to raise_error(FastlaneCore::Interface::FastlaneError, /artifact_id/)
  end

  it "lists credentials and exposes them through lane context" do
    credentials = [
      { "id" => "cert-1", "type" => "ios_certificate", "name" => "App Store", "payload" => { "password" => "secret" } },
      { "id" => "keystore-1", "type" => "android_keystore", "name" => "Production" }
    ]
    allow(Fastlane::Helper::AppManagerHelper).to receive(:list_credentials).and_return(credentials)

    result = Fastlane::Actions::AppManagerListCredentialsAction.run({})

    expect(result).to eq(credentials)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_CREDENTIALS]
    ).to eq([
      { "id" => "cert-1", "type" => "ios_certificate", "name" => "App Store" },
      { "id" => "keystore-1", "type" => "android_keystore", "name" => "Production" }
    ])
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_CREDENTIALS] = nil
  end

  it "lists identifiers and exposes them through lane context" do
    identifiers = [
      { "id" => "ios-1", "platform" => "ios", "identifier" => "com.example.ios", "name" => "iOS" },
      { "id" => "android-1", "platform" => "android", "identifier" => "com.example.android", "name" => "Android" }
    ]
    allow(Fastlane::Helper::AppManagerHelper).to receive(:list_identifiers).and_return(identifiers)

    result = Fastlane::Actions::AppManagerListIdentifiersAction.run({})

    expect(result).to eq(identifiers)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_IDENTIFIERS]
    ).to eq(identifiers)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_IDENTIFIERS] = nil
  end

  it "exposes all actions including new ones" do
    runner = Fastlane::Runner.new
    action_names = %i[
      app_manager_configure
      app_manager_app_info
      app_manager_create_build
      app_manager_build_start
      app_manager_build_log
      app_manager_build_finish
      app_manager_add_artifact
      app_manager_list_builds
      app_manager_show_build
      app_manager_cancel_build
      app_manager_download_artifact
      app_manager_list_credentials
      app_manager_list_identifiers
      app_manager_download_icon
      app_manager_download_splashscreen
      app_manager_download_ios_certificate
      app_manager_download_provisioning_profile
      app_manager_download_android_keystore
      app_manager_download_firebase_config
      app_manager_download_apns_key
      app_manager_download_google_play_service_account
      app_manager_download_apple_api_key
    ]

    action_names.each do |action_name|
      expect(runner.class_reference_from_action_name(action_name)).not_to be_nil
    end

    configure_keys = Fastlane::Actions::AppManagerConfigureAction.available_options.map(&:key)
    credential_keys = Fastlane::Actions::AppManagerDownloadIosCertificateAction.available_options.map(&:key)
    asset_keys = Fastlane::Actions::AppManagerDownloadIconAction.available_options.map(&:key)
    expect(configure_keys).to include(:app, :connect_timeout, :read_timeout, :max_retries)
    expect(credential_keys).not_to include(:app)
    expect(asset_keys).not_to include(:app)
  end

  it "lists app files and exposes them through lane context" do
    files = [{ "name" => "release-notes.pdf" }]
    allow(Fastlane::Helper::AppManagerHelper).to receive(:list_files).and_return(files)

    result = Fastlane::Actions::AppManagerListFilesAction.run({})

    expect(result).to eq(files)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_FILES]
    ).to eq(files)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_FILES] = nil
  end

  it "uploads an app file with metadata" do
    file = { "name" => "release-notes.pdf" }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:upload_file).and_return(file)

    result = Fastlane::Actions::AppManagerUploadFileAction.run(
      file_path: "/tmp/release-notes.pdf",
      metadata: { "channel" => "beta" }
    )

    expect(result).to eq(file)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:upload_file).with(
      file_path: "/tmp/release-notes.pdf",
      metadata: { "channel" => "beta" }
    )
  end

  it "downloads an app file to the requested path" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_file).and_return("/tmp/notes.pdf")

    result = Fastlane::Actions::AppManagerDownloadFileAction.run(
      name: "release-notes.pdf",
      output_path: "/tmp/notes.pdf",
      overwrite: true
    )

    expect(result).to eq("/tmp/notes.pdf")
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_file).with(
      name: "release-notes.pdf",
      output_path: "/tmp/notes.pdf",
      overwrite: true
    )
  end

  it "deletes an app file by name" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:delete_file).and_return(true)

    expect(Fastlane::Actions::AppManagerDeleteFileAction.run(name: "release-notes.pdf")).to be(true)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:delete_file).with(
      name: "release-notes.pdf"
    )
  end
end
