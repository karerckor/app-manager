require "spec_helper"

RSpec.describe "App Manager actions" do
  before do
    allow(Fastlane::UI).to receive(:success)
  end

  it "exposes action files to Fastlane's plugin manager" do
    action_files = Fastlane::AppManager.all_classes.select do |path|
      File.dirname(path).end_with?("/actions")
    end

    expect(action_files).to include(
      a_string_ending_with("/app_manager_build_context_action.rb"),
      a_string_ending_with("/app_manager_build_start_action.rb"),
      a_string_ending_with("/app_manager_build_log_action.rb"),
      a_string_ending_with("/app_manager_download_google_services_json_action.rb")
    )
  end

  it "claims an external build and exposes its versions through lane context" do
    build = {
      id: "build-uuid",
      external_id: "customer-build-42",
      status: :running,
      cancelled: false,
      last_log_sequence: 7,
      target: {
        identifier: "com.example.android",
        version: "2.3.0",
        version_code: 20_300
      }
    }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:start_build).and_return(build)

    result = Fastlane::Actions::AppManagerBuildStartAction.run(
      external_id: "customer-build-42",
      ci_provider: "github",
      ci_run_id: "12345",
      ci_run_url: nil
    )

    expect(result.dig(:target, :version_code)).to eq(20_300)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:start_build).with(
      external_id: "customer-build-42",
      ci_provider: "github",
      ci_run_id: "12345",
      ci_run_url: nil
    )
    expect(Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD]).to eq(build)
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_LOG_SEQUENCE]
    ).to eq(7)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD] = nil
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_LOG_SEQUENCE] = nil
  end

  it "stops before build work when App Manager already cancelled the external build" do
    allow(Fastlane::Helper::AppManagerHelper).to receive(:start_build).and_return(
      id: "build-uuid",
      external_id: "cancelled-build",
      cancelled: true
    )

    expect do
      Fastlane::Actions::AppManagerBuildStartAction.run(
        external_id: "cancelled-build",
        ci_provider: "github",
        ci_run_id: "12345",
        ci_run_url: nil
      )
    end.to raise_error(FastlaneCore::Interface::FastlaneError, /was cancelled/)
  end

  it "appends logs with a monotonic sequence for the current build" do
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD] = {
      id: "build-uuid"
    }
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_LOG_SEQUENCE] = 7
    allow(Fastlane::Helper::AppManagerHelper).to receive(:append_build_logs).and_return(
      accepted: 1,
      last_sequence: 8
    )

    result = Fastlane::Actions::AppManagerBuildLogAction.run(
      message: "Compiling",
      stream: "stdout",
      level: "info",
      build_id: nil,
      sequence: nil
    )

    expect(result[:last_sequence]).to eq(8)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:append_build_logs) do |options|
      expect(options[:build_id]).to eq("build-uuid")
      expect(options[:entries].first).to include(sequence: 8, message: "Compiling")
    end
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD] = nil
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_LOG_SEQUENCE] = nil
  end

  it "runs a shell build with snapshot versions and reports completion" do
    build = {
      id: "build-uuid",
      external_id: "customer-build-42",
      target: {
        platform: :android,
        identifier: "com.example.android",
        version: "2.3.0",
        version_code: 20_300,
        build_number: nil
      },
      profile: { value: "production" }
    }
    terminal = build.merge(status: :completed)
    runner = instance_double(Fastlane::AppManager::BuildRunner)
    configuration = instance_double(Fastlane::AppManager::Configuration, api_key: "api-secret")
    allow(Fastlane::Actions::AppManagerBuildStartAction).to receive(:run).and_return(build)
    allow(Fastlane::AppManager::Configuration).to receive(:current).and_return(configuration)
    allow(Fastlane::AppManager::BuildRunner).to receive(:new).and_return(runner)
    allow(runner).to receive(:run).with(command: "./gradlew bundleRelease").and_return(
      cancelled: false,
      success: true,
      exit_status: 0
    )
    allow(Fastlane::Helper::AppManagerHelper).to receive(:finish_build).and_return(terminal)

    result = Fastlane::Actions::AppManagerRunBuildAction.run(
      external_id: "customer-build-42",
      command: "./gradlew bundleRelease",
      artifact_url: "https://ci.example/app.aab",
      ci_provider: "github",
      ci_run_id: "12345",
      ci_run_url: nil,
      upload_logs: false,
      check_cancellation: true,
      poll_interval: 5
    )

    expect(result[:status]).to eq(:completed)
    expect(Fastlane::AppManager::BuildRunner).to have_received(:new) do |options|
      expect(options[:environment]).to include(
        "APP_MANAGER_BUILD_IDENTIFIER" => "com.example.android",
        "APP_MANAGER_BUILD_VERSION" => "2.3.0",
        "APP_MANAGER_BUILD_VERSION_CODE" => 20_300
      )
      expect(options[:upload_logs]).to be(false)
      expect(options[:check_cancellation]).to be(true)
    end
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:finish_build).with(
      build_id: "build-uuid",
      status: "completed",
      artifact_url: "https://ci.example/app.aab"
    )
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD] = nil
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
      allow_expired: false,
      output_path: "/tmp/certificate.p12",
      overwrite: true
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
      allow_expired: false,
      output_path: "/tmp/firebase.json",
      overwrite: true
    )
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::PLATFORM_NAME] = nil
  end

  it "loads build context for the current platform lane" do
    context = {
      target: { identifier: "com.example.ios" },
      profile: { value: "appstore" },
      credentials: []
    }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:build_context).and_return(context)
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::PLATFORM_NAME] = :ios

    result = Fastlane::Actions::AppManagerBuildContextAction.run(
      platform: nil,
      profile_type: "appstore",
      app_identifier: nil
    )

    expect(result).to eq(context)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:build_context).with(
      platform: "ios",
      profile_type: "appstore",
      app_identifier: nil
    )
    expect(
      Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_CONTEXT]
    ).to eq(context)
  ensure
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::PLATFORM_NAME] = nil
    Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::APP_MANAGER_BUILD_CONTEXT] = nil
  end

  it "downloads both platform client Firebase files with typed actions" do
    downloaded = { path: "/tmp/firebase-client", payload: {} }
    allow(Fastlane::Helper::AppManagerHelper).to receive(:download_credential).and_return(downloaded)
    params = {
      app_identifier: nil,
      output_path: "/tmp/firebase-client",
      credential_id: nil,
      credential_name: nil,
      allow_expired: false,
      overwrite: true
    }

    Fastlane::Actions::AppManagerDownloadGoogleServicesJsonAction.run(params)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_credential).with(
      type: "google_services_json",
      app_identifier: nil,
      platform: "android",
      profile_type: nil,
      credential_id: nil,
      credential_name: nil,
      allow_expired: false,
      output_path: "/tmp/firebase-client",
      overwrite: true
    )

    Fastlane::Actions::AppManagerDownloadGoogleServiceInfoPlistAction.run(params)
    expect(Fastlane::Helper::AppManagerHelper).to have_received(:download_credential).with(
      type: "google_service_info_plist",
      app_identifier: nil,
      platform: "ios",
      profile_type: nil,
      credential_id: nil,
      credential_name: nil,
      allow_expired: false,
      output_path: "/tmp/firebase-client",
      overwrite: true
    )
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

  it "exposes all typed download actions" do
    runner = Fastlane::Runner.new
    action_names = %i[
      app_manager_configure
      app_manager_app_info
      app_manager_build_context
      app_manager_build_start
      app_manager_build_log
      app_manager_build_status
      app_manager_build_finish
      app_manager_run_build
      app_manager_download_icon
      app_manager_download_splashscreen
      app_manager_download_ios_certificate
      app_manager_download_provisioning_profile
      app_manager_download_android_keystore
      app_manager_download_firebase_config
      app_manager_download_apns_key
      app_manager_download_google_play_service_account
      app_manager_download_apple_api_key
      app_manager_download_google_services_json
      app_manager_download_google_service_info_plist
    ]

    action_names.each do |action_name|
      expect(runner.class_reference_from_action_name(action_name)).not_to be_nil
    end

    configure_keys = Fastlane::Actions::AppManagerConfigureAction.available_options.map(&:key)
    credential_keys = Fastlane::Actions::AppManagerDownloadIosCertificateAction.available_options.map(&:key)
    asset_keys = Fastlane::Actions::AppManagerDownloadIconAction.available_options.map(&:key)
    run_build_keys = Fastlane::Actions::AppManagerRunBuildAction.available_options.map(&:key)
    expect(configure_keys).to include(:app)
    expect(credential_keys).not_to include(:app)
    expect(credential_keys).to include(:allow_expired)
    expect(asset_keys).not_to include(:app)
    expect(run_build_keys).to include(:upload_logs, :check_cancellation)
  end
end
