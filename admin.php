<?php

use dokuwiki\Extension\AdminPlugin;

/**
 * Admin component for importing Fontello ZIP packages.
 */
class admin_plugin_fontello extends AdminPlugin
{
    /** @var helper_plugin_fontello */
    protected $helper;

    public function __construct()
    {
        $this->helper = $this->loadHelper('fontello');
    }

    /**
     * return sort order for position in admin menu
     *
     * @return int
     */
    public function getMenuSort()
    {
        return 300;
    }

    /**
     * Handle ZIP uploads.
     *
     * @return void
     */
    public function handle()
    {
        global $ID;

        if (($_POST['fontello_action'] ?? '') === 'save_enabled') {
            if (!checkSecurityToken()) {
                msg($this->getLang('err_bad_token'), -1);
                return;
            }

            $enabledIcons = $_POST['enabled_icons'] ?? [];
            if (!is_array($enabledIcons)) $enabledIcons = [];

            try {
                $this->helper->saveEnabledIconNames($enabledIcons);
                msg($this->getLang('activation_ok'), 1);
                send_redirect(wl($ID, ['do' => 'admin', 'page' => 'fontello'], true, '&'));
            } catch (RuntimeException $exception) {
                msg(hsc($exception->getMessage()), -1);
            }

            return;
        }

        if (
            !isset($_FILES['fontellozip']) ||
            ($_FILES['fontellozip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            return;
        }

        if (!checkSecurityToken()) {
            msg($this->getLang('err_bad_token'), -1);
            return;
        }

        try {
            $this->helper->importPackage($_FILES['fontellozip']);
            msg($this->getLang('upload_ok'), 1);
            send_redirect(wl($ID, ['do' => 'admin', 'page' => 'fontello'], true, '&'));
        } catch (RuntimeException $exception) {
            msg(hsc($exception->getMessage()), -1);
        }
    }

    /**
     * Output the admin page.
     *
     * @return void
     */
    public function html()
    {
        global $lang;

        $package = $this->helper->getPackageInfo();

        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
        echo $this->locale_xhtml('intro');

        echo '<form action="" method="post" enctype="multipart/form-data"><div class="no">';
        formSecurityToken();
        echo '<p>';
        echo '<label for="fontellozip">' . hsc($this->getLang('label_zip')) . '</label><br />';
        echo '<input type="file" name="fontellozip" id="fontellozip" accept=".zip,application/zip" /> ';
        echo '<button type="submit">' . hsc($lang['btn_upload']) . '</button>';
        echo '</p>';
        echo '</div></form>';

        if ($package === null) {
            echo '<p>' . hsc($this->getLang('no_package')) . '</p>';
            return;
        }

        echo '<div class="table"><table class="inline"><tbody>';
        echo '<tr><th>' . hsc($this->getLang('current_zip')) . '</th><td>' . hsc($package['zip_name']) . '</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('current_prefix')) . '</th><td>' . hsc($package['prefix']) . '</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('current_count')) . '</th><td>' .
            (int) $package['icon_count'] . '</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('current_enabled')) . '</th><td>' .
            (int) $package['enabled_count'] . '</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('current_imported')) . '</th><td>' .
            hsc($package['imported_at'] ? $package['imported_at'] : $this->getLang('unknown')) . '</td></tr>';
        echo '</tbody></table></div>';

        echo '<h2>' . hsc($this->getLang('icons_heading')) . '</h2>';
        echo '<form action="" method="post"><div class="no">';
        formSecurityToken();
        echo '<input type="hidden" name="fontello_action" value="save_enabled" />';
        echo '<div class="table"><table class="inline"><thead><tr>';
        echo '<th>' . hsc($this->getLang('icon_enabled')) . '</th>';
        echo '<th>' . hsc($this->getLang('icon_name')) . '</th>';
        echo '<th>' . hsc($this->getLang('icon_class')) . '</th>';
        echo '<th>' . hsc($this->getLang('icon_preview')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($package['icons'] as $icon) {
            $fieldId = 'fontello-enabled-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $icon['name']);
            echo '<tr>';
            echo '<td><input type="checkbox" name="enabled_icons[]" id="' . hsc($fieldId) . '" value="' .
                hsc($icon['name']) . '"' . (!empty($icon['enabled']) ? ' checked="checked"' : '') . ' /></td>';
            echo '<td><label for="' . hsc($fieldId) . '"><code>' . hsc($icon['name']) . '</code></label></td>';
            echo '<td><code>' . hsc($icon['class']) . '</code></td>';
            echo '<td><span class="fontello-icon ' . hsc($icon['class']) . '" aria-hidden="true"></span></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<p><button type="submit">' . hsc($this->getLang('save_activation')) . '</button></p>';
        echo '</div></form>';
    }
}
