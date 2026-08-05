require "fileutils"
require "json"
require "optparse"

module AppManagerSwiftDemo
  class Prepare
    def initialize(project_root:)
      @project_root = File.expand_path(project_root)
    end

    def call(input)
      definition = object(input, "Adapter input must be an object")
      context = object(definition["context"], "Build context is missing")
      files = object(definition["files"] || {}, "Downloaded files map is invalid")
      raise "Unsupported build context schema: #{context['schema_version']}" unless context["schema_version"] == 1

      app = object(context["app"], "Build context app is missing")
      target = object(context["target"], "Build context target is missing")
      profile = object(context["profile"], "Build context profile is missing")
      raise "Swift demo requires an iOS build context" unless target["platform"] == "ios" && profile["platform"] == "ios"

      slug = string(app["slug"], "App slug is missing")
      name = string(app["name"], "App name is missing")
      identifier = string(target["identifier"], "iOS bundle identifier is missing")
      version = string(target["version"], "iOS version is missing")
      build_number = string(target["build_number"], "iOS build_number is missing")
      profile_type = string(profile["value"], "iOS profile type is missing")

      certificate = downloaded_file(files["certificate"], "iOS certificate")
      certificate_payload = object(certificate["payload"], "iOS certificate payload is missing")
      certificate_password = secret_string(certificate_payload["password"], "iOS certificate password is missing")
      provisioning_profile = downloaded_file(files["provisioning_profile"], "iOS provisioning profile")

      %w[icon splashscreen google_service_info apple_api_key].each do |name_key|
        value = files[name_key]
        next unless value
        value.is_a?(Hash) ? downloaded_file(value, name_key.tr("_", " ")) : existing_file(value, name_key.tr("_", " "))
      end

      generated_root = File.join(@project_root, ".app-manager")
      xcconfig_path = File.join(generated_root, "AppManager.xcconfig")
      credentials_path = File.join(generated_root, "credentials.json")
      context_path = File.join(generated_root, "context.json")
      assets_path = File.join(generated_root, "GeneratedAssets.xcassets")

      xcconfig = {
        "PRODUCT_BUNDLE_IDENTIFIER" => identifier,
        "MARKETING_VERSION" => version,
        "CURRENT_PROJECT_VERSION" => build_number,
        "APP_DISPLAY_NAME" => name,
        "APP_MANAGER_APP_SLUG" => slug,
        "APP_MANAGER_PROFILE_TYPE" => profile_type,
        "ASSETCATALOG_COMPILER_APPICON_NAME" => files["icon"] ? "AppManagerIcon" : "AppIcon",
      }
      write_atomic(
        xcconfig_path,
        xcconfig.map { |key, value| "#{key} = #{xcconfig_value(value)}" }.join("\n") + "\n",
        0o600,
      )

      credentials = {
        "certificate" => {
          "path" => certificate.fetch("path"),
          "password" => certificate_password,
        },
        "provisioningProfilePath" => provisioning_profile.fetch("path"),
      }
      write_atomic(credentials_path, JSON.pretty_generate(credentials) + "\n", 0o600)

      api_key = nil
      if files["apple_api_key"]
        key = downloaded_file(files["apple_api_key"], "App Store Connect API key")
        payload = object(key["payload"], "App Store Connect API key payload is missing")
        api_key = {
          "path" => key.fetch("path"),
          "keyId" => string(payload["key_id"], "App Store Connect key_id is missing"),
          "issuerId" => string(payload["issuer_id"], "App Store Connect issuer_id is missing"),
        }
      end

      build_context = {
        "app" => slug,
        "platform" => "ios",
        "version" => version,
        "ios" => {
          "bundleIdentifier" => identifier,
          "buildNumber" => build_number,
          "profileType" => profile_type,
          "appStoreApiKey" => api_key,
        },
      }
      write_atomic(context_path, JSON.pretty_generate(build_context) + "\n", 0o600)
      write_asset_catalog(assets_path, files)

      {
        xcconfig: xcconfig,
        xcconfig_path: xcconfig_path,
        credentials: credentials,
        credentials_path: credentials_path,
        context: build_context,
        context_path: context_path,
        assets_path: assets_path,
      }
    end

    private

    def write_asset_catalog(path, files)
      write_atomic(
        File.join(path, "Contents.json"),
        JSON.pretty_generate("info" => { "author" => "app-manager", "version" => 1 }) + "\n",
        0o644,
      )
      if files["icon"]
        filename = File.basename(files["icon"])
        copy_asset(files["icon"], File.join(path, "AppManagerIcon.appiconset", filename))
        images = [{ "filename" => filename, "idiom" => "universal", "platform" => "ios", "size" => "1024x1024" }]
        write_atomic(File.join(path, "AppManagerIcon.appiconset", "Contents.json"), asset_contents(images), 0o644)
      end
      if files["splashscreen"]
        filename = File.basename(files["splashscreen"])
        copy_asset(files["splashscreen"], File.join(path, "AppManagerSplash.imageset", filename))
        images = [{ "filename" => filename, "idiom" => "universal", "scale" => "1x" }]
        write_atomic(File.join(path, "AppManagerSplash.imageset", "Contents.json"), asset_contents(images), 0o644)
      end
    end

    def copy_asset(source, destination)
      FileUtils.mkdir_p(File.dirname(destination))
      FileUtils.cp(File.expand_path(source, @project_root), destination)
      File.chmod(0o644, destination)
    end

    def asset_contents(images)
      JSON.pretty_generate(
        "images" => images,
        "info" => { "author" => "app-manager", "version" => 1 },
      ) + "\n"
    end

    def downloaded_file(value, label)
      result = object(value, "#{label} download result is missing")
      result["path"] = existing_file(result["path"], label)
      result
    end

    def existing_file(value, label)
      path = string(value, "#{label} path is missing")
      absolute = File.expand_path(path, @project_root)
      raise "#{label} file does not exist: #{path}" unless File.file?(absolute)
      path
    end

    def object(value, message)
      raise message unless value.is_a?(Hash)
      value
    end

    def string(value, message)
      raise message unless value.is_a?(String) && !value.strip.empty?
      value.strip
    end

    def secret_string(value, message)
      raise message unless value.is_a?(String) && !value.empty?
      value
    end

    def xcconfig_value(value)
      string(value.to_s, "xcconfig value is missing").gsub(/[\r\n]/, " ")
    end

    def write_atomic(path, contents, mode)
      FileUtils.mkdir_p(File.dirname(path))
      temporary = "#{path}.#{$$}.tmp"
      File.binwrite(temporary, contents)
      File.chmod(mode, temporary)
      File.rename(temporary, path)
      File.chmod(mode, path)
    ensure
      File.delete(temporary) if temporary && File.exist?(temporary)
    end
  end
end

if $PROGRAM_NAME == __FILE__
  begin
    options = { project_root: File.expand_path("..", __dir__) }
    OptionParser.new do |parser|
      parser.on("--input PATH") { |value| options[:input] = value }
      parser.on("--project-root PATH") { |value| options[:project_root] = value }
    end.parse!
    raise "--input is required" unless options[:input]

    input = JSON.parse(File.read(options[:input]))
    result = AppManagerSwiftDemo::Prepare.new(project_root: options[:project_root]).call(input)
    puts "Generated #{result[:xcconfig_path]}"
  rescue StandardError => e
    warn "prepare failed: #{e.message}"
    exit 1
  end
end
