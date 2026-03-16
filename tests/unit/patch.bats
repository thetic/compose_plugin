#!/usr/bin/env bats
# Unit tests for patch.sh helpers

# Load test framework
load '../bats_setup.bash'

PATCH_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/patch.sh"

setup() {
  :
}

@test "ver_to_int converts dotted version to integer" {
  # Source function
  source <(sed -n '/^ver_to_int()/,/^}/p' "$PATCH_SCRIPT")

  run ver_to_int 6.10.3
  [ "$status" -eq 0 ]
  [ "$output" -eq 61003 ]
}

@test "parse_folder_range handles open-ended plus (6.10+)" {
  source <(sed -n '/^ver_to_int()/,/^}/p' "$PATCH_SCRIPT")
  source <(sed -n '/^parse_folder_range()/,/^}/p' "$PATCH_SCRIPT")

  out=$(parse_folder_range "6.10+")
  arr=($out)
  [ "${arr[0]}" -eq $(ver_to_int 6.10.0) ]
  [ "${arr[1]}" -eq 99999999 ]
}

@test "parse_folder_range handles open-ended minus (6.10-)" {
  source <(sed -n '/^ver_to_int()/,/^}/p' "$PATCH_SCRIPT")
  source <(sed -n '/^parse_folder_range()/,/^}/p' "$PATCH_SCRIPT")

  out=$(parse_folder_range "6.10-")
  arr=($out)
  [ "${arr[0]}" -eq 0 ]
  [ "${arr[1]}" -eq $(ver_to_int 6.10.99) ]
}

@test "parse_folder_range handles range A-B (6.10-6.11)" {
  source <(sed -n '/^ver_to_int()/,/^}/p' "$PATCH_SCRIPT")
  source <(sed -n '/^parse_folder_range()/,/^}/p' "$PATCH_SCRIPT")

  out=$(parse_folder_range "6.10-6.11")
  arr=($out)
  [ "${arr[0]}" -eq $(ver_to_int 6.10.0) ]
  [ "${arr[1]}" -eq $(ver_to_int 6.11.99) ]
}

@test "parse_folder_range handles exact minor (6.10)" {
  source <(sed -n '/^ver_to_int()/,/^}/p' "$PATCH_SCRIPT")
  source <(sed -n '/^parse_folder_range()/,/^}/p' "$PATCH_SCRIPT")

  out=$(parse_folder_range "6.10")
  arr=($out)
  [ "${arr[0]}" -eq $(ver_to_int 6.10.0) ]
  [ "${arr[1]}" -eq $(ver_to_int 6.10.99) ]
}

@test "parse_folder_range handles major only (6)" {
  source <(sed -n '/^ver_to_int()/,/^}/p' "$PATCH_SCRIPT")
  source <(sed -n '/^parse_folder_range()/,/^}/p' "$PATCH_SCRIPT")

  out=$(parse_folder_range "6")
  arr=($out)
  [ "${arr[0]}" -eq $(ver_to_int 6.0.0) ]
  [ "${arr[1]}" -eq $(ver_to_int 6.99.99) ]
}

@test "determine_target extracts *** Update File header" {
  source <(sed -n '/^determine_target()/,/^}/p' "$PATCH_SCRIPT")

  tmp=$(mktemp)
  cat > "$tmp" <<'EOF'
*** Update File: /usr/local/foo/bar.txt

*** End Patch Description
EOF

  out=$(determine_target "$tmp")
  [ "$out" = "/usr/local/foo/bar.txt" ]
  rm -f "$tmp"
}

@test "determine_target extracts +++ header and normalizes to absolute" {
  source <(sed -n '/^determine_target()/,/^}/p' "$PATCH_SCRIPT")

  tmp=$(mktemp)
  cat > "$tmp" <<'EOF'
+++ b/some/relative/path/file.txt	2020-01-01
EOF

  out=$(determine_target "$tmp")
  [ "$out" = "/some/relative/path/file.txt" ]
  rm -f "$tmp"
}
