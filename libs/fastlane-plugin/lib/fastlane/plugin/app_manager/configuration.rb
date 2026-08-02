require "uri"

module Fastlane
  module AppManager
    class Error < StandardError; end

    class Configuration
      DEFAULT_BASE_URL = "http://localhost:8000".freeze
      DEFAULT_CONNECT_TIMEOUT = 10
      DEFAULT_READ_TIMEOUT = 60
      DEFAULT_MAX_RETRIES = 3

      RETRYABLE_HTTP_CODES = [429, 502, 503, 504].freeze

      SLUG_PATTERN = /\A[a-z0-9]+(?:-[a-z0-9]+)*\z/

      attr_reader :api_key, :base_url, :team, :app, :connect_timeout, :read_timeout, :max_retries

      class << self
        def configure(api_key:, base_url:, team:, app:, connect_timeout: nil, read_timeout: nil, max_retries: nil)
          @current = new(
            api_key: api_key,
            base_url: base_url,
            team: team,
            app: app,
            connect_timeout: connect_timeout,
            read_timeout: read_timeout,
            max_retries: max_retries
          )
        end

        def current
          @current ||= new(
            api_key: ENV["APP_MANAGER_API_KEY"],
            base_url: ENV.fetch("APP_MANAGER_BASE_URL", DEFAULT_BASE_URL),
            team: ENV["APP_MANAGER_TEAM"],
            app: ENV["APP_MANAGER_APP"]
          )
        end

        def reset!
          @current = nil
        end
      end

      def initialize(api_key:, base_url:, team:, app:, connect_timeout: nil, read_timeout: nil, max_retries: nil)
        @api_key = required_value(api_key, "api_key (or APP_MANAGER_API_KEY)")
        @base_url = normalize_base_url(required_value(base_url, "base_url (or APP_MANAGER_BASE_URL)"))
        @team = validate_slug(required_value(team, "team (or APP_MANAGER_TEAM)"), "team")
        @app = validate_slug(required_value(app, "app (or APP_MANAGER_APP)"), "app")
        @connect_timeout = (connect_timeout || DEFAULT_CONNECT_TIMEOUT).to_i
        @read_timeout = (read_timeout || DEFAULT_READ_TIMEOUT).to_i
        @max_retries = (max_retries || DEFAULT_MAX_RETRIES).to_i
      end

      def api_root
        return base_url if base_url.end_with?("/api/v1")

        "#{base_url}/api/v1"
      end

      private

      def required_value(value, name)
        normalized = value.to_s.strip
        raise Error, "App Manager #{name} is required" if normalized.empty?

        normalized
      end

      def validate_slug(value, name)
        unless value.match?(SLUG_PATTERN)
          raise Error, "App Manager #{name} must be a lowercase slug (letters, digits, hyphens); got '#{value}'"
        end

        value
      end

      def normalize_base_url(value)
        normalized = value.sub(%r{/+\z}, "")
        uri = URI.parse(normalized)

        unless %w[http https].include?(uri.scheme) && uri.host && !uri.query && !uri.fragment
          raise Error, "App Manager base_url must be an absolute HTTP(S) URL"
        end

        loopback = ["localhost", "127.0.0.1", "::1"].include?(uri.host)
        insecure_http_allowed = ENV["APP_MANAGER_ALLOW_INSECURE_HTTP"] == "1"
        if uri.scheme == "http" && !loopback && !insecure_http_allowed
          raise Error, "App Manager base_url must use HTTPS for non-loopback hosts (set APP_MANAGER_ALLOW_INSECURE_HTTP=1 to override)"
        end

        normalized
      rescue URI::InvalidURIError
        raise Error, "App Manager base_url must be an absolute HTTP(S) URL"
      end
    end
  end
end
