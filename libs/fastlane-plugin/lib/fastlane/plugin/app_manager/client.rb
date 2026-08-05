require "fileutils"
require "json"
require "net/http"
require "openssl"
require "tempfile"
require "time"
require "uri"

module Fastlane
  module AppManager
    class Client
      ASSET_FILE_MODE = 0o644
      CREDENTIAL_FILE_MODE = 0o600

      def initialize(configuration = Configuration.current)
        @configuration = configuration
        @json_cache = {}
      end

      def app_info
        app = get_json(app_endpoint)["data"]
        identifiers = get_json(app_endpoint("identifiers"))["data"]
        raise Error, "App Manager returned an invalid app response" unless app.is_a?(Hash)
        unless identifiers.is_a?(Array) && identifiers.all? { |identifier| identifier.is_a?(Hash) }
          raise Error, "App Manager returned an invalid app identifiers response"
        end

        normalized_identifiers = identifiers.map do |identifier|
          platform = required_response_value(identifier, "platform", "app identifier")
          value = required_response_value(identifier, "identifier", "app identifier")

          {
            id: identifier["id"],
            platform: platform.to_sym,
            identifier: value,
            name: identifier["name"],
            version: identifier["version"],
            version_code: identifier["version_code"],
            build_number: identifier["build_number"],
            is_default: identifier["is_default"] == true,
            default_declared: identifier.key?("is_default"),
            default: identifier["is_default"] == true,
            created_at: identifier["created_at"],
            updated_at: identifier["updated_at"]
          }
        end
        normalized_identifiers.group_by { |identifier| identifier[:platform] }.each_value do |platform_identifiers|
          next if platform_identifiers.any? { |identifier| identifier[:default] }

          legacy_response = platform_identifiers.none? { |identifier| identifier[:default_declared] }
          platform_identifiers.first[:default] = true if legacy_response || platform_identifiers.length == 1
        end
        normalized_identifiers.each { |identifier| identifier.delete(:default_declared) }
        defaults = normalized_identifiers
                   .select { |identifier| identifier[:default] }
                   .to_h { |identifier| [identifier[:platform], identifier] }

        {
          id: app["id"],
          slug: app["slug"],
          name: required_response_value(app, "name", "app"),
          description: app["description"],
          assets: app["assets"] || {},
          app_identifiers: normalized_identifiers,
          default_app_identifiers: defaults,
          created_at: app["created_at"],
          updated_at: app["updated_at"]
        }
      end

      def build_context(platform:, profile_type:, app_identifier: nil)
        query = URI.encode_www_form({
          platform: platform,
          profile_type: profile_type,
          identifier: app_identifier
        }.compact)
        data = get_json("#{app_endpoint('build-context')}?#{query}")["data"]
        raise Error, "App Manager returned an invalid build context response" unless data.is_a?(Hash)
        raise Error, "App Manager returned an unsupported build context schema" unless data["schema_version"] == 1

        app = data["app"]
        target = data["target"]
        profile = data["profile"]
        credentials = data["credentials"]
        unless app.is_a?(Hash) && target.is_a?(Hash) && profile.is_a?(Hash) && credentials.is_a?(Array)
          raise Error, "App Manager returned an invalid build context response"
        end

        target_platform = required_response_value(target, "platform", "build context target")
        profile_platform = required_response_value(profile, "platform", "build context profile")

        {
          schema_version: 1,
          app: {
            id: app["id"],
            slug: app["slug"],
            name: required_response_value(app, "name", "build context app"),
            description: app["description"]
          },
          target: {
            id: target["id"],
            platform: target_platform.to_sym,
            identifier: required_response_value(target, "identifier", "build context target"),
            name: target["name"],
            version: target["version"],
            version_code: target["version_code"],
            build_number: target["build_number"],
            is_default: target["is_default"] == true
          },
          profile: {
            id: profile["id"],
            value: required_response_value(profile, "value", "build context profile"),
            platform: profile_platform.to_sym,
            name: profile["name"],
            label: profile["label"],
            artifact: profile["artifact"],
            description: profile["description"]
          },
          assets: data["assets"] || {},
          credentials: credentials
        }
      end

      def build(external_id:)
        data = request_json(:get, app_endpoint("builds", "external", external_id))["data"]
        normalize_build(data)
      end

      def build_status(build_id:)
        data = request_json(:get, app_endpoint("builds", build_id))["data"]
        normalize_build(data)
      end

      def start_build(external_id:, ci_provider:, ci_run_id:, ci_run_url: nil)
        record = build(external_id: external_id)
        return record if record[:cancelled]

        data = request_json(
          :post,
          app_endpoint("builds", record.fetch(:id), "start"),
          {
            ci_provider: ci_provider,
            ci_run_id: ci_run_id,
            ci_run_url: ci_run_url
          }.compact
        )["data"]
        normalize_build(data)
      end

      def append_build_logs(build_id:, entries:)
        data = request_json(
          :post,
          app_endpoint("builds", build_id, "logs"),
          entries: entries
        )["data"]
        raise Error, "App Manager returned an invalid build log response" unless data.is_a?(Hash)

        {
          accepted: data["accepted"].to_i,
          last_sequence: data["last_sequence"]&.to_i
        }
      end

      def finish_build(build_id:, status:, artifact_url: nil)
        data = request_json(
          :patch,
          app_endpoint("builds", build_id),
          { status: status, artifact_url: artifact_url }.compact
        )["data"]
        normalize_build(data)
      end

      def download_asset(asset:, output_path:, overwrite: true)
        path = app_endpoint("assets", asset)
        download(path, output_path, overwrite: overwrite, mode: ASSET_FILE_MODE)
      end

      def download_credential(
        type:,
        output_path:,
        app_identifier: nil,
        platform: nil,
        profile_type: nil,
        credential_id: nil,
        credential_name: nil,
        allow_expired: false,
        overwrite: true
      )
        resolved_app_identifier = app_identifier || first_app_identifier(platform)
        credential = find_credential(
          type: type,
          app_identifier: resolved_app_identifier,
          platform: platform,
          profile_type: profile_type,
          credential_id: credential_id,
          credential_name: credential_name,
          allow_expired: allow_expired
        )
        path = download(
          app_endpoint("credentials", credential.fetch("id"), "file"),
          output_path,
          overwrite: overwrite,
          mode: CREDENTIAL_FILE_MODE
        )

        {
          path: path,
          id: credential["id"],
          type: credential["type"],
          name: credential["name"],
          payload: credential["payload"] || {},
          expires_at: credential["expires_at"],
          profile_type: profile_type,
          app_identifier: resolved_app_identifier
        }
      end

      def find_credential(
        type:,
        app_identifier: nil,
        platform: nil,
        profile_type: nil,
        credential_id: nil,
        credential_name: nil,
        allow_expired: false
      )
        app_identifier ||= first_app_identifier(platform)
        body = get_json(app_endpoint("credentials"))
        credentials = body["data"]
        raise Error, "App Manager returned an invalid credentials response" unless credentials.is_a?(Array)

        matches = credentials.select do |credential|
          credential["type"] == type &&
            identifier_matches?(credential, app_identifier, profile_type) &&
            optional_match?(credential["id"], credential_id) &&
            optional_match?(credential["name"], credential_name)
        end

        matches = reject_expired_credentials(matches) unless allow_expired

        if matches.empty?
          filters = ["type=#{type}", "app_identifier=#{app_identifier}"]
          filters << "profile_type=#{profile_type}" if profile_type
          filters << "credential_id=#{credential_id}" if present?(credential_id)
          filters << "credential_name=#{credential_name}" if present?(credential_name)
          raise Error, "No App Manager credential matches #{filters.join(', ')}"
        end

        if matches.length > 1
          choices = matches.map { |item| "#{item['name']} (#{item['id']})" }.join(", ")
          raise Error,
                "Multiple App Manager credentials match; set credential_id or credential_name: #{choices}"
        end

        matches.first
      end

      private

      def normalize_build(data)
        raise Error, "App Manager returned an invalid build response" unless data.is_a?(Hash)

        status = required_response_value(data, "status", "build")
        snapshot = data["context_snapshot"]
        target = snapshot.is_a?(Hash) ? snapshot["target"] : nil
        profile = snapshot.is_a?(Hash) ? snapshot["profile"] : nil
        unless data["id"] && target.is_a?(Hash) && profile.is_a?(Hash)
          raise Error, "App Manager returned an invalid build response"
        end

        {
          id: data["id"],
          external_id: data["external_id"],
          status: status.to_sym,
          cancelled: status == "cancelled",
          target: {
            id: target["id"],
            platform: target["platform"]&.to_sym,
            identifier: target["identifier"],
            version: target["version"],
            version_code: target["version_code"],
            build_number: target["build_number"]
          },
          profile: {
            id: profile["id"],
            value: profile["value"],
            platform: profile["platform"]&.to_sym,
            artifact: profile["artifact"]
          },
          context_snapshot: snapshot,
          ci: data["ci"] || {},
          artifact_url: data["artifact_url"],
          last_log_sequence: data["last_log_sequence"]&.to_i,
          started_at: data["started_at"],
          finished_at: data["finished_at"]
        }
      end

      def get_json(path)
        return @json_cache[path] if @json_cache.key?(path)

        response = request(path)
        parsed = JSON.parse(response.body)
        raise Error, "App Manager returned invalid JSON for #{path}" unless parsed.is_a?(Hash)

        @json_cache[path] = parsed
      rescue JSON::ParserError, TypeError
        raise Error, "App Manager returned invalid JSON for #{path}"
      end

      def request_json(method, path, body = nil)
        response = request(path, method: method, body: body)
        parsed = JSON.parse(response.body)
        raise Error, "App Manager returned invalid JSON for #{path}" unless parsed.is_a?(Hash)

        parsed
      rescue JSON::ParserError, TypeError
        raise Error, "App Manager returned invalid JSON for #{path}"
      end

      def download(path, output_path, overwrite:, mode:)
        destination = File.expand_path(output_path.to_s)
        raise Error, "output_path is required" if output_path.to_s.strip.empty?
        raise Error, "Refusing to overwrite #{destination}" if File.exist?(destination) && !overwrite

        response = request(path)
        write_atomically(destination, response.body, mode)
        destination
      end

      def request(path, method: :get, body: nil)
        uri = URI.parse(path)
        request_class = {
          get: Net::HTTP::Get,
          post: Net::HTTP::Post,
          patch: Net::HTTP::Patch
        }.fetch(method.to_sym)
        http_request = request_class.new(uri)
        http_request["Authorization"] = "Bearer #{@configuration.api_key}"
        http_request["Accept"] = "application/json"
        http_request["User-Agent"] = "fastlane-plugin-app_manager/#{Fastlane::AppManager::VERSION}"
        if body
          http_request["Content-Type"] = "application/json"
          http_request.body = JSON.generate(body)
        end

        http = Net::HTTP.new(uri.host, uri.port)
        http.use_ssl = uri.scheme == "https"
        http.open_timeout = 10
        http.read_timeout = 60
        response = http.request(http_request)
        return response if response.is_a?(Net::HTTPSuccess)

        raise Error, response_error(response)
      rescue Error
        raise
      rescue SystemCallError, SocketError, Timeout::Error, OpenSSL::SSL::SSLError => e
        raise Error, "Could not connect to App Manager at #{@configuration.base_url}: #{e.message}"
      end

      def response_error(response)
        parsed = JSON.parse(response.body)
        return "App Manager API request failed (HTTP #{response.code}): #{response.message}" unless parsed.is_a?(Hash)

        details = Array(parsed["errors"]&.values).flatten.compact.join(", ")
        message = parsed["message"].to_s
        message = "#{message}: #{details}" unless details.empty?
        message = response.message if message.empty?
        "App Manager API request failed (HTTP #{response.code}): #{message}"
      rescue JSON::ParserError
        "App Manager API request failed (HTTP #{response.code}): #{response.message}"
      end

      def endpoint(*segments)
        encoded = [@configuration.team, *segments].map do |segment|
          URI.encode_www_form_component(segment.to_s).gsub("+", "%20")
        end
        "#{@configuration.api_root}/#{encoded.join('/')}"
      end

      def app_endpoint(*segments)
        endpoint("apps", @configuration.app, *segments)
      end

      def first_app_identifier(platform)
        body = get_json(app_endpoint("identifiers"))
        identifiers = body["data"]
        raise Error, "App Manager returned an invalid app identifiers response" unless identifiers.is_a?(Array)

        if present?(platform)
          identifiers = identifiers.select { |identifier| identifier["platform"] == platform.to_s }
        end

        platform_label = present?(platform) ? " #{platform}" : ""
        defaults = identifiers.select { |identifier| identifier["is_default"] == true }
        if defaults.length > 1
          raise Error, "App Manager returned multiple default#{platform_label} app identifiers"
        end

        identifier = defaults.first
        if identifier.nil? && (identifiers.length == 1 || identifiers.none? { |item| item.key?("is_default") })
          identifier = identifiers.first
        end

        if identifier.nil? && identifiers.length > 1
          choices = identifiers.map { |item| item["identifier"] }.compact.join(", ")
          raise Error,
                "Multiple#{platform_label} app identifiers have no default; " \
                "set app_identifier explicitly: #{choices}"
        end

        unless identifier && present?(identifier["identifier"])
          raise Error,
                "No#{platform_label} app identifier is configured for App Manager app #{@configuration.app}"
        end

        identifier["identifier"]
      end

      def identifier_matches?(credential, app_identifier, profile_type)
        if profile_type
          Array(credential["profiles"]).any? do |profile|
            profile["type"] == profile_type &&
              profile.dig("app_identifier", "identifier") == app_identifier
          end
        else
          Array(credential["app_identifiers"]).any? do |identifier|
            identifier["identifier"] == app_identifier
          end
        end
      end

      def optional_match?(actual, expected)
        !present?(expected) || actual.to_s == expected.to_s
      end

      def reject_expired_credentials(credentials)
        active, expired = credentials.partition { |credential| !credential_expired?(credential) }
        return active unless active.empty? && expired.any?

        choices = expired.map { |credential| "#{credential['name']} (#{credential['id']})" }.join(", ")
        raise Error, "All matching App Manager credentials are expired: #{choices}"
      end

      def credential_expired?(credential)
        value = credential["expires_at"]
        return false unless present?(value)

        Time.iso8601(value.to_s) <= Time.now
      rescue ArgumentError
        raise Error, "App Manager returned an invalid credential expiration for #{credential['id']}"
      end

      def present?(value)
        !value.nil? && !value.to_s.strip.empty?
      end

      def required_response_value(resource, key, resource_name)
        value = resource[key]
        raise Error, "App Manager returned an invalid #{resource_name} response" unless present?(value)

        value.to_s
      end

      def write_atomically(destination, contents, mode)
        directory = File.dirname(destination)
        FileUtils.mkdir_p(directory)
        raise Error, "output_path points to a directory: #{destination}" if File.directory?(destination)

        temporary = Tempfile.new([".app-manager-", ".tmp"], directory)
        temporary.binmode
        temporary.write(contents)
        temporary.flush
        temporary.fsync
        temporary.close
        File.chmod(mode, temporary.path)
        File.rename(temporary.path, destination)
      rescue SystemCallError => e
        raise Error, "Could not write #{destination}: #{e.message}"
      ensure
        temporary&.close!
      end
    end
  end
end
