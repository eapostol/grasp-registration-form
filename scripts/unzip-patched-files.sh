#!/bin/bash

set -euo pipefail

usage() {
  echo "Usage: $0 -filename=../name-of-file.zip"
}

zip_file=""

for arg in "$@"; do
  case "$arg" in
    -filename=*|--filename=*)
      zip_file="${arg#*=}"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $arg" >&2
      usage
      exit 1
      ;;
  esac
done

if [ -z "$zip_file" ]; then
  echo "Missing required argument: -filename=..." >&2
  usage
  exit 1
fi

if [ ! -f "$zip_file" ]; then
  echo "File not found: $zip_file" >&2
  exit 1
fi

unzip -o "$zip_file" -d ./
