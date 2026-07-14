require "spec_helper"

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
      output_path: "/tmp/firebase.json",
      overwrite: true
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

  it "exposes all typed download actions" do
    runner = Fastlane::Runner.new
    action_names = %i[
      app_manager_configure
      app_manager_app_info
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
    expect(configure_keys).to include(:app)
    expect(credential_keys).not_to include(:app)
    expect(asset_keys).not_to include(:app)
  end
end
