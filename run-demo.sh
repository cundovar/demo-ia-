#!/usr/bin/env bash
set -euo pipefail

HOST="${HOST:-localhost}"
PORT="${PORT:-8080}"
UPLOAD_MAX_FILESIZE="${UPLOAD_MAX_FILESIZE:-500M}"
POST_MAX_SIZE="${POST_MAX_SIZE:-550M}"
MEMORY_LIMIT="${MEMORY_LIMIT:--1}"
OLLAMA_TIMEOUT="${OLLAMA_TIMEOUT:-1800}"

export OLLAMA_TIMEOUT

php \
  -d upload_max_filesize="${UPLOAD_MAX_FILESIZE}" \
  -d post_max_size="${POST_MAX_SIZE}" \
  -d memory_limit="${MEMORY_LIMIT}" \
  -d max_input_time=0 \
  -d max_execution_time=0 \
  -S "${HOST}:${PORT}" \
  index.php
