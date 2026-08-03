#!/usr/bin/env bash

# Output formatting: tables, single-resource display, JSON extraction.

extract_field() {
  local json_file="$1"
  local field="$2"
  require_jq
  "$JQ_BIN" -r "$field" "$json_file" 2>/dev/null
}

extract_data_field() {
  extract_field "$1" ".data$2"
}

has_data() {
  local json_file="$1"
  require_jq
  [ "$("$JQ_BIN" -r '.data // empty' "$json_file")" != "" ]
}

is_data_array() {
  local json_file="$1"
  require_jq
  [ "$("$JQ_BIN" -r 'if .data | type == "array" then "yes" else "no" end' "$json_file")" = "yes" ]
}

data_array_length() {
  local json_file="$1"
  require_jq
  "$JQ_BIN" -r '.data | length' "$json_file"
}

# Print a single resource as key-value pairs.
# Usage: format_resource <json_file> <field_spec>
# field_spec is a jq object like '{"id":"id","name":"name","status":"status"}'
# where keys are display labels and values are jq paths relative to .data
format_resource() {
  local json_file="$1"
  local fields="$2"
  local label
  local path
  local value
  local max_label=0

  require_jq

  while IFS='=' read -r label path; do
    if [ "${#label}" -gt "$max_label" ]; then
      max_label=${#label}
    fi
  done < <("$JQ_BIN" -r 'to_entries[] | "\(.key)=\(.value)"' <<<"$fields")

  # The printed label carries a trailing colon, so the column has to be one wider
  # than the longest key. Without this the longest rows sit a character to the
  # right of every other row.
  max_label=$((max_label + 1))

  while IFS='=' read -r label path; do
    value=$("$JQ_BIN" -r ".data.${path} // \"\"" "$json_file")
    printf "%-${max_label}s  %s\n" "$label:" "$value"
  done < <("$JQ_BIN" -r 'to_entries[] | "\(.key)=\(.value)"' <<<"$fields")
}

# Print a list of resources as a table.
# Usage: format_table <json_file> <fields_array_name> <headers_array_name>
# fields_array: array of jq paths relative to .data[]
# headers_array: corresponding display headers
format_table() {
  local json_file="$1"
  local fields_json="$2"
  local headers_json="$3"
  local count
  local i
  local field
  local header
  local value
  local widths
  local header_line
  local separator_line
  local row_line

  require_jq

  count=$(data_array_length "$json_file")
  if [ "$count" -eq 0 ]; then
    printf '(no results)\n'
    return 0
  fi

  local num_fields
  num_fields=$("$JQ_BIN" -r 'length' <<<"$headers_json")

  widths=()
  for i in $(seq 0 $((num_fields - 1))); do
    header=$("$JQ_BIN" -r ".[$i]" <<<"$headers_json")
    widths+=("${#header}")
  done

  for i in $(seq 0 $((count - 1))); do
    for j in $(seq 0 $((num_fields - 1))); do
      field=$("$JQ_BIN" -r ".[$j]" <<<"$fields_json")
      value=$("$JQ_BIN" -r ".data[$i].${field} // \"\"" "$json_file")
      if [ "${#value}" -gt "${widths[$j]}" ]; then
        widths[$j]=${#value}
      fi
    done
  done

  header_line=""
  separator_line=""
  for j in $(seq 0 $((num_fields - 1))); do
    header=$("$JQ_BIN" -r ".[$j]" <<<"$headers_json")
    if [ "$j" -gt 0 ]; then
      header_line="$header_line  "
      separator_line="$separator_line  "
    fi
    printf -v padded "%-${widths[$j]}s" "$header"
    header_line="$header_line$padded"
    printf -v dashes "%-${widths[$j]}s" ""
    dashes="${dashes// /-}"
    separator_line="$separator_line$dashes"
  done

  printf '%s\n' "$header_line"
  printf '%s\n' "$separator_line"

  for i in $(seq 0 $((count - 1))); do
    row_line=""
    for j in $(seq 0 $((num_fields - 1))); do
      field=$("$JQ_BIN" -r ".[$j]" <<<"$fields_json")
      value=$("$JQ_BIN" -r ".data[$i].${field} // \"\"" "$json_file")
      if [ "$j" -gt 0 ]; then
        row_line="$row_line  "
      fi
      printf -v padded "%-${widths[$j]}s" "$value"
      row_line="$row_line$padded"
    done
    printf '%s\n' "$row_line"
  done
}

# Emit response: either pretty JSON or formatted output.
# If FORMAT_SPEC is set, uses structured formatting; otherwise raw output.
emit_formatted_response() {
  local json_file="$1"
  local format_spec="$2"

  if [ "$PRETTY_JSON" -eq 1 ]; then
    emit_response
    return
  fi

  if [ -n "$format_spec" ]; then
    local fmt_type
    fmt_type=$("$JQ_BIN" -r '.type' <<<"$format_spec")
    case "$fmt_type" in
      resource)
        local fields
        fields=$("$JQ_BIN" -c '.fields' <<<"$format_spec")
        format_resource "$json_file" "$fields"
        ;;
      table)
        local fields
        local headers
        fields=$("$JQ_BIN" -c '.fields' <<<"$format_spec")
        headers=$("$JQ_BIN" -c '.headers' <<<"$format_spec")
        format_table "$json_file" "$fields" "$headers"
        ;;
      raw)
        emit_response
        ;;
      *)
        emit_response
        ;;
    esac
  else
    emit_response
  fi
}
