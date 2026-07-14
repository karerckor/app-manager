require "uri"

module Fastlane
  module AppManager
    class Error < StandardError; end

    class Configuration
      DEFAULT_BASE_URL = "http://localhost:8000".freeze

      attr_reader :api_key, :base_url, :team, :app

      class << self
        def configure(api_key:, base_url:, team:, app:)
          @current = new(api_key: api_key, base_url: base_url, team: team, app: app)
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

      def initialize(api_key:, base_url:, team:, app:)
        @api_key = required_value(api_key, "api_key (or APP_MANAGER_API_KEY)")
        @base_url = normalize_base_url(required_value(base_url, "base_url (or APP_MANAGER_BASE_URL)"))
        @team = required_value(team, "team (or APP_MANAGER_TEAM)")
        @app = required_value(app, "app (or APP_MANAGER_APP)")
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

      def normalize_base_url(value)
        normalized = value.sub(%r{/+\z}, "")
        uri = URI.parse(normalized)

        unless %w[http https].include?(uri.scheme) && uri.host && !uri.query && !uri.fragment
          raise Error, "App Manager base_url must be an absolute HTTP(S) URL"
        end

        normalized
      rescue URI::InvalidURIError
        raise Error, "App Manager base_url must be an absolute HTTP(S) URL"
      end
    end
  end
end
