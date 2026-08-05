require "fileutils"
require "minitest/autorun"
require "tmpdir"
require_relative "../scripts/prepare"

class PrepareTest < Minitest::Test
  def setup
    @project_root = Dir.mktmpdir("app-manager-swift-")
  end

  def teardown
    FileUtils.rm_rf(@project_root)
  end

  def test_generates_xcconfig_assets_credentials_and_context
    input = fixture("example-app")
    create_downloads(input)

    result = adapter.call(input)

    assert_equal "com.example.app", result[:xcconfig]["PRODUCT_BUNDLE_IDENTIFIER"]
    assert_equal "1", result[:xcconfig]["CURRENT_PROJECT_VERSION"]
    assert_equal "AppManagerIcon", result[:xcconfig]["ASSETCATALOG_COMPILER_APPICON_NAME"]
    assert_equal "appstore", result[:context].dig("ios", "profileType")
    assert File.file?(File.join(result[:assets_path], "AppManagerIcon.appiconset", "Contents.json"))
    assert File.file?(File.join(result[:assets_path], "AppManagerIcon.appiconset", "icon.png"))
    assert File.file?(File.join(result[:assets_path], "AppManagerSplash.imageset", "Contents.json"))
    assert_equal 0o600, File.stat(result[:xcconfig_path]).mode & 0o777
    assert_equal 0o600, File.stat(result[:credentials_path]).mode & 0o777
  end

  def test_same_source_generates_isolated_white_label_configuration
    first_input = fixture("example-app")
    blue_input = fixture("example-app-blue")
    create_downloads(first_input)
    create_downloads(blue_input)

    first = adapter.call(first_input)
    blue = adapter.call(blue_input)

    refute_equal first[:xcconfig]["APP_DISPLAY_NAME"], blue[:xcconfig]["APP_DISPLAY_NAME"]
    refute_equal first[:xcconfig]["PRODUCT_BUNDLE_IDENTIFIER"], blue[:xcconfig]["PRODUCT_BUNDLE_IDENTIFIER"]
    refute_equal first[:xcconfig]["CURRENT_PROJECT_VERSION"], blue[:xcconfig]["CURRENT_PROJECT_VERSION"]
    refute_equal first[:credentials].dig("certificate", "path"), blue[:credentials].dig("certificate", "path")
    refute_equal first[:context].dig("ios", "appStoreApiKey", "path"), blue[:context].dig("ios", "appStoreApiKey", "path")
  end

  def test_rejects_missing_build_number
    input = fixture("example-app")
    input["context"]["target"]["build_number"] = nil
    create_downloads(input)

    error = assert_raises(RuntimeError) { adapter.call(input) }
    assert_match(/build_number/, error.message)
  end

  private

  def adapter
    AppManagerSwiftDemo::Prepare.new(project_root: @project_root)
  end

  def fixture(slug)
    blue = slug.end_with?("-blue")
    prefix = ".app-manager/downloads/#{slug}"
    {
      "context" => {
        "schema_version" => 1,
        "app" => { "slug" => slug, "name" => blue ? "Blue Example App" : "Example App" },
        "target" => {
          "platform" => "ios",
          "identifier" => blue ? "com.example.blue" : "com.example.app",
          "version" => blue ? "2.0.0" : "1.0.0",
          "build_number" => blue ? "20000" : "1",
        },
        "profile" => { "platform" => "ios", "value" => "appstore" },
      },
      "files" => {
        "icon" => "#{prefix}/AppManagerIcon.appiconset/icon.png",
        "splashscreen" => "#{prefix}/AppManagerSplash.imageset/splash.png",
        "google_service_info" => "#{prefix}/GoogleService-Info.plist",
        "certificate" => {
          "path" => "#{prefix}/distribution.p12",
          "payload" => { "password" => "#{slug}-certificate" },
        },
        "provisioning_profile" => { "path" => "#{prefix}/profile.mobileprovision" },
        "apple_api_key" => {
          "path" => "#{prefix}/appstore.p8",
          "payload" => { "key_id" => "#{slug}-key", "issuer_id" => "#{slug}-issuer" },
        },
      },
    }
  end

  def create_downloads(input)
    input.fetch("files").each_value do |value|
      path = value.is_a?(Hash) ? value.fetch("path") : value
      absolute = File.join(@project_root, path)
      FileUtils.mkdir_p(File.dirname(absolute))
      File.binwrite(absolute, "#{path}-bytes")
      File.chmod(0o600, absolute)
    end
  end
end
