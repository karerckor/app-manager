require "open3"

module Fastlane
  module AppManager
    class BuildRunner
      MAX_LOG_MESSAGE = 60_000

      def initialize(
        client:,
        build:,
        poll_interval: 5,
        batch_size: 20,
        redact_values: [],
        environment: {},
        upload_logs: true,
        check_cancellation: true
      )
        @client = client
        @build = build
        @poll_interval = poll_interval.to_f
        @batch_size = batch_size
        @sequence = build[:last_log_sequence] || 0
        @entries = []
        @redact_values = redact_values.compact.map(&:to_s).reject(&:empty?)
        @environment = environment.compact.transform_values(&:to_s)
        @upload_logs = upload_logs
        @check_cancellation = check_cancellation
      end

      def run(command:)
        append("Build command started", stream: "system")
        cancelled = false
        process_status = nil
        process_thread = nil

        Open3.popen3(@environment, command, pgroup: true) do |stdin, stdout, stderr, wait_thread|
          process_thread = wait_thread
          stdin.close
          queue = Queue.new
          readers = start_readers(queue, stdout, stderr)
          finished_readers = 0
          next_poll = monotonic_time + @poll_interval if @check_cancellation

          until finished_readers == readers.length && !wait_thread.alive? && queue.empty?
            until queue.empty?
              type, stream, message = queue.pop
              if type == :eof
                finished_readers += 1
              else
                Fastlane::UI.command_output(redact(message))
                append(message, stream: stream)
              end
            end
            flush if @entries.length >= @batch_size

            if @check_cancellation && !cancelled && monotonic_time >= next_poll
              current = @client.build_status(build_id: @build.fetch(:id))
              if current[:cancelled]
                cancelled = true
                append("Cancellation received from App Manager", stream: "system", level: "warning")
                terminate(wait_thread)
              end
              next_poll = monotonic_time + @poll_interval
            end

            sleep(0.05) if queue.empty? && (wait_thread.alive? || finished_readers < readers.length)
          end

          readers.each(&:join)
          process_status = wait_thread.value
        end

        if cancelled
          append("Command stopped because the build was cancelled", stream: "system", level: "warning")
        else
          level = process_status.success? ? "info" : "error"
          append("Command exited with status #{process_status.exitstatus}", stream: "system", level: level)
        end
        flush

        {
          cancelled: cancelled,
          success: !cancelled && process_status.success?,
          exit_status: process_status.exitstatus
        }
      rescue StandardError
        terminate(process_thread) if process_thread&.alive?
        raise
      ensure
        flush unless @entries.empty?
      end

      private

      def start_readers(queue, stdout, stderr)
        { "stdout" => stdout, "stderr" => stderr }.map do |stream, io|
          Thread.new do
            io.each_line do |line|
              message = line.chomp
              chunks = message.scan(/.{1,#{MAX_LOG_MESSAGE}}/m)
              chunks = [""] if chunks.empty?
              chunks.each { |chunk| queue << [:line, stream, chunk] }
            end
          ensure
            queue << [:eof, stream, nil]
          end
        end
      end

      def append(message, stream:, level: nil)
        return unless @upload_logs

        @sequence += 1
        @entries << {
          sequence: @sequence,
          stream: stream,
          level: level || (stream == "stderr" ? "error" : "info"),
          message: redact(message),
          logged_at: Time.now.utc.iso8601
        }
      end

      def flush
        return if @entries.empty?

        result = @client.append_build_logs(build_id: @build.fetch(:id), entries: @entries)
        @sequence = [@sequence, result[:last_sequence] || 0].max
        @entries = []
      end

      def redact(message)
        redacted = message.to_s.gsub(/Bearer\s+\S+/i, "Bearer [REDACTED]")
        @redact_values.each { |secret| redacted = redacted.gsub(secret, "[REDACTED]") }
        redacted
      end

      def terminate(wait_thread)
        Process.kill("TERM", -wait_thread.pid)
        deadline = monotonic_time + 2
        sleep(0.05) while wait_thread.alive? && monotonic_time < deadline
        Process.kill("KILL", -wait_thread.pid) if wait_thread.alive?
      rescue Errno::ESRCH
        nil
      end

      def monotonic_time
        Process.clock_gettime(Process::CLOCK_MONOTONIC)
      end
    end
  end
end
