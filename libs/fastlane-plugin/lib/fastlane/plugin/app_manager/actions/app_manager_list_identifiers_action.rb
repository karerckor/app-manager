module Fastlane
  module Actions
    module SharedValues
      APP_MANAGER_IDENTIFIERS = :APP_MANAGER_IDENTIFIERS
    end

    class AppManagerListIdentifiersAction < Action
      def self.run(_params)
        identifiers = Helper::AppManagerHelper.list_identifiers
        Actions.lane_context[SharedValues::APP_MANAGER_IDENTIFIERS] = identifiers
        print_identifiers_table(identifiers)
        identifiers
      end

      def self.description
        "Lists app identifiers for the configured app"
      end

      def self.details
        "Fetches all identifiers (bundle IDs, package names) from App Manager. " \
        "Stores the result in APP_MANAGER_IDENTIFIERS lane context."
      end

      def self.available_options
        []
      end

      def self.output
        [["APP_MANAGER_IDENTIFIERS", "Array of identifier hashes returned by App Manager"]]
      end

      def self.return_value
        "An array of identifier hashes"
      end

      def self.authors
        ["App Manager contributors"]
      end

      def self.is_supported?(_platform)
        true
      end

      def self.print_identifiers_table(identifiers)
        return if identifiers.empty?

        UI.message("")
        UI.message("  Identifiers (#{identifiers.length}):")
        UI.message("  #{'ID'.ljust(38)} #{'Platform'.ljust(12)} #{'Identifier'.ljust(40)} #{'Name'}")
        UI.message("  #{'-' * 38} #{'-' * 12} #{'-' * 40} #{'-' * 20}")
        identifiers.each do |id|
          row_id = id["id"].to_s.ljust(38)
          platform = id["platform"].to_s.ljust(12)
          identifier = id["identifier"].to_s.ljust(40)
          name = id["name"].to_s
          UI.message("  #{row_id} #{platform} #{identifier} #{name}")
        end
        UI.message("")
      end
    end
  end
end
