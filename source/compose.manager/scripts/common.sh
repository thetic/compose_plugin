#!/bin/bash
# Compose Manager - Shared shell functions
# Sourced by event scripts and other shell scripts that need common utilities.

# Sanitize a string for use as a Docker Compose project name.
# Replaces spaces, dots, and dashes with underscores and lowercases.
sanitize() {
   local s="${1?need a string}"
   s="${s// /_}"
   s="${s//./_}"
   s="${s//-/_}"
   echo "${s,,}"
}

# Find the compose file in a directory using Docker Compose spec priority.
# Returns the full path to the first matching file, or exits with 1 if none found.
# Priority: compose.yaml > compose.yml > docker-compose.yaml > docker-compose.yml
# See: https://docs.docker.com/compose/intro/compose-application-model/#the-compose-file
find_compose_file() {
    local dir="$1"
    for name in compose.yaml compose.yml docker-compose.yaml docker-compose.yml; do
        if [ -f "$dir/$name" ]; then
            echo "$dir/$name"
            return 0
        fi
    done
    return 1
}


find_compose_override_file() {
    local dir="$1"
    for name in compose.override.yaml compose.override.yml docker-compose.override.yaml docker-compose.override.yml; do
        if [ -f "$dir/$name" ]; then
            echo "$dir/$name"
            return 0
        fi
    done
    return 1
}

# Check if a directory has any compose file
has_compose_file() {
    find_compose_file "$1" > /dev/null 2>&1
}
