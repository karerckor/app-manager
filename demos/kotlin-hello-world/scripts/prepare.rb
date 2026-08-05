require "fileutils"
require "json"
require "optparse"

module AppManagerKotlinDemo
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
      raise "Kotlin demo requires an Android build context" unless target["platform"] == "android" && profile["platform"] == "android"

      slug = string(app["slug"], "App slug is missing")
      name = string(app["name"], "App name is missing")
      identifier = string(target["identifier"], "Android application identifier is missing")
      version = string(target["version"], "Android version is missing")
      version_code = target["version_code"]
      raise "Android version_code must be a positive integer" unless version_code.is_a?(Integer) && version_code.positive?

      profile_type = string(profile["value"], "Android profile type is missing")
      keystore = file(files["keystore"], "Android keystore")
      payload = object(keystore["payload"], "Android keystore payload is missing")

      properties = {
        "appManagerApplicationId" => identifier,
        "appManagerAppName" => name,
        "appManagerVersionName" => version,
        "appManagerVersionCode" => version_code.to_s,
        "appManagerKeystorePath" => keystore.fetch("path"),
        "appManagerStorePassword" => secret_string(payload["store_password"], "Keystore store_password is missing"),
        "appManagerKeyAlias" => string(payload["key_alias"], "Keystore key_alias is missing"),
        "appManagerKeyPassword" => secret_string(payload["key_password"], "Keystore key_password is missing"),
      }

      %w[icon splashscreen google_services google_play].each do |name_key|
        existing_file(files[name_key], name_key.tr("_", " ")) if files[name_key]
      end

      generated_root = File.join(@project_root, ".app-manager")
      properties_path = File.join(generated_root, "android.properties")
      context_path = File.join(generated_root, "context.json")
      write_atomic(
        properties_path,
        properties.map { |key, value| "#{escape(key)}=#{escape(value)}" }.join("\n") + "\n",
        0o600,
      )
      build_context = {
        "app" => slug,
        "platform" => "android",
        "version" => version,
        "android" => {
          "package" => identifier,
          "versionCode" => version_code,
          "profileType" => profile_type,
          "googlePlayJsonKeyPath" => files["google_play"],
        },
      }
      write_atomic(context_path, JSON.pretty_generate(build_context) + "\n", 0o600)

      {
        properties: properties,
        properties_path: properties_path,
        context: build_context,
        context_path: context_path,
      }
    end

    private

    def file(value, label)
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

    def escape(value)
      value.to_s
           .gsub("\\", "\\\\")
           .gsub("\n", "\\n")
           .gsub(" ", "\\ ")
           .gsub(/[:=#!]/) { |character| "\\#{character}" }
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
    result = AppManagerKotlinDemo::Prepare.new(project_root: options[:project_root]).call(input)
    puts "Generated #{result[:properties_path]}"
  rescue StandardError => e
    warn "prepare failed: #{e.message}"
    exit 1
  end
end
