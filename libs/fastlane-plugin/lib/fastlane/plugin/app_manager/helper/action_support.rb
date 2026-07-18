module Fastlane
  module Helper
    module AppManagerActionSupport
      IOS_PROFILE_TYPES = %w[development adhoc appstore enterprise].freeze
      ANDROID_PROFILE_TYPES = %w[development preview production].freeze

      def run_credential_action(params, type:, platform:, profile_types: nil)
        profile_type = params[:profile_type]
        validate_profile_type!(profile_type, profile_types) if profile_types

        result = AppManagerHelper.download_credential(
          type: type,
          app_identifier: params[:app_identifier],
          platform: platform,
          profile_type: profile_type,
          credential_id: params[:credential_id],
          credential_name: params[:credential_name],
          output_path: params[:output_path],
          overwrite: overwrite_value(params)
        )
        UI.success("Downloaded #{type} to #{result[:path]}")
        result
      end

      def credential_options(profile_types: nil)
        options = [
          string_option(
            :app_identifier,
            "Bundle ID or Android package name; defaults to the first identifier for the action platform",
            optional: true
          ),
          string_option(:output_path, "Exact local path where the file will be saved"),
          string_option(:credential_id, "Credential UUID used to disambiguate matches", optional: true),
          string_option(:credential_name, "Credential name used to disambiguate matches", optional: true),
          FastlaneCore::ConfigItem.new(
            key: :overwrite,
            description: "Overwrite an existing file at output_path",
            type: Boolean,
            default_value: true
          )
        ]

        return options unless profile_types

        options.insert(
          2,
          FastlaneCore::ConfigItem.new(
            key: :profile_type,
            description: "Build profile type (#{profile_types.join(', ')})",
            type: String,
            verify_block: lambda do |value|
              validate_profile_type!(value, profile_types)
            end
          )
        )
        options
      end

      def asset_options
        [
          string_option(:output_path, "Exact local path where the image will be saved"),
          FastlaneCore::ConfigItem.new(
            key: :overwrite,
            description: "Overwrite an existing file at output_path",
            type: Boolean,
            default_value: true
          )
        ]
      end

      def plugin_authors
        ["App Manager contributors"]
      end

      def current_mobile_platform
        platform = Fastlane::Actions.lane_context[Fastlane::Actions::SharedValues::PLATFORM_NAME]
        platform.to_s if %i[ios android].include?(platform&.to_sym)
      end

      private

      def overwrite_value(params)
        value = params[:overwrite]
        value.nil? ? true : value
      end

      def string_option(key, description, optional: false)
        FastlaneCore::ConfigItem.new(
          key: key,
          description: description,
          type: String,
          optional: optional
        )
      end

      def validate_profile_type!(value, profile_types)
        return if profile_types.include?(value)

        UI.user_error!("profile_type must be one of: #{profile_types.join(', ')}")
      end
    end
  end
end
