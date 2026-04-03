#!/usr/bin/env bash
# patch.sh - robust patch installer for compose.manager (renamed from patch_ui.sh)
# Features:
# - apply/remove patches found under patches/<version-range>
# - idempotent apply (treat previously-applied as success)
# - generate reverse patch & store minimal manifest for clean remove
# - supports --dry-run and --verbose

set -uo pipefail

usage() {
  cat <<EOF
Usage: $0 [apply|remove] [--dry-run] [--verbose] [--patch-dir DIR]

Commands:
  apply        Apply patches for the running Unraid version (default)
  remove       Remove previously applied patches (clean)

Options:
  --dry-run    Show actions without making changes
  --verbose    Verbose output
  --patch-dir  Override the patches root directory (default: /usr/local/emhttp/plugins/compose.manager/patches)
  -r           Shortcut for 'remove'

EOF
  exit 1
}

# Defaults
COMMAND="apply"
DRY_RUN=0
VERBOSE=0
PATCH_ROOT="/usr/local/emhttp/plugins/compose.manager/patches"
PLUGIN_ROOT="/usr/local/emhttp/plugins/compose.manager"
APPLIED_DIR="$PLUGIN_ROOT/patches/applied"

# Parse args
if [ "$#" -gt 0 ]; then
  case "$1" in
    apply|remove)
      COMMAND="$1"; shift
      ;;
    -r)
      COMMAND="remove"; shift
      ;;
  esac
fi

while [ "$#" -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --verbose) VERBOSE=1; shift ;;
    --patch-dir) PATCH_ROOT="$2"; shift 2 ;;
    -h|--help) usage ;;
    *) echo "Unknown option: $1"; usage ;;
  esac
done

vmsg(){ [ "$VERBOSE" -eq 1 ] && echo "$@"; }

# Helpers
ver_to_int() {
  IFS='.' read -r maj min pat <<< "$1" || true
  maj=${maj:-0}; min=${min:-0}; pat=${pat:-0}
  echo $((maj*10000 + min*100 + pat))
}

parse_folder_range() {
  local name="$1"
  name=$(basename "$name")
  local n="$name"

  # Supported formats (strict-ish):
  #  - major:        7                 => 7.0.0 - 7.99.99
  #  - major.minor:  7.1               => 7.1.0 - 7.1.99
  #  - major.minor.patch: 6.12.3       => 6.12.3 - 6.12.3
  #  - range:        a-b where each side may be 1-3 numeric components
  #                   e.g., 6.12-7 => 6.12.0 - 7.99.99
  #  - suffix '+' or '-' for open ranges (>= or <=)

  # Open-ended >= (X+) -> X.0.0 .. infinity
  if [[ "$n" =~ ^([0-9]+(\.[0-9]+){0,2})\+$ ]]; then
    local a=${BASH_REMATCH[1]}
    IFS='.' read -r a1 a2 a3 <<< "$a"
    a1=${a1:-0}; a2=${a2:-0}; a3=${a3:-0}
    local minI
    minI=$(ver_to_int "${a1}.${a2}.${a3}")
    echo "$minI 99999999"
    return
  fi

  # Open-ended <= (X-) -> 0 .. X.max
  if [[ "$n" =~ ^([0-9]+(\.[0-9]+){0,2})\-$ ]]; then
    local a=${BASH_REMATCH[1]}
    IFS='.' read -r a1 a2 a3 <<< "$a"
    a1=${a1:-0}; a2=${a2:-99}; a3=${a3:-99}
    local maxI
    maxI=$(ver_to_int "${a1}.${a2}.${a3}")
    echo "0 $maxI"
    return
  fi

  # Range: A-B where each side has 1-3 numeric components
  if [[ "$n" =~ ^([0-9]+(\.[0-9]+){0,2})\-([0-9]+(\.[0-9]+){0,2})$ ]]; then
    local a=${BASH_REMATCH[1]}
    local b=${BASH_REMATCH[3]}
    IFS='.' read -r a1 a2 a3 <<< "$a"
    a1=${a1:-0}; a2=${a2:-0}; a3=${a3:-0}
    IFS='.' read -r b1 b2 b3 <<< "$b"
    b1=${b1:-0}; b2=${b2:-99}; b3=${b3:-99}
    local minI
    minI=$(ver_to_int "${a1}.${a2}.${a3}")
    local maxI
    maxI=$(ver_to_int "${b1}.${b2}.${b3}")
    echo "$minI $maxI"
    return
  fi

  # Exact minor
  if [[ "$n" =~ ^([0-9]+)\.([0-9]+)$ ]]; then
    local v="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
    local minI
    minI=$(ver_to_int "${v}.0")
    local maxI
    maxI=$(ver_to_int "${v}.99")
    echo "$minI $maxI"
    return
  fi

  # Major only
  if [[ "$n" =~ ^([0-9]+)$ ]]; then
    local maj=${BASH_REMATCH[1]}
    local minI
    minI=$(ver_to_int "${maj}.0.0")
    local maxI
    maxI=$(ver_to_int "${maj}.99.99")
    echo "$minI $maxI"
    return
  fi

  # fallback: match all
  echo "0 99999999"
}

