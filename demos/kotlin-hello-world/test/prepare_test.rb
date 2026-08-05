require "fileutils"
require "json"
require "minitest/autorun"
require "tmpdir"
require_relative "../scripts/prepare"

class PrepareTest < Minitest::Test
  def setup
    @project_root = Dir.mktmpdir("app-manager-kotlin-")
  end

  def teardown
    FileUtils.rm_rf(@project_root)
  end

  def test_generates_gradle_and_deploy_context_for_android_only
    input = fixture("example-app")
    create_downloads(input)

    result = adapter.call(input)

    assert_equal "com.example.app", result[:properties]["appManagerApplicationId"]
    assert_equal "1.0.0", result[:properties]["appManagerVersionName"]
    assert_equal "1", result[:properties]["appManagerVersionCode"]
    assert_equal "example-app", result[:context]["app"]
    assert_equal "production", result[:context].dig("android", "profileType")
    assert_equal 0o600, File.stat(result[:properties_path]).mode & 0o777
    assert_equal 0o600, File.stat(result[:context_path]).mode & 0o777
  end

  def test_same_source_generates_isolated_white_label_properties
    first_input = fixture("example-app")
    blue_input = fixture("example-app-blue")
    create_downloads(first_input)
    create_downloads(blue_input)

    first = adapter.call(first_input)
    blue = adapter.call(blue_input)

    refute_equal first[:properties]["appManagerAppName"], blue[:properties]["appManagerAppName"]
    refute_equal first[:properties]["appManagerApplicationId"], blue[:properties]["appManagerApplicationId"]
    refute_equal first[:properties]["appManagerVersionCode"], blue[:properties]["appManagerVersionCode"]
    refute_equal first[:properties]["appManagerKeystorePath"], blue[:properties]["appManagerKeystorePath"]
    refute_equal first[:context].dig("android", "googlePlayJsonKeyPath"), blue[:context].dig("android", "googlePlayJsonKeyPath")
  end

  def test_rejects_missing_android_version_code
    input = fixture("example-app")
    input["context"]["target"]["version_code"] = nil
    create_downloads(input)

    error = assert_raises(RuntimeError) { adapter.call(input) }
    assert_match(/version_code/, error.message)
  end

  private

  def adapter
    AppManagerKotlinDemo::Prepare.new(project_root: @project_root)
  end

  def fixture(slug)
    blue = slug.end_with?("-blue")
    identifier = blue ? "com.example.blue" : "com.example.app"
    prefix = ".app-manager/downloads/#{slug}"
    {
      "context" => {
        "schema_version" => 1,
        "app" => {
          "slug" => slug,
          "name" => blue ? "Blue Example App" : "Example App",
        },
        "target" => {
          "platform" => "android",
          "identifier" => identifier,
          "version" => blue ? "2.0.0" : "1.0.0",
          "version_code" => blue ? 20_000 : 1,
        },
        "profile" => { "platform" => "android", "value" => "production" },
      },
      "files" => {
        "icon" => "#{prefix}/icon.png",
        "splashscreen" => "#{prefix}/splash.png",
        "google_services" => "app/google-services.json",
        "google_play" => "#{prefix}/google-play.json",
        "keystore" => {
          "path" => "#{prefix}/release.jks",
          "payload" => {
            "store_password" => "#{slug}:store",
            "key_alias" => "#{slug}-release",
            "key_password" => "#{slug}=key",
          },
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
