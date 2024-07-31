#!/usr/bin/env bash
set -eu
echo "Deleting uneeded files from Billboard..."
declare -a files=(
  "html/libraries/billboard/dist-esm"
  "html/libraries/billboard/src"
  "html/libraries/billboard/types"
  "html/libraries/billboard/dist/plugin"
  "html/libraries/billboard/dist/theme"
  "html/libraries/billboard/CONTRIBUTING.md"
  "html/libraries/billboard/README.md"
  "html/libraries/billboard/LICENSE"
  "html/libraries/billboard/package.json"
  "html/libraries/billboard/dist/package.json"
)
for file in "${files[@]}"
  do
    if [[ -d $file || -f $file ]]; then
      rm -rf $file
    fi
  done
