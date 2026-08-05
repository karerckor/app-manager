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
          metadata: app["metadata"],
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
            platform: required_response_value(target, "platform", "build context target").to_sym,
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
            platform: required_response_value(profile, "platform", "build context profile").to_sym,
            name: profile["name"],
            label: profile["label"],
            artifact: profile["artifact"],
            description: profile["description"]
          },
          assets: data["assets"] || {},
          credentials: credentials
        }
      end

      def list_builds(status: nil, limit: nil)
        query = {}
        query[:status] = status if present?(status)
        query[:limit] = limit if limit
        path = app_endpoint("builds")
        path += "?#{URI.encode_www_form(query)}" if query.any?
        body = get_json(path)
        builds = body["data"]
        raise Error, "App Manager returned an invalid builds response" unless builds.is_a?(Array)

        builds
      end

      def create_build(request_uuid:, profile_type:, app_identifier_id: nil, context: {})
        payload = {
          request_uuid: request_uuid,
          profile_type: profile_type,
          context: context
        }
        payload[:app_identifier_id] = app_identifier_id if present?(app_identifier_id)
        build_data(json_request(app_endpoint("builds"), method: :post, body: payload))
      end

      def build(build_id)
        build_data(get_json(app_endpoint("builds", build_id)))
      end

      def cancel_build(build_id)
        build_data(json_request(
          app_endpoint("builds", build_id, "cancel"),
          method: :post,
          body: {}
        ))
      end

      def update_build(build_id:, status:)
        build_data(json_request(
          app_endpoint("builds", build_id),
          method: :patch,
          body: { status: status }
        ))
      end

      def append_build_log(build_id:, content:)
        data = json_request(
          app_endpoint("builds", build_id, "logs"),
          method: :post,
          body: { content: content }
        )["data"]
        raise Error, "App Manager returned an invalid build log response" unless data.is_a?(Hash)

        data
      end

      def upload_artifact(build_id:, file_path:, name:, metadata: {})
        raise Error, "file_path #{file_path} does not exist" unless File.file?(file_path)

        body, content_type = multipart_body(
          fields: { "name" => name, "metadata" => JSON.generate(metadata || {}) },
          file_field: "file",
          file_path: file_path
        )
        response = request(
          app_endpoint("builds", build_id, "artifacts"),
          method: :post,
          body: body,
          content_type: content_type
        )
        parsed = JSON.parse(response.body)
        raise Error, "App Manager returned an invalid artifact response" unless parsed.is_a?(Hash) && parsed["data"].is_a?(Hash)

        parsed["data"]
      rescue JSON::ParserError, TypeError
        raise Error, "App Manager returned invalid JSON for artifact upload"
      end

      def download_artifact(build_id:, artifact_id:, output_path:, overwrite: true)
        download(
          app_endpoint("builds", build_id, "artifacts", artifact_id, "file"),
          output_path,
          overwrite: overwrite,
          mode: ASSET_FILE_MODE
        )
      end

      def list_files
        body = get_json(app_endpoint("files"))
        files = body["data"]
        raise Error, "App Manager returned an invalid files response" unless files.is_a?(Array)

        files
      end

      def upload_file(file_path:, metadata: {})
        raise Error, "file_path #{file_path} does not exist" unless File.file?(file_path)

        fields = {}
        fields["metadata"] = JSON.generate(metadata) if metadata && !metadata.empty?
        body, content_type = multipart_body(
          fields: fields,
          file_field: "file",
          file_path: file_path
        )
        response = request(
          app_endpoint("files"),
          method: :post,
          body: body,
          content_type: content_type
        )
        parsed = JSON.parse(response.body)
        raise Error, "App Manager returned an invalid file response" unless parsed.is_a?(Hash) && parsed["data"].is_a?(Hash)

        parsed["data"]
      rescue JSON::ParserError, TypeError
        raise Error, "App Manager returned invalid JSON for file upload"
      end

      def download_file(name:, output_path:, overwrite: true)
        download(
          app_endpoint("files", name, "content"),
          output_path,
          overwrite: overwrite,
          mode: ASSET_FILE_MODE
        )
      end

      def delete_file(name:)
        request(app_endpoint("files", name), method: :delete, body: nil)
        true
      end

      def list_credentials
        body = get_json(app_endpoint("credentials"))
        credentials = body["data"]
        raise Error, "App Manager returned an invalid credentials response" unless credentials.is_a?(Array)

        credentials
      end

      def list_identifiers
        body = get_json(app_endpoint("identifiers"))
        identifiers = body["data"]
        raise Error, "App Manager returned an invalid identifiers response" unless identifiers.is_a?(Array)

        identifiers
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
        secret = json_request(
          app_endpoint("credentials", credential.fetch("id"), "reveal"),
          method: :post,
          body: {}
        )["data"]
        unless secret.is_a?(Hash) && secret["payload"].is_a?(Hash)
          raise Error, "App Manager returned an invalid credential secret response"
        end
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
          payload: secret["payload"],
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
        credentials = list_credentials

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

      def get_json(path)
        response = request(path)
        parsed = JSON.parse(response.body)
        raise Error, "App Manager returned invalid JSON for #{path}" unless parsed.is_a?(Hash)

        parsed
      rescue JSON::ParserError, TypeError
        raise Error, "App Manager returned invalid JSON for #{path}"
      end

      def json_request(path, method:, body:)
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

      def request(path, method: :get, body: nil, content_type: nil, attempt: 0)
        uri = URI.parse(path)
        request_class = {
          get: Net::HTTP::Get,
          post: Net::HTTP::Post,
          patch: Net::HTTP::Patch,
          delete: Net::HTTP::Delete
        }.fetch(method)
        request = request_class.new(uri)
        request["Authorization"] = "Bearer #{@configuration.api_key}"
        request["Accept"] = "application/json"
        request["User-Agent"] = "fastlane-plugin-app_manager/#{Fastlane::AppManager::VERSION}"
        if body
          if body.is_a?(String)
            request["Content-Type"] = content_type || "application/octet-stream"
            request.body = body
          else
            request["Content-Type"] = "application/json"
            request.body = JSON.generate(body)
          end
        end

        http = Net::HTTP.new(uri.host, uri.port)
        http.use_ssl = uri.scheme == "https"
        http.open_timeout = @configuration.connect_timeout
        http.read_timeout = @configuration.read_timeout
        response = http.request(request)

        if retryable_response?(response) && attempt < @configuration.max_retries
          sleep(retry_delay(attempt))
          return request(path, method: method, body: body, content_type: content_type, attempt: attempt + 1)
        end

        return response if response.is_a?(Net::HTTPSuccess)

        raise Error, response_error(response)
      rescue Error
        raise
      rescue SystemCallError, SocketError, Timeout::Error, OpenSSL::SSL::SSLError => e
        if attempt < @configuration.max_retries
          sleep(retry_delay(attempt))
          return request(path, method: method, body: body, content_type: content_type, attempt: attempt + 1)
        end
        raise Error, "Could not connect to App Manager at #{@configuration.base_url}: #{e.message}"
      end

      def retryable_response?(response)
        Configuration::RETRYABLE_HTTP_CODES.include?(response.code.to_i)
      end

      def retry_delay(attempt)
        [2**attempt, 10].min
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

      def multipart_body(fields:, file_field:, file_path:)
        boundary = "----AppManager#{Fastlane::AppManager::VERSION.gsub('.', '')}#{file_path.hash.abs}"
        parts = []
        fields.each do |key, value|
          parts << "--#{boundary}\r\n"
          parts << "Content-Disposition: form-data; name=\"#{key}\"\r\n\r\n"
          parts << "#{value}\r\n"
        end
        filename = File.basename(file_path)
        parts << "--#{boundary}\r\n"
        parts << "Content-Disposition: form-data; name=\"#{file_field}\"; filename=\"#{filename}\"\r\n"
        parts << "Content-Type: application/octet-stream\r\n\r\n"
        parts << File.binread(file_path)
        parts << "\r\n--#{boundary}--\r\n"

        [parts.join, "multipart/form-data; boundary=#{boundary}"]
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
        identifiers = list_identifiers

        if present?(platform)
          identifiers = identifiers.select { |identifier| identifier["platform"] == platform.to_s }
        end

        identifier = identifiers.first
        platform_label = present?(platform) ? " #{platform}" : ""
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

      def build_data(response)
        build = response["data"]
        raise Error, "App Manager returned an invalid build response" unless build.is_a?(Hash)

        build
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
