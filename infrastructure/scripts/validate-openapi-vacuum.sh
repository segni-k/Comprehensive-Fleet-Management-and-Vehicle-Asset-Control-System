#!/usr/bin/env bash
set -euo pipefail

version="0.30.0"
archive="vacuum_${version}_linux_x86_64.tar.gz"
base="https://github.com/daveshanley/vacuum/releases/download/v${version}"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

curl -fsSL "${base}/${archive}" -o "${work_dir}/${archive}"
curl -fsSL "${base}/checksums.txt" -o "${work_dir}/checksums.txt"
(cd "$work_dir" && grep " ${archive}$" checksums.txt | sha256sum --check)
tar -xzf "${work_dir}/${archive}" -C "$work_dir"
"${work_dir}/vacuum" lint --no-banner --no-style --errors docs/api/openapi.yaml
