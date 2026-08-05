require "spec_helper"

RSpec.describe Fastlane::AppManager::BuildRunner do
  before do
    allow(Fastlane::UI).to receive(:command_output)
  end

  let(:client) do
    Class.new do
      attr_reader :entries, :status_checks

      def initialize
        @entries = []
        @cancelled = false
        @status_checks = 0
      end

      def cancel!
        @cancelled = true
      end

      def build_status(build_id:)
        @status_checks += 1
        { id: build_id, cancelled: @cancelled }
      end

      def append_build_logs(build_id:, entries:)
        raise "missing build" unless build_id == "build-uuid"

        @entries.concat(entries)
        { accepted: entries.length, last_sequence: @entries.map { |entry| entry[:sequence] }.max }
      end
    end.new
  end
  let(:build) do
    {
      id: "build-uuid",
      external_id: "customer-build-42",
      last_log_sequence: 4
    }
  end

  it "streams stdout and stderr with monotonic sequences and secret redaction" do
    runner = described_class.new(
      client: client,
      build: build,
      poll_interval: 1,
      batch_size: 2,
      redact_values: ["api-secret"],
      environment: { "APP_MANAGER_BUILD_VERSION_CODE" => 20_300 }
    )

    result = runner.run(
      command: "printf 'version %s token api-secret\\n' \"$APP_MANAGER_BUILD_VERSION_CODE\"; printf 'warning\\n' >&2"
    )

    expect(result).to include(success: true, cancelled: false, exit_status: 0)
    expect(client.entries.map { |entry| entry[:sequence] }).to eq((5..8).to_a)
    expect(client.entries.map { |entry| entry[:stream] }).to include("stdout", "stderr", "system")
    expect(client.entries.map { |entry| entry[:message] }.join).to include("[REDACTED]")
    expect(client.entries.map { |entry| entry[:message] }.join).to include("version 20300")
    expect(client.entries.map { |entry| entry[:message] }.join).not_to include("api-secret")
  end

  it "terminates the command when App Manager reports cancellation" do
    client.cancel!
    runner = described_class.new(
      client: client,
      build: build,
      poll_interval: 0.01,
      batch_size: 20
    )

    result = runner.run(command: "sleep 10")

    expect(result[:cancelled]).to be(true)
    expect(result[:success]).to be(false)
    expect(client.entries.map { |entry| entry[:message] }.join(" ")).to include("Cancellation received")
  end

  it "can track cancellation without uploading command logs" do
    client.cancel!
    runner = described_class.new(
      client: client,
      build: build,
      poll_interval: 0.01,
      upload_logs: false
    )

    result = runner.run(command: "sleep 10")

    expect(result[:cancelled]).to be(true)
    expect(client.status_checks).to be >= 1
    expect(client.entries).to be_empty
  end

  it "can upload logs without checking remote cancellation" do
    client.cancel!
    runner = described_class.new(
      client: client,
      build: build,
      poll_interval: 0.01,
      check_cancellation: false
    )

    result = runner.run(command: "printf 'still running\\n'")

    expect(result).to include(success: true, cancelled: false, exit_status: 0)
    expect(client.status_checks).to eq(0)
    expect(client.entries.map { |entry| entry[:message] }).to include("still running")
  end

  it "keeps command output visible when log upload is disabled" do
    runner = described_class.new(
      client: client,
      build: build,
      upload_logs: false,
      check_cancellation: false
    )

    result = runner.run(command: "printf 'local output\\n'")

    expect(result).to include(success: true, cancelled: false, exit_status: 0)
    expect(Fastlane::UI).to have_received(:command_output).with("local output")
    expect(client.entries).to be_empty
  end
end
