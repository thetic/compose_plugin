#! /bin/bash
patch_script=$2/scripts/patch.sh
config_file=$3/compose.manager.cfg
case "$1" in
    "install")
    # Use the new patch utility during install to cleanup and optionally apply patches
    if [ -f "$patch_script" ]; then
        if [ -f "$config_file" ]; then
            # shellcheck source=/dev/null
            source <(grep '=' "$config_file")
            patch_ui=${PATCH_UI:='false'}

            if [ "$patch_ui" == 'true' ]; then
                echo ""
                echo "----------------------------------------------------"
                echo " Applying WebUI Patches because PATCH_UI=true"
                echo "----------------------------------------------------"
                echo ""
                $patch_script -r
                $patch_script apply || true
            else
                echo ""
                echo "----------------------------------------------------"
                echo " Removing WebUI Patches because PATCH_UI=false"
                echo " To enable the WebUI, set PATCH_UI=true in settings"
                echo " and apply patches from the plugin settings page."
                echo "----------------------------------------------------"
                echo ""
                $patch_script -r
            fi
        fi
    fi
    ;;
    
    "remove")
    # Use the new patch utility during uninstall to cleanup and remove patches
    if [ -f "$patch_script" ]; then
        if [ -f "$config_file" ]; then
            # shellcheck source=/dev/null
            source <(grep '=' "$config_file")
            patch_ui=${PATCH_UI:='false'}

            if [ "$patch_ui" == 'true' ]; then
                echo ""
                echo "----------------------------------------------------"
                echo " Removing WebUI Patches because plugin is being removed"
                echo "----------------------------------------------------"
                echo ""
                $patch_script -r
            fi
        fi
    fi
    ;;
esac