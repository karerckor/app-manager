module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_CREDENTIALS = :APP_MANAGER_CREDENTIALS
    end

    class AppManagerListCredentialsAction < Action
      def self.run(_params)
        credentials = Helper::AppManagerHelper.list_credentials
        Actions.lane_context[SharedValues::APP_MANAGER_CREDENTIALS] = credentials
        print_credentials_table(credentials)
        credentials
      end

      def self.description
        "Lists credentials for the configured app"
      end

      def self.details
        "Fetches all credentials from App Manager. " \
        "Stores the result in APP_MANAGER_CREDENTIALS lane context."
      end

      def self.available_options
        []
      end

      def self.output
        [["APP_MANAGER_CREDENTIALS", "Array of credential hashes returned by App Manager"]]
      end

      def self.return_value
        "An array of credential hashes"
      end

      def self.authors
        ["App Manager contributors"]
      end

      def self.is_supported?(_platform)
        true
      end

      def self.print_credentials_table(credentials)
        return if credentials.empty?

        UI.message("")
        UI.message("  Credentials (#{credentials.length}):")
        UI.message("  #{'ID'.ljust(38)} #{'Type'.ljust(35)} #{'Name'}")
        UI.message("  #{'-' * 38} #{'-' * 35} #{'-' * 30}")
        credentials.each do |cred|
          id = cred["id"].to_s.ljust(38)
          type = cred["type"].to_s.ljust(35)
          name = cred["name"].to_s
          UI.message("  #{id} #{type} #{name}")
        end
        UI.message("")
      end
    end
  end
end
