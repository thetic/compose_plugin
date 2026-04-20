#!/bin/bash
# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"
composeLogger "Array not started, aborting script" warning compose
echo "The Array must be started in order to run this script"