# Determine host Unraid version
UNRAID_VER=$(grep -oP "\d+\.\d+\.\d+" /etc/unraid-version || true)
if [ -z "$UNRAID_VER" ]; then
  vmsg "Could not detect Unraid version; defaulting to include all patch folders"
fi

# Build candidate patch directories ordered by min range
candidates=()
host_ver_int=$(ver_to_int "${UNRAID_VER:-0.0.0}")
if [ -d "$PATCH_ROOT" ]; then
  tmpfile=$(mktemp /tmp/patchdirs.XXXXXXXX)
  for d in "$PATCH_ROOT"/*; do
    [ -d "$d" ] || continue
    read -r minI maxI <<< "$(parse_folder_range "$d")"
    if [ "$UNRAID_VER" = "" ] || { [ "$host_ver_int" -ge "$minI" ] && [ "$host_ver_int" -le "$maxI" ]; }; then
      printf "%s %s\n" "$minI" "$d" >> "$tmpfile"
    fi
  done
  while read -r line; do
    candidates+=("$(echo "$line" | cut -d' ' -f2-)")
  done < <(sort -n "$tmpfile")
  rm -f "$tmpfile"
else
  echo "Patch root $PATCH_ROOT not found"; exit 1
fi

mkdir -p "$APPLIED_DIR"

# Utility: determine target file for a patch
determine_target(){
  local patchfile="$1"
  local target=""
  if grep -q -E "^\*\*\* Update File: " "$patchfile" 2>/dev/null; then
    target=$(grep -m1 -E "^\*\*\* Update File: " "$patchfile" | sed -E 's/^\*\*\* Update File: //')
  else
    plusline=$(grep -m1 -E "^\+\+\+ " "$patchfile" || true)
    if [ -n "$plusline" ]; then
      target=$(echo "$plusline" | sed -E 's/^\+\+\+ [ab]\///; s/^\+\+\+ //; s/[[:space:]].*$//')
    fi
  fi
  target=$(echo "$target" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')
  # Normalize to absolute path if necessary
  if [ -n "$target" ] && [[ "$target" != /* ]]; then
    target="/$target"
  fi
  echo "$target"
}

APPLIED_MANIFEST="$APPLIED_DIR/applied_manifest.csv"

apply_patches(){
  local failed=0
  local applied=0
  local failed_list=()
  for pdir in "${candidates[@]}"; do
    for patchfile in "$pdir"/*.patch; do
      [ -f "$patchfile" ] || continue
      base=$(basename "$patchfile")
      # skip artifacts
      if [[ "$base" == *.reverse.patch ]] || [[ "$base" == *.orig ]]; then
        vmsg "Skipping artifact: $patchfile"
        continue
      fi

      vmsg "Processing patch: $patchfile"
      target=$(determine_target "$patchfile")
      if [ -z "$target" ]; then
        echo "Could not determine target for $patchfile (missing header); marking as failed"
        failed=$((failed+1))
        failed_list+=("$patchfile")
        continue
      fi
      if [ ! -f "$target" ]; then
        echo "Target file $target does not exist for $patchfile; marking as failed"
        failed=$((failed+1))
        failed_list+=("$patchfile")
        continue
      fi

      echo "==> Patch: $base -> $target"
      if [ "$DRY_RUN" -eq 1 ]; then
        continue
      fi

      # Apply using patch utility (non-interactive, creates .orig backup)
      patch_output=$(patch -s -N -r - -b -Y . -z .orig "$target" "$patchfile" 2>&1)
      rc=$?
      if [ $rc -eq 0 ]; then
        echo "Applied $base"
        applied=$((applied+1))
        # write reverse patch and manifest
        mkdir -p "$APPLIED_DIR"
        reverse_file="$APPLIED_DIR/${base}.reverse.patch"
        # Always generate reverse diff from .orig to patched file
        diff -u "$target.orig" "$target" | sed -E 's/^(--- |\+\+\+)\///' > "$reverse_file" || true
        if [ -s "$reverse_file" ]; then
          echo "Wrote reverse: $reverse_file"
        else
          rm -f "$reverse_file" || true
        fi
        # store original for restore path
        cp "$target.orig" "$APPLIED_DIR/${base}.orig" 2>/dev/null || true
        # add to manifest (replace if exists)
        grep -v "^${base}," "$APPLIED_MANIFEST" 2>/dev/null > "$APPLIED_MANIFEST.tmp" || true
        echo "${base},${target}" >> "$APPLIED_MANIFEST.tmp"
        mv "$APPLIED_MANIFEST.tmp" "$APPLIED_MANIFEST" || true
        continue
      fi

      # Treat 'previously applied' as benign
      if echo "$patch_output" | grep -qi "Reversed (or previously applied)" || echo "$patch_output" | grep -qi "Skipping patch"; then
        echo "Patch reported previously applied/skip: $base"
        if [ "$DRY_RUN" -eq 1 ]; then
          echo "(dry-run) would record manifest and attempt to generate reverse for $base"
          continue
        fi

        # If an original backup (.orig) already exists, ensure the manifest records this patch
        if [ -f "$target.orig" ]; then
          grep -v "^${base}," "$APPLIED_MANIFEST" 2>/dev/null > "$APPLIED_MANIFEST.tmp" || true
          echo "${base},${target}" >> "$APPLIED_MANIFEST.tmp"
          mv "$APPLIED_MANIFEST.tmp" "$APPLIED_MANIFEST" || true
          vmsg "Recorded ${base} in manifest (orig present)"
          continue
        fi

        # Try to reconstruct the original content by reversing the patch into a temporary copy
        tmp_orig=$(mktemp)
        cp "$target" "$tmp_orig"
        if patch -s -p0 -R "$tmp_orig" "$patchfile" >/dev/null 2>&1; then
          # tmp_orig should now contain the original (pre-patched) file
          mkdir -p "$APPLIED_DIR"
          cp "$tmp_orig" "$APPLIED_DIR/${base}.orig" 2>/dev/null || true
          reverse_file="$APPLIED_DIR/${base}.reverse.patch"
          # reverse file should be original -> patched (so removal can use patch -R on it)
          diff -u "$tmp_orig" "$target" | sed -E 's/^(--- |\+\+\+)\///' > "$reverse_file" || true
          if [ -s "$reverse_file" ]; then
            echo "Wrote reverse: $reverse_file"
          else
            rm -f "$reverse_file" || true
          fi
          # record manifest entry
          grep -v "^${base}," "$APPLIED_MANIFEST" 2>/dev/null > "$APPLIED_MANIFEST.tmp" || true
          echo "${base},${target}" >> "$APPLIED_MANIFEST.tmp"
          mv "$APPLIED_MANIFEST.tmp" "$APPLIED_MANIFEST" || true
        else
          echo "Could not reconstruct original for $base; recording manifest entry without reverse. Manual revert may be required."
          grep -v "^${base}," "$APPLIED_MANIFEST" 2>/dev/null > "$APPLIED_MANIFEST.tmp" || true
          echo "${base},${target}" >> "$APPLIED_MANIFEST.tmp"
          mv "$APPLIED_MANIFEST.tmp" "$APPLIED_MANIFEST" || true
        fi
        rm -f "$tmp_orig" || true
        continue
      fi

      echo "Failed to apply $base (rc=$rc)"
      echo "$patch_output"
      failed=$((failed+1))
      failed_list+=("$patchfile")
    done
  done

  if [ $failed -ne 0 ]; then
    echo "Finished with $failed failed patches"
    echo "Failed patches":;
    for f in "${failed_list[@]}"; do echo " - $f"; done
    return 1
  fi
  echo "Finished: $applied patches applied"
  return 0
}

remove_patches(){
  local failed=0
  local failed_unpatches_list=()
  local to_remove=()
  # First pass: try to apply reverse patches in applied dir
  if [ -f "$APPLIED_MANIFEST" ]; then
    # iterate reverse patches in the same order they were recorded
    while IFS=',' read -r pbase tpath; do
      [ -n "$pbase" ] || continue
      rev="$APPLIED_DIR/${pbase}.reverse.patch"
      orig_saved="$APPLIED_DIR/${pbase}.orig"
      echo "Reverting: $pbase -> $tpath"
      if [ "$DRY_RUN" -eq 1 ]; then
        continue
      fi
      if [ -f "$rev" ]; then
        if (cd / && patch -s -p0 -R < "$rev"); then
          echo "Reverted via $rev"
          rm -f "$rev" || true
          rm -f "$orig_saved" || true
          # schedule manifest removal; commit after loop to avoid in-loop file rewrite
          to_remove+=("$pbase")
          continue
        else
          echo "Reverse patch failed for $pbase"
          failed=$((failed+1))
          failed_unpatches_list+=("$pbase")
          continue
        fi
      fi

      # Fallback: if original exists in applied dir, restore it
      if [ -f "$orig_saved" ]; then
        cp "$orig_saved" "$tpath" || { echo "Failed to restore $tpath from ${orig_saved}"; failed=$((failed+1)); failed_unpatches_list+=("$pbase"); continue; }
        rm -f "$orig_saved" || true
        to_remove+=("$pbase")
        continue
      fi

      # If we reach here, no reverse nor saved orig found
      echo "No reverse or saved original for ${pbase}; manual restore required for ${tpath}"
      failed=$((failed+1))
      failed_unpatches_list+=("$pbase")
    done < "$APPLIED_MANIFEST"

    if [ ${#to_remove[@]} -ne 0 ]; then
      cp "$APPLIED_MANIFEST" "$APPLIED_MANIFEST.tmp" 2>/dev/null || true
      for rm_entry in "${to_remove[@]}"; do
        awk -F',' -v entry="$rm_entry" '$1 != entry' "$APPLIED_MANIFEST.tmp" > "$APPLIED_MANIFEST.tmp2" || true
        mv "$APPLIED_MANIFEST.tmp2" "$APPLIED_MANIFEST.tmp" || true
      done
      mv "$APPLIED_MANIFEST.tmp" "$APPLIED_MANIFEST" || true
    fi
  else
    vmsg "No manifest found; nothing to revert via manifest"
  fi

  # At the end, report failed unpatches if any
  if [ ${#failed_unpatches_list[@]} -ne 0 ]; then
    echo "Failed to unpatch the following items:"
    for p in "${failed_unpatches_list[@]}"; do echo " - $p"; done
  fi

  # Final cleanup: remove any empty manifest file
  if [ -f "$APPLIED_MANIFEST" ] && [ ! -s "$APPLIED_MANIFEST" ]; then
    rm -f "$APPLIED_MANIFEST" || true
  fi

  if [ $failed -ne 0 ]; then
    echo "Remove finished with $failed failures"
    return 1
  fi
  echo "Remove finished successfully"
  return 0
}

case "$COMMAND" in
  apply)
    apply_patches
    exit $?
    ;;
  remove)
    remove_patches
    exit $?
    ;;
  *)
    usage
    ;;
esac
