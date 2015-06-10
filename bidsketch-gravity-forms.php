<?php
/*
  Plugin Name: Gravity Forms Bidsketch Add-On
  Plugin URI: https://www.bidsketch.com
  Description: Integrates Gravity Forms with Bidsketch allowing form submissions to be automatically sent to your Bidsketch account
  Version: 1.0.0
  Author: Bidsketch and Orbisius
  Author URI: https://www.bidsketch.com/integrations/wordpress

  ------------------------------------------------------------------------
  Copyright 2014 Bidsketch

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

add_action('init', array('GF_Bidsketch_AddOn', 'init'));
register_activation_hook(__FILE__, array("GF_Bidsketch_AddOn", "add_permissions"));

require_once(dirname(__FILE__) . "/api/Bidsketch_API.class.php");
require_once(dirname(__FILE__) . "/data.php");

define('BIDSKETCH_GF_ADDON_DEV_ENV', !empty($_SERVER['DEV_ENV']));
define('BIDSKETCH_GF_ADDON_DEV_ENV_USE_FAKE_API', 0);

class GF_Bidsketch_AddOn_Util {

    /**
     * Mapping GF fields to Bidsketch fields
     * GF_Bidsketch_AddOn_Util::generateBidsketchFieldsSelect();
     * @param type $name
     * @param type $sel
     * @param type $options
     * @param type $attr
     * @return str
     */
    public static function generateBidsketchFieldsSelect($name = '', $sel = null, $options = array(), $attr = '') {
        $fields = array(
            '' => '',
            
            // req
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',

            // opt
            'name' => 'Company Name',
            'phone' => 'Phone',
            'alt_phone' => 'Alternative Phone',
            'alt_phone' => 'Alternative Phone',
            'website' => 'Website',
            'address_field_one' => 'Address Line 1',
            'address_field_two' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State',
            'postal_zip' => 'Postal/Zip Code',
            'country' => 'Country',
            'notes' => 'Notes',
        );

        $auto_guessed_val = array_key_exists($name, $fields) ? $fields[$name] : null;

        if (is_null($auto_guessed_val) && array_key_exists($sel, $fields)) {
            $auto_guessed_val = $sel;
        }

        $res = self::generateHTMLSelect($name, $auto_guessed_val, $fields);

        return $res;
    }

    // generates HTML select
    // GF_Bidsketch_AddOn_Util::generateHTMLSelect()
    public static function generateHTMLSelect($name = '', $sel = null, $options = array(), $attr = '') {
        $name = esc_attr($name); // can contain []
        $id = sanitize_title($name); // ID can't
        
        $html = "\n" . '<select name="' . $name . '" id="' . $id . '" ' . $attr . '>' . "\n";

        foreach ($options as $key => $label) {
            $selected = $sel == $key ? ' selected="selected"' : '';
            $label = esc_attr($label);
            $key = esc_attr($key);
            $html .= "\t<option value='$key' $selected>$label</option>\n";
        }

        $html .= '</select>';
        $html .= "\n";

        return $html;
    }
}

class GF_Bidsketch_AddOn {
    private static $path = "bidsketch-gravity-forms-addon/bidsketch-gravity-forms-addon.php";
    private static $url = "https://www.bidsketch.com";
    private static $slug = "bidsketchgravityforms";
    private static $version = "1.0.0";
    private static $min_gravityforms_version = "1.7.6.11";
    private static $supported_fields = array(
        "checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", 
        "multiselect", "post_title", "post_tags", "post_custom_field", "post_content", "post_excerpt"
    );

    //Plugin starting point. Will load appropriate files
    public static function init() {
        //supports logging
        add_filter("gform_logging_supported", array("GF_Bidsketch_AddOn", "set_logging_supported"));

        /*if (basename($_SERVER['PHP_SELF']) == "plugins.php") {
            //loading translations
            load_plugin_textdomain('bidsketchgravityforms', FALSE, '/bidsketchgravityforms/languages');
        }*/

        if (!self::is_gravityforms_supported()) {
            return;
        }

        if (is_admin()) {
            //loading translations
            //load_plugin_textdomain('bidsketchgravityforms', FALSE, '/bidsketchgravityforms/languages');

            //creates a new Settings page on Gravity Forms' settings screen
            if (self::has_access("gravityforms_bidsketch")) {
                RGForms::add_settings_page("Bidsketch", array("GF_Bidsketch_AddOn", "settings_page"), self::get_base_url() . "/images/bidsketch_logo.png");
            }
        }

        //integrating with Members plugin
        if (function_exists('members_get_capabilities')) {
            add_filter('members_get_capabilities', array("GF_Bidsketch_AddOn", "members_get_capabilities"));
        }

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GF_Bidsketch_AddOn', 'create_menu'));

        if (self::is_bidsketch_page()) {
            //enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));
            
            //loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GF_Bidsketch_AddOn', 'tooltips'));

            //runs the setup when version changes
            self::setup();
        } else if (in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))) {
            add_action('wp_ajax_bidsketch_update_feed_active', array('GF_Bidsketch_AddOn', 'update_feed_active'));
            add_action('wp_ajax_gf_select_bidsketch_form', array('GF_Bidsketch_AddOn', 'select_bidsketch_form'));
            add_action('wp_ajax_gf_select_bidsketch_account', array('GF_Bidsketch_AddOn', 'select_bidsketch_account'));
        } else {
            //handling post submission.
            add_action("gform_after_submission", array('GF_Bidsketch_AddOn', 'export'), 10, 2);
        }
    }

    public static function update_feed_active() {
        check_ajax_referer('bidsketch_update_feed_active', 'bidsketch_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GF_Bidsketch_AddOnData::get_feed($id);
        GF_Bidsketch_AddOnData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_bidsketch_page() {
        $current_page = trim(strtolower(rgget("page")));
        $bidsketch_pages = array("gf_bidsketch");

        return in_array($current_page, $bidsketch_pages);
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup() {
        if (get_option("gf_bidsketch_version") != self::$version) {
            GF_Bidsketch_AddOnData::update_table();
            update_option("gf_bidsketch_version", self::$version);
        }
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips) {
        $bidsketch_tooltips = array(
            "bidsketch_accounts" => "<h6>" . __("Bidsketch Accounts", "bidsketchgravityforms") . "</h6>" . __("Pick the account you want to send the data to.", "bidsketchgravityforms"),
            "bidsketch_contact_list" => "<h6>" . __("Bidsketch Campaign", "bidsketchgravityforms") . "</h6>" . __("Select the Bidsketch campaign you would like to add your contacts to.", "bidsketchgravityforms"),
            "bidsketch_gravity_form" => "<h6>" . __("Gravity Form", "bidsketchgravityforms") . "</h6>" . __("Select the Gravity Form you would like to integrate with Bidsketch. Contacts generated by this form will be automatically added to your Bidsketch account.", "bidsketchgravityforms"),
            "bidsketch_map_fields" => "<h6>" . __("Map Fields", "bidsketchgravityforms") . "</h6>" . __("Associate your Bidsketch merge variables to the appropriate Gravity Form fields by selecting.", "bidsketchgravityforms"),
            "bidsketch_map_fields_unmapped_warning" => __("Unmapped fields will NOT be sent to Bidsketch. Also email must be present as well.", "bidsketchgravityforms"),
            "bidsketch_optin_condition" => "<h6>" . __("Opt-In Condition", "bidsketchgravityforms") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to Bidsketch when the condition is met. When disabled all form submissions will be exported.", "bidsketchgravityforms"),
            "bidsketch_double_optin" => "<h6>" . __("Double Opt-In", "bidsketchgravityforms") . "</h6>" . __("When the double opt-in option is enabled, Bidsketch will send a confirmation email to the user and will only add them to your Bidsketch campaign upon confirmation.", "bidsketchgravityforms")
        );
        return array_merge($tooltips, $bidsketch_tooltips);
    }

    //Creates bidsketch left nav menu under Forms
    public static function create_menu($menus) {
        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_bidsketch");

        if (!empty($permission)) {
            $menus[] = array(
                "name" => "gf_bidsketch",
                "label" => __("Bidsketch", "bidsketchgravityforms"),
                "callback" => array("GF_Bidsketch_AddOn", "bidsketch_page"), 
                "permission" => $permission,
            );
        }

        return $menus;
    }

    public static function settings_page() {
        if (rgpost("uninstall")) {
            check_admin_referer("uninstall", "gf_bidsketch_uninstall");
            self::uninstall();
            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Bidsketch Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>", "</a>"), "bidsketchgravityforms") ?></div>
            <?php
            return;
        } else if (rgpost("gf_bidsketch_submit")) {
            check_admin_referer("update", "gf_bidsketch_update");
            $settings = array(
                "apikey" => $_POST["gf_bidsketch_apikey"]
            );

            update_option("gf_bidsketch_settings", $settings);
        } else {
            $settings = get_option("gf_bidsketch_settings");
        }

        //feedback for api key
        $feedback_image = "";
        $is_valid_apikey = false;
        
        if (!empty($settings["apikey"])) {
            $is_valid_apikey = self::is_valid_login($settings["apikey"]);
            $icon = $is_valid_apikey ? self::get_base_url() . "/images/tick.png" : self::get_base_url() . "/images/stop.png";
            $feedback_image = "<img src='{$icon}' />";
        }

        //if username is blank, hide username/password fields
        if (empty($is_valid_apikey)) {
            $hidden_class = "hidden";
        }
        ?>
        <style>
            .valid_credentials{color:green;}
            .invalid_credentials{color:red;}
        </style>

        <form method="post" action="">
        <?php wp_nonce_field("update", "gf_bidsketch_update") ?>
            <h3><?php _e("Bidsketch Account Information", "bidsketchgravityforms") ?></h3>
            <p style="text-align: left;">
        <?php _e(sprintf("Bidsketch makes it easy to send email newsletters to your customers, manage your subscriber lists, and track campaign performance. Use Gravity Forms to collect customer information and automatically add them to your Bidsketch subscriber list. If you don't have a Bidsketch account, you can %ssign up for one here%s", 
                "<a href='https://www.bidsketch.com/' target='_blank'>", "</a>"), "bidsketchgravityforms") ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_bidsketch_apikey"><?php _e("Bidsketch API Token", "bidsketchgravityforms"); ?></label> </th>
                    <td>
                        <input type="text" id="gf_bidsketch_apikey" name="gf_bidsketch_apikey" value="<?php
                            echo empty($settings["apikey"]) ? "" : esc_attr($settings["apikey"]) ?>" size="50"/>
                        <?php echo $feedback_image ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_bidsketch_submit" class="button-primary" value="<?php _e("Save Settings", "bidsketchgravityforms") ?>" /></td>
                </tr>
            </table>
        </form>

        <form action="" method="post">
        <?php wp_nonce_field("uninstall", "gf_bidsketch_uninstall") ?>
        <?php if (GFCommon::current_user_can_any("gravityforms_bidsketch_uninstall")) { ?>
                <div class="hr-divider"></div>
                <h3><?php _e("Uninstall Bidsketch Add-On", "bidsketchgravityforms") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Bidsketch Feeds.", "bidsketchgravityforms") ?>
            <?php
            $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Bidsketch Add-On", "bidsketchgravityforms")
                    . '" class="button" onclick="return confirm(\''
                    . __("Warning! ALL Bidsketch Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "bidsketchgravityforms") . '\');"/>';
            echo apply_filters("gform_bidsketch_uninstall_button", $uninstall_button);
            ?>
                </div>
        <?php } ?>
        </form>
                <?php
            }

            public static function bidsketch_page() {
                $view = rgar($_GET, "view");

                if ($view == "edit") {
                    self::edit_page($_GET["id"]);
                } else {
                    self::list_page();
                }
            }

            //Displays the bidsketch feeds list page
            private static function list_page() {
                if (!self::is_gravityforms_supported()) {
                    die(__(sprintf("Bidsketch Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "bidsketchgravityforms"));
                }

                if (rgpost("action") == "delete") {
                    check_admin_referer("list_action", "gf_bidsketch_list");

                    $id = absint($_POST["action_argument"]);
                    GF_Bidsketch_AddOnData::delete_feed($id);
                    ?>
                    <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "bidsketchgravityforms") ?></div>
                    <?php
                } else if (!empty($_POST["bulk_action"])) {
                    check_admin_referer("list_action", "gf_bidsketch_list");
                    $selected_feeds = $_POST["feed"];
                    if (is_array($selected_feeds)) {
                        foreach ($selected_feeds as $feed_id)
                            GF_Bidsketch_AddOnData::delete_feed($feed_id);
                    }
                    ?>
                    <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "bidsketchgravityforms") ?></div>
                    <?php
                }
        ?>
        <div class="wrap">
            <img alt="<?php _e("Bidsketch Feeds", "bidsketchgravityforms") ?>" src="<?php echo self::get_base_url() ?>/images/bidsketch_logo.png"
                 style="float:left; margin:6px 7px 0 0;"/>
            <h2><?php _e("Feeds", "bidsketchgravityforms"); ?>
                <a class="button add-new-h2" href="admin.php?page=gf_bidsketch&view=edit&id=0"><?php _e("Add New", "bidsketchgravityforms") ?></a>
            </h2>


            <form id="feed_form" method="post">
        <?php wp_nonce_field('list_action', 'gf_bidsketch_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "bidsketchgravityforms") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "bidsketchgravityforms") ?> </option>
                            <option value='delete'><?php _e("Delete", "bidsketchgravityforms") ?></option>
                        </select>
        <?php
        echo '<input type="submit" class="button" value="' . __("Apply", "bidsketchgravityforms") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "bidsketchgravityforms") . __("\'Cancel\' to stop, \'OK\' to delete.", "bidsketchgravityforms") . '\')) { return false; } return true;"/>';
        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "bidsketchgravityforms") ?></th>
                            <!--<th scope="col" class="manage-column"><?php // _e("bidsketch List", "bidsketchgravityforms") ?></th>-->
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "bidsketchgravityforms") ?></th>
                            <!--<th scope="col" class="manage-column"><?php //_e("bidsketch List", "bidsketchgravityforms") ?></th>-->
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
        <?php
        $settings = GF_Bidsketch_AddOnData::get_feeds();
        if (is_array($settings) && sizeof($settings) > 0) {
            foreach ($settings as $setting) {
                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td>
                                        <img src="<?php echo self::get_base_url() ?>/images/active<?php
                                            echo intval($setting["is_active"]) ?>.png" alt="<?php
                                                echo $setting["is_active"]
                                                        ? __("Active", "bidsketchgravityforms")
                                                        : __("Inactive", "bidsketchgravityforms"); ?>"
                                                        title="<?php echo $setting["is_active"]
                                                                ? __("Active", "bidsketchgravityforms")
                                                                : __("Inactive", "bidsketchgravityforms"); ?>"
                                                                onclick="ToggleActive(this, <?php echo $setting['id'] ?>);" />
                                        <?php
                                        $field_map_exp = var_export($setting['meta']['field_map'], 1);
                                        if (!self::has_email_field($field_map_exp)) :?>
                                            <img src="<?php echo self::get_base_url() ?>/images/exclamation.png" 
                                                 title="The feed doesn't contain an email address and won't be sent to Bidsketch."
                                                 alt="The feed doesn't contain an email address and won't be sent to Bidsketch." />
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-title" colspan="2">
                                        <a href="admin.php?page=gf_bidsketch&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "bidsketchgravityforms") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a href="admin.php?page=gf_bidsketch&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "bidsketchgravityforms") ?>"><?php _e("Edit", "bidsketchgravityforms") ?></a>
                                                |
                                            </span>

                                            <span class="trash">
                                                <a title="<?php _e("Delete", "bidsketchgravityforms") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "bidsketchgravityforms") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "bidsketchgravityforms") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "bidsketchgravityforms") ?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <!--<td class="column-date"><?php echo $setting["meta"]["contact_list_name"] ?></td>-->
                                </tr>
                <?php
            }
        } else if (self::get_api()) {
            ?>
                            <tr>
                                <td colspan="3" style="padding:20px;">
            <?php _e(sprintf("You don't have any Bidsketch feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_bidsketch&view=edit&id=0">', "</a>"), "bidsketchgravityforms"); ?>
                                </td>
                            </tr>
            <?php
        } else {
            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                            <?php _e(sprintf("To get started, please configure your %sBidsketch Settings%s.", '<a href="admin.php?page=gf_settings&addon=bidsketch&subview=Bidsketch">', "</a>"), "bidsketchgravityforms"); ?>
                                </td>
                            </tr>
                                    <?php
                                }
                                ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id) {
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id) {
                var is_active = img.src.indexOf("active1.png") >= 0
                if (is_active) {
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title', '<?php _e("Inactive", "bidsketchgravityforms") ?>').attr('alt', '<?php _e("Inactive", "bidsketchgravityforms") ?>');
                }
                else {
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title', '<?php _e("Active", "bidsketchgravityforms") ?>').attr('alt', '<?php _e("Active", "bidsketchgravityforms") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar("action", "bidsketch_update_feed_active");
                mysack.setVar("bidsketch_update_feed_active", "<?php echo wp_create_nonce("bidsketch_update_feed_active") ?>");
                mysack.setVar("feed_id", feed_id);
                mysack.setVar("is_active", is_active ? 0 : 1);
                mysack.encVar("cookie", document.cookie, false);
                mysack.onError = function() {
                    alert('<?php _e("Ajax error while updating feed", "bidsketchgravityforms") ?>')
                };
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
    }

    /**
     *
     * @param type $username_or_apikey
     * @return bool
     */
    private static function is_valid_login($username_or_apikey, $password = null) {
        $api = self::get_api($username_or_apikey);
        
        if (!is_null($api)) {
            self::log_debug("Login valid: true");
        } else {
            self::log_error("Login valid: false.");
        }

        return is_null($api) ? false : true;
    }

    private static function get_api($api_key = null) {
        static $api = null;
        
        if (!is_null($api)) { // if it has worked once let's not stress Bidsketch's server too much.
            return $api;
        }

        if (empty($api_key)) {
            $settings = get_option("gf_bidsketch_settings"); // global bidsketch settings
            
            if (empty($settings["apikey"])) {
                self::log_debug("API credentials are not set");
                return null;
            } else {
                $api_key = $settings["apikey"];
            }
        }
        
        self::log_debug("Retrieving API Info for key: [$api_key]");

        try {
            if (BIDSKETCH_GF_ADDON_DEV_ENV && BIDSKETCH_GF_ADDON_DEV_ENV_USE_FAKE_API) {
                require_once(dirname(__FILE__) . "/api/Bidsketch_API_Fake.class.php");
                $api = new Bidsketch_API_Gravity_Fake($api_key);
            } else {
                $api = new Bidsketch_API_Gravity($api_key);
            }

            /*$accounts = $api->get_clients();

            if (!$accounts) {
                self::log_error("Cannot get accounts or received an error: " . $api->get_error_code() . " - " . $api->get_error_message());

                sleep(1); // give bidsketch a chance to catch its breath

                // try again
                $accounts = $api->get_clients();

                if (!$accounts) {
                    throw new Exception('Cannot get accounts. 2nd try. Giving up.');
                }
            }*/
        } catch (Exception $e) {
            self::log_error("Failed to set up the API. Error: " . $e->getMessage());

            if (!BIDSKETCH_GF_ADDON_DEV_ENV) {
                return null; // ::STMP uncomment when internet is back.
            }
        }

        self::log_debug("Successful API response received");

        return $api;
    }

    private static function edit_page() {
        ?>
        <style>
            .bidsketch_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold;}
            .bidsketch_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .gfield_required{color:red;}

            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0;}
            #bidsketch_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
            .bidsketch_group_condition{padding-bottom:6px; padding-left:20px;}

            /* apply the same css as .wp-admin select */
            .bidsketch_container .bidsketch_input {
                height: 28px;
                line-height: 28px;
                padding: 2px;
                vertical-align: middle;
            }

            .bidsketch_container .hide_me {
                display: none;
            }

            #bidsketch_optin_value_container {
                display: inline;
            }
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap bidsketch_container">
            <img alt="<?php _e("Bidsketch", "bidsketchgravityforms") ?>" style="margin: 6px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/bidsketch_logo.png"/>
            <h2><?php _e("Feed", "bidsketchgravityforms") ?></h2>
        <?php
        //getting bidsketch API
        $api = self::get_api();

        //ensures valid credentials were entered in the settings page
        if (!$api) {
            ?>
                <div><?php echo sprintf(__("We are unable to login to Bidsketch with the provided credentials. "
                        . "Please make sure they are valid in the %sSettings Page%s", "bidsketchgravityforms"),
                        "<a href='?page=gf_settings&addon=bidsketch'>", "</a>"); ?></div>
            <?php
            return;
        }

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["bidsketch_setting_id"]) ? $_POST["bidsketch_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array("double_optin" => true), "is_active" => true) : GF_Bidsketch_AddOnData::get_feed($id);

        if (!isset($config["meta"])) {
            $config["meta"] = array();
        }

        $form_id = 0;

        if (!empty($config['form_id'])) {
            $form_id = $config['form_id'];
        } elseif (!empty($_REQUEST['gf_bidsketch_form'])) {
            $form_id = absint($_REQUEST['gf_bidsketch_form']);
        }

        $merge_vars = !empty($form_id) ? self::get_form_merge_fields($form_id) : array();
        self::log_debug("Merge_Vars retrieved: " . print_r($merge_vars, true));

        // updating meta information
        if (rgpost("gf_bidsketch_submit")) {
            $is_valid = true;
            $field_map = array();
            $config["form_id"] = $form_id;

            foreach ($merge_vars as $var) {
                $field_name = "bidsketch_map_field_" . $var["tag"]; // input_1_5
                $mapped_field = trim(stripslashes($_POST[$field_name]));

                // Let's replace spaces with underscores just in case.
                $mapped_field = preg_replace('#\s+#si', '_', $mapped_field);
                $mapped_field = preg_replace('#_+#si', '_', $mapped_field);

                if (!empty($mapped_field)) {
                    $field_map[$var["tag"]] = $mapped_field;
                } else {
                    unset($field_map[$var["tag"]]);

                    if ($var["req"] == "Y") {
                        $is_valid = false;
                    }
                }
            }

            $config["meta"]["groups"] = array();

            $config["meta"]["field_map"] = $field_map;
            $config["meta"]["double_optin"] = rgpost("bidsketch_double_optin") ? true : false;

            $config["meta"]["optin_enabled"] = rgpost("bidsketch_optin_enable") ? true : false;
            $config["meta"]["optin_field_id"] = rgpost("bidsketch_optin_field_id");
            $config["meta"]["optin_operator"] = rgpost("bidsketch_optin_operator");
            $config["meta"]["optin_value"] = rgpost("bidsketch_optin_value");

            $config["meta"]["account_id"] = rgpost("gf_bidsketch_account_id");
            $config["meta"]["send_an_event_enabled"] = rgpost("bidsketch_send_an_event_enabled") ? true : false;
            $config["meta"]["bidsketch_send_event_name"] = trim(rgpost("bidsketch_send_event_name"));

            if ($is_valid) {
                $id = GF_Bidsketch_AddOnData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                //$config = GF_Bidsketch_AddOnData::get_feed($id);
                ?>
                    <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "bidsketchgravityforms"), "<a href='?page=gf_bidsketch'>", "</a>") ?></div>
                    <input type="hidden" name="bidsketch_setting_id" value="<?php echo $id ?>"/>
                    <?php
                } else {
                    ?>
                    <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "bidsketchgravityforms") ?></div>
                    <?php
                }
            }
            ?>
            <form method="post" action="">
                <input type="hidden" name="bidsketch_setting_id" value="<?php echo $id ?>"/>

                <div id="bidsketch_form_container" valign="top" class="margin_vertical_10">
                    <label for="gf_bidsketch_form" class="left_header"><?php _e("Gravity Form", "bidsketchgravityforms"); ?> <?php gform_tooltip("bidsketch_gravity_form") ?></label>

                    <select id="gf_bidsketch_form" name="gf_bidsketch_form" onchange="Bidsketch_SelectForm(jQuery('#gf_bidsketch_list').val(), jQuery(this).val());">
                        <option value=""><?php _e("Select a form", "bidsketchgravityforms"); ?></option>
                        <?php
                            $forms = RGFormsModel::get_forms();
                            foreach ($forms as $form) {
                                $selected = absint($form->id) == rgar($config, "form_id") ? "selected='selected'" : "";
                                ?>
                                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                                <?php
                            }
                        ?>
                    </select>
                    &nbsp;&nbsp;
                    <img src="<?php echo GF_Bidsketch_AddOn::get_base_url() ?>/images/loading.gif" id="bidsketch_wait" class="bidsketch_wait hide_me" />
                </div>
                <div id="bidsketch_field_group" valign="top" class="<?php echo empty($config["form_id"]) ? "hide_me" : "" ?>">
                    <div id="bidsketch_field_container" valign="top" class="margin_vertical_10" >
                        <label for="bidsketch_fields" class="left_header"><?php _e("Map Fields", "bidsketchgravityforms"); ?> <?php gform_tooltip("bidsketch_map_fields") ?></label>
                        <div id="bidsketch_field_list">
                            <?php
                            if ($form_id > 0) {
                                //getting field map UI
                                $mapping_buff = self::get_field_mapping($config, $form_id, $merge_vars);

                                //getting list of selection fields to be used by the optin
                                $form_meta = RGFormsModel::get_form_meta($form_id);

                                // is email mapped? so it must be surrounded by single or doulbe quotes (in a text field value)
                                if (!self::has_email_field($mapping_buff)) {
                                    $email_missing_warning = "<div style='backround:red;color:red;'>Data won't be sent to Bidsketch until an email field/address is added to this form.</div>";
                                    $mapping_buff = $mapping_buff . $email_missing_warning;
                                    $mapping_buff = str_replace('<!-- msg -->', $email_missing_warning, $mapping_buff); // we hid this
                                }

                                echo $mapping_buff;
                            }
                            ?>
                        </div>
                    </div>

                    <div id="bidsketch_optin_container" valign="top" class="margin_vertical_10">
                        <label for="bidsketch_optin" class="left_header"><?php _e("Opt-In Condition", "bidsketchgravityforms"); ?> <?php gform_tooltip("bidsketch_optin_condition") ?></label>
                        <div id="bidsketch_optin">
                            <table>
                                <tr>
                                    <td>
                                        <input type="checkbox" id="bidsketch_optin_enable" name="bidsketch_optin_enable" value="1"
                                            <?php echo rgar($config["meta"], "optin_enabled") ? "checked='checked'" : "" ?>/>
                                        <label for="bidsketch_optin_enable"><?php _e("Enable", "bidsketchgravityforms"); ?></label>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div id="bidsketch_optin_condition_field_container" <?php echo !rgar($config["meta"], "optin_enabled") ? "class='hide_me'" : "" ?>>
                                            <div id="bidsketch_optin_condition_fields">
                                                <?php _e("Export to Bidsketch if ", "bidsketchgravityforms") ?>
                                                <select id="bidsketch_optin_field_id" name="bidsketch_optin_field_id" class='optin_select'
                                                        onchange='
                                                        var x = Bidsketch_GetFieldValues(jQuery(this).val(), "", 20);
                                                        
                                                        if (x != "") {
                                                            jQuery("#bidsketch_optin_value_container").html(x);
                                                        }
                                                        '>
                                                </select>
                                                
                                                <select id="bidsketch_optin_operator" name="bidsketch_optin_operator" >
                                                    <option value="is" <?php echo rgar($config["meta"], "optin_operator") == "is" ? "selected='selected'" : "" ?>><?php _e("is", "bidsketchgravityforms") ?></option>
                                                    <option value="isnot" <?php echo rgar($config["meta"], "optin_operator") == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "bidsketchgravityforms") ?></option>
                                                    <option value=">" <?php echo rgar($config['meta'], 'optin_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "bidsketchgravityforms") ?></option>
                                                    <option value="<" <?php echo rgar($config['meta'], 'optin_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "bidsketchgravityforms") ?></option>
                                                    <option value="contains" <?php echo rgar($config['meta'], 'optin_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "bidsketchgravityforms") ?></option>
                                                    <option value="starts_with" <?php echo rgar($config['meta'], 'optin_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "bidsketchgravityforms") ?></option>
                                                    <option value="ends_with" <?php echo rgar($config['meta'], 'optin_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "bidsketchgravityforms") ?></option>
                                                </select>
                                                <!--<div id="bidsketch_optin_value_container" name="bidsketch_optin_value_container"></div>-->
                                                <input type="text" name="bidsketch_optin_value" id="bidsketch_optin_value"  placeholder='<?php _e("Enter value", "gravityforms"); ?>'
                                                       value="<?php echo empty($config["meta"]["optin_value"]) ? '' : esc_attr($config["meta"]["optin_value"]); ?>" class="bidsketch_input"/>
                                            </div>
                                            <div id="bidsketch_optin_condition_message" class="hide_me0">
                                                <?php _e("To create an Opt-In condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                            </div>
                                        </div> <!-- /bidsketch_optin_condition_field_container -->
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div id="bidsketch_groupings">
                            <?php
                            if (!empty($form_id)) {
                                $group_condition = array();
                                
                                //getting list of selection fields to be used by the optin
                                $form_meta = RGFormsModel::get_form_meta($form_id);
                                $selection_fields = GFCommon::get_selection_fields($form_meta, $config["meta"]["optin_field_id"]);
                            }
                            ?>
                        </div>

                        <script type="text/javascript">
                                <?php
                                    if (!empty($form_id)) {
                                ?>
                                //creating Javascript form object
                                form_bidsketch_config = <?php echo GFCommon::json_encode($config) ?>;
                                form = <?php echo GFCommon::json_encode($form_meta) ?>;

                                //initializing drop downs
                                jQuery(document).ready(function() {
                                    var selectedField = "<?php echo str_replace('"', '\"', empty($config["meta"]["optin_field_id"])
                                                ? ''
                                                : $config["meta"]["optin_field_id"]); ?>";

                                    var selectedValue = "<?php echo str_replace('"', '\"', empty($config["meta"]["optin_value"])
                                                ? ''
                                                : $config["meta"]["optin_value"]); ?>";

                                    Bidsketch_SetOptin(selectedField, selectedValue);

                                    <?php
                                    if (!empty($group_condition)) {
                                        foreach ($group_condition as $condition) {
                                            $input_name = "bidsketch_group_" . esc_js($condition["groupingName"]) . "_"
                                                    . esc_js($condition["groupName"]) . "_value";
                                            echo 'Bidsketch_SetGroupCondition("' . esc_js($condition["groupingName"]) . '","' 
                                                    . esc_js($condition["groupName"]) . '","' . esc_js($condition["selectedField"]) . '","'
                                                    . esc_js($condition["selectedValue"]) . '","' . $input_name . '");';
                                        }
                                    }
                                    ?>
                                });
                                <?php
                            }
                            ?>
                        </script>
                        <script>
                            jQuery(document).ready(function() {
                                Bidsketch_HandleOptInCondition();

                                jQuery('#bidsketch_optin_enable').on('click', function () {
                                    Bidsketch_HandleOptInCondition();
                                });
                            });
                        </script>
                    </div> <!-- /bidsketch_optin_container -->

                    <div id="bidsketch_submit_container" class="margin_vertical_10">
                        <input type="submit" name="gf_bidsketch_submit" value="<?php echo empty($id) ? __("Save", "bidsketchgravityforms") : __("Update", "bidsketchgravityforms"); ?>" class="button-primary"/>
                        <input type="button" value="<?php _e("Cancel", "bidsketchgravityforms"); ?>" class="button" onclick="javascript:document.location = 'admin.php?page=gf_bidsketch'" />
                    </div>
                </div>
            </form>
        </div>
        <script type="text/javascript">
        function Bidsketch_HandleOptInCondition() {
                if (jQuery('#bidsketch_optin_enable').prop('checked')) {
                    if (jQuery('#bidsketch_optin_field_id option').length == 0) {
                        var x = Bidsketch_GetFieldValues(jQuery('#bidsketch_optin_field_id').val(), '', 20);

                        if (1 || x != '') {
                            jQuery('#bidsketch_optin_value_container').html(x);
                        }
                    }

                    jQuery('#bidsketch_optin_condition_message').hide();
                    jQuery('#bidsketch_optin_condition_fields').show();
                    jQuery('#bidsketch_optin_condition_field_container').show('slow');

                    jQuery('#bidsketch_optin_value').focus();
                } else {
                    jQuery('#bidsketch_optin_condition_message').show();
                    jQuery('#bidsketch_optin_condition_fields').hide();
                    jQuery('#bidsketch_optin_condition_field_container').hide('slow');
                }
            }
            
            function Bidsketch_SelectList(listId) {
                if (listId) {
                    jQuery("#bidsketch_form_container").slideDown();
                    jQuery("#gf_bidsketch_form").val("");
                }
                else {
                    jQuery("#bidsketch_form_container").slideUp();
                    Bidsketch_EndBidsketch_SelectForm("");
                }
            }

            function Bidsketch_SelectForm(listId, formId) {
                if (!formId) {
                    jQuery("#bidsketch_field_group").slideUp();
                    return;
                }

                if (listId != '') {
                    jQuery("#bidsketch_wait").show();
                }

                jQuery("#bidsketch_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar("action", "gf_select_bidsketch_form");
                mysack.setVar("gf_select_bidsketch_form", "<?php echo wp_create_nonce("gf_select_bidsketch_form") ?>");
                mysack.setVar("form_id", formId);
                mysack.encVar("cookie", document.cookie, false);
                mysack.onError = function() {
                    jQuery("#bidsketch_wait").hide();
                    alert('<?php _e("Ajax error while selecting a form", "bidsketchgravityforms") ?>')
                };
                mysack.runAJAX();

                return true;
            }

            function Bidsketch_SetOptin(selectedField, selectedValue) {
                // Select the previous option when the select is first rendered
                // i.e. no elements (they are JS generated).
                if (jQuery("#bidsketch_optin_field_id option").length == 0
                        && (typeof form_bidsketch_config != 'undefined') ) {
                    setTimeout(function () {
                        jQuery("#bidsketch_optin_field_id").val(parseInt(form_bidsketch_config.meta.optin_field_id));
                        jQuery("#bidsketch_optin_value").val(form_bidsketch_config.meta.optin_value);

                    }, 10);
                }

                // Load form fields
                var optinConditionField = jQuery("#bidsketch_optin_field_id").val();

                jQuery("#bidsketch_optin_field_id").html(Bidsketch_GetSelectableFields(selectedField, 20));
                selectedValue = jQuery.trim(selectedValue);

                if (jQuery('#bidsketch_optin_enable').prop('checked') /*|| optinConditionField*/) {
                    jQuery("#bidsketch_optin_condition_message").hide();
                    jQuery("#bidsketch_optin_condition_fields").show();

                    var dyn_input = Bidsketch_GetFieldValues(optinConditionField, selectedValue, 20);

                    if (dyn_input !== '') {
                        jQuery("#bidsketch_optin_value_container").html(dyn_input);
                    }

                    if (selectedField.length > 0 && selectedValue.length > 0) {
                        jQuery("#bidsketch_optin_value").val(selectedValue);
                    }
                } else {
                    jQuery("#bidsketch_optin_condition_message").show();
                    jQuery("#bidsketch_optin_condition_fields").hide();
                }
            }

            function Bidsketch_SetGroupCondition(groupingName, groupname, selectedField, selectedValue) {
                return;

                //load form fields
                jQuery("#bidsketch_group_" + groupingName + "_" + groupname + "_field_id").html(Bidsketch_GetSelectableFields(selectedField, 20));
                var groupConditionField = jQuery("#bidsketch_group_" + groupingName + "_" + groupname + "_field_id").val();

                if (groupConditionField) {
                    jQuery("#bidsketch_group_" + groupingName + "_" + groupname + "_condition_message").hide();
                    jQuery("#bidsketch_group_" + groupingName + "_" + groupname + "_condition_fields").show();
                    jQuery("#bidsketch_group_" + groupingName + "_" + groupname + "_container").html(Bidsketch_GetFieldValues(groupConditionField, selectedValue, 20, "bidsketch_group_" + groupingName + "_" + groupname + "_value"));
                }
                else {
                    jQuery("#bidsketch_group_" + groupingName + "_" + groupname + "_condition_message").show();
                    jQuery("#bidsketch_group_" + groupingName + "_" + groupname + "_condition_fields").hide();
                }
            }


            function Bidsketch_EndBidsketch_SelectForm(fieldList, form_meta, grouping, groups) {
                //setting global form object
                form = form_meta;
                
                if (fieldList) {
                    Bidsketch_SetOptin("", "");

                    jQuery("#bidsketch_field_list").html(fieldList);
                    jQuery("#bidsketch_groupings").html(grouping);

                    for (var i in groups)
                        Bidsketch_SetGroupCondition(groups[i]["main"], groups[i]["sub"], "", "");

                    jQuery('.tooltip_bidsketch_groups').tooltip({
                        show: 500,
                        hide: 1000,
                        content: function() {
                            return jQuery(this).prop('title');
                        }
                    });

                    jQuery("#bidsketch_field_group").slideDown();
                } else {
                    jQuery("#bidsketch_field_group").slideUp();
                    jQuery("#bidsketch_field_list").html("");
                }
                
                jQuery("#bidsketch_wait").hide();
            }

            function Bidsketch_GetFieldValues(fieldId, selectedValue, labelMaxCharacters, inputName) {
                if (!inputName) {
                    inputName = 'bidsketch_optin_value';
                }

                if (!fieldId) {
                    return "";
                }

                var str = "";
                var field = Bidsketch_GetFieldById(fieldId);
                
                if (!field) {
                    return "";
                }

                var isAnySelected = false;

                if (field["type"] == "post_category" && field["displayAllCategories"]) {
                    str += '<?php $dd = wp_dropdown_categories(array("class" => "optin_select", "orderby" => "name", "id" => "bidsketch_optin_value",
                            "name" => "bidsketch_optin_value", "hierarchical" => true, "hide_empty" => 0, "echo" => false,));
                            echo str_replace("\n", "", str_replace("'", "\\'", $dd)); ?>';
                } else if (field.choices) {
                    str += '<select id="' + inputName + '" name="' + inputName + '" class="optin_select">';

                    for (var i = 0; i < field.choices.length; i++) {
                        var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                        var isSelected = fieldValue == selectedValue;
                        var selected = isSelected ? "selected='selected'" : "";
                        if (isSelected)
                            isAnySelected = true;

                        str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + Bidsketch_TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                    }

                    if (!isAnySelected && selectedValue) {
                        str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + Bidsketch_TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                    }
                    str += "</select>";
                } else if (jQuery('#' + inputName).length == 0) {
                    selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";

                    // create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
                    str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='"
                            + inputName + "' name='" + inputName + "' value='" + selectedValue + "' />";
                }

                return str;
            }

            function Bidsketch_GetFieldById(fieldId) {
                for (var i = 0; i < form.fields.length; i++) {
                    if (form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function Bidsketch_TruncateMiddle(text, maxCharacters) {
                if (text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function Bidsketch_GetSelectableFields(selectedFieldId, labelMaxCharacters) {
                var str = "";
                var inputType;

                for (var i = 0; i < form.fields.length; i++) {
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;

                    if (Bidsketch_IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + Bidsketch_TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function Bidsketch_IsConditionalLogicField(field) {
                inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                    "post_tags", "post_custom_field", "post_content", "post_excerpt"];

                var index = jQuery.inArray(inputType, supported_fields);

                return index >= 0;
            }

        </script>

        <?php
    }

    public static function add_permissions() {
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_bidsketch");
        $wp_roles->add_cap("administrator", "gravityforms_bidsketch_uninstall");
    }

    public static function selected($selected, $current) {
        return $selected === $current ? " selected='selected'" : "";
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities($caps) {
        return array_merge($caps, array("gravityforms_bidsketch", "gravityforms_bidsketch_uninstall"));
    }

    public static function disable_bidsketch() {
        delete_option("gf_bidsketch_settings");
    }

    public static function select_bidsketch_form() {
        check_ajax_referer("gf_select_bidsketch_form", "gf_select_bidsketch_form");
        $form_id = intval(rgpost("form_id"));
        $setting_id = intval(rgpost("setting_id"));

        $api = self::get_api();

        if (!$api) {
            die("Bidsketch_EndBidsketch_SelectForm();");
        }

        //getting configuration
        $config = GF_Bidsketch_AddOnData::get_feed($setting_id);

        //getting list of selection fields to be used by the optin
        $form_meta = RGFormsModel::get_form_meta($form_id);

        //getting field map UI
        $str = self::get_field_mapping($config, $form_id, array(), $form_meta);

        $group_names = array();
        
        //fields meta
        $buff = "Bidsketch_EndBidsketch_SelectForm('" . str_replace("'", "\'", $str) . "', "
                . GFCommon::json_encode($form_meta) . ", '" . str_replace("'", "\'", '')
                . "', " . str_replace("'", "\'", json_encode($group_names)) . " );";

        // new lines break the JS so get rid of them.
        $buff = str_replace(array("\r", "\n"), '', $buff);

        die($buff);
    }

    /**
     *
     * @param type $config
     * @param type $form_id
     * @param type $merge_vars
     * @param type $form_meta
     * @return array 
     */
    public static function get_form_merge_fields($form_id, $form_meta = array()) {
        static $mem_cache = array();
        $merge_vars = array();

        if (empty($form_meta)) {
            $form_meta = RGFormsModel::get_form_meta($form_id);
        }

        if (!empty($mem_cache[$form_id])) {
            return $mem_cache[$form_id];
        }

        if (empty($form_meta['fields'])) {
            return $merge_vars;
        }

        $email_req_set = false;

        foreach ($form_meta['fields'] as $rec) {
            $new_rec['id'] = $rec['id'];
            $new_rec['name'] = $rec['label'];
            $new_rec['field_type'] = $rec['type'];
            $new_rec['tag'] = "input_{$form_id}_{$rec['id']}"; // that's field id/name see get_field_input from \plugins\gravityforms\common.php
            $new_rec['req'] = !$email_req_set && preg_match('#email#si', $rec['label']) ? true : false; // set it for only one field

            $merge_vars[] = $new_rec;
        }

        $mem_cache[$form_id] = $merge_vars;
        
        return $merge_vars;
    }

    private static function get_field_mapping($config, $form_id, $merge_vars = array(), $form_meta = array()) {
        $str = "<table class='widefat' style='width:50%;' cellpadding='0' cellspacing='0'>"
                . "<tr><td class='bidsketch_col_heading'>"
                . __("Form Fields", "bidsketchgravityforms")
                . "</td>"
                . "<td class='bidsketch_col_heading'>"
                . __("Bidsketch Fields", "bidsketchgravityforms") . '<!-- tooltip -->'
                . "</td>"
                . "</tr>";

        $str .= "<tr>"
                . "<td colspan='2'>"
                . "Note: Unmapped fields will NOT be sent to Bidsketch. Also email must be present as well."
                . "<!-- msg -->"
                . "</td>"
                . "</tr>";

        if (!isset($config["meta"])) {
            $config["meta"] = array("field_map" => "");
        }

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form_id);

        if (empty($form_meta)) {
            $form_meta = RGFormsModel::get_form_meta($form_id);
        }

        if (empty($merge_vars) && !empty($form_meta['fields'])) {
            $merge_vars = self::get_form_merge_fields($form_id, $form_meta);
        }

        foreach ($merge_vars as $var) {
            $selected_field = rgar($config["meta"]["field_map"], $var["tag"]);
            $required = $var["req"] == "Y" || $var["req"] == 1 ? "<span class='gfield_required'>*</span>" : "";
            $error_class = ''; //$var["req"] == "Y" && empty($selected_field) && !empty($_POST["gf_bidsketch_submit"]) ? " feeds_validation_error" : "";
            $str .= "<tr class='$error_class'>"
                    . "<td class='bidsketch_field_cell'>" . ucwords($var["name"]) . " $required</td>"
                    . "<td class='bidsketch_field_cell'>"
                    . self::get_mapped_field_list($var, $selected_field, $form_fields) . "</td>"
                    . "</tr>";
        }
        
        $str .= "</table>";

        return $str;
    }

    public static function get_form_fields($form_id) {
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        // Adding default fields
        array_push($form["fields"], array("id" => "date_created", "label" => __("Entry Date", "bidsketchgravityforms")));
        array_push($form["fields"], array("id" => "ip", "label" => __("User IP", "bidsketchgravityforms")));
        array_push($form["fields"], array("id" => "source_url", "label" => __("Source Url", "bidsketchgravityforms")));
        array_push($form["fields"], array("id" => "form_title", "label" => __("Form Title", "bidsketchgravityforms")));
        
        $form = self::get_entry_meta($form);

        if (is_array($form["fields"])) {
            foreach ($form["fields"] as $field) {
                if (is_array(rgar($field, "inputs"))) {
                    //If this is an address field, add full name to the list
                    if (RGFormsModel::get_input_type($field) == "address")
                        $fields[] = array($field["id"], GFCommon::get_label($field) . " (" . __("Full", "bidsketchgravityforms") . ")");

                    //If this is a name field, add full name to the list
                    if (RGFormsModel::get_input_type($field) == "name")
                        $fields[] = array($field["id"], GFCommon::get_label($field) . " (" . __("Full", "bidsketchgravityforms") . ")");

                    foreach ($field["inputs"] as $input)
                        $fields[] = array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if (!rgar($field, "displayOnly")) {
                    $fields[] = array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function get_entry_meta($form) {
        $entry_meta = GFFormsModel::get_entry_meta($form["id"]);
        $keys = array_keys($entry_meta);
        foreach ($keys as $key) {
            array_push($form["fields"], array("id" => $key, "label" => $entry_meta[$key]['label']));
        }
        return $form;
    }

    private static function get_address($entry, $field_id) {
        $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
        $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
        $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
        $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
        $zip_value = trim($entry[$field_id . ".5"]);
        $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

        $address = $street_value;
        $address .=!empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
        $address .=!empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
        $address .=!empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
        $address .=!empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
        $address .=!empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

        return $address;
    }

    private static function get_name($entry, $field_id) {

        //If field is simple (one input), simply return full content
        $name = rgar($entry, $field_id);
        if (!empty($name))
            return $name;

        //Complex field (multiple inputs). Join all pieces and create name
        $prefix = trim(rgar($entry, $field_id . ".2"));
        $first = trim(rgar($entry, $field_id . ".3"));
        $last = trim(rgar($entry, $field_id . ".6"));
        $suffix = trim(rgar($entry, $field_id . ".8"));

        $name = $prefix;
        $name .=!empty($name) && !empty($first) ? " $first" : $first;
        $name .=!empty($name) && !empty($last) ? " $last" : $last;
        $name .=!empty($name) && !empty($suffix) ? " $suffix" : $suffix;
        return $name;
    }

    /**
     * Bidsketch is more relaxed so fields will be text.
     * 
     * @param type $rec
     * @param type $selected_field
     * @param type $fields
     * @param type $merge_vars
     * @return string
     */
    public static function get_mapped_field_list($rec, $selected_field, $fields, $merge_vars = array()) {
        $str = $value = '';
        $variable_name = $rec["tag"];

        $field_name = "bidsketch_map_field_" . $variable_name;
        /*$str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        
        foreach ($fields as $field) {
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . "</option>";
        }

        $str .= "</select>";*/
        if (!empty($selected_field)) {
            $value = $selected_field;
        } elseif (preg_match('#email#si', $rec['name'])) { // smat mapping
            $value = 'email';
            //$value = $rec['id'];
        }

        $value = esc_attr($value);

        //$str .= "<input type='text' name='$field_name' id='$field_name' value='$value' />\n";
        $str .= GF_Bidsketch_AddOn_Util::generateBidsketchFieldsSelect($field_name, $value);

        return $str;
    }

    public static function export($entry, $form, $is_fulfilled = false) {
        //Login to bidsketch
        $api = self::get_api();
        
        if (!$api) {
            self::log_debug(__METHOD__ . " trying to export but API not available.");
            return;
        }

        //getting all active feeds
        $feeds = GF_Bidsketch_AddOnData::get_feed_by_form($form["id"], true);
        
        foreach ($feeds as $feed) {
            //only export if user has opted in
            if (self::is_optin($form, $feed, $entry)) {
                self::export_feed($entry, $form, $feed, $api);
                //updating meta to indicate this entry has already been subscribed to bidsketch. This will be used to prevent duplicate subscriptions.
                self::log_debug("Marking entry " . $entry["id"] . " as subscribed");
                gform_update_meta($entry["id"], "bidsketch_is_subscribed", true);
            } else {
                self::log_debug("Opt-in condition not met; not subscribing entry " . $entry["id"] . " to list");
            }
        }
    }

    public static function has_bidsketch($form_id) {
        //Getting Mail Chimp settings associated with this form
        $config = GF_Bidsketch_AddOnData::get_feed_by_form($form_id);

        if (!$config)
            return false;

        return true;
    }

    /**
     * ::STODO:  subscribe the user.
     * don't check for subs status.
     * pull campaign
     *
     * @param type $entry
     * @param type $form
     * @param type $feed
     * @param type $api
     * @return boolean
     */
    public static function export_feed($entry, $form, $feed, $api) {
        $custom_fields4bidsketch = array();
        
        foreach ($feed["meta"]["field_map"] as $var_tag => $bidsketch_id_slug) { // tag: input_1_1 and field is 'email' (mapped var)
            $val = '';
           
            $numeric_field_id = $var_tag; // tag looks like: input_1_1
            $numeric_field_id = preg_replace('#^.*?input_\d+_#si', '', $numeric_field_id); // the field is prefixes by form ID

            $field_rec = RGFormsModel::get_field($form, $bidsketch_id_slug);

            if (empty($field_rec)) { // Let's try a different way to the the input element
                $field_rec = RGFormsModel::get_field($form, $numeric_field_id);
            }

            if (empty($field_rec)) {
                self::log_debug("Can't find this entry so proceed to next. Tag: [$var_tag], Numeric ID: [$numeric_field_id], Bidsketch ID Slug: [$bidsketch_id_slug]");
                continue;
            }

            $id = $field_rec['id'];

            // The value is available from the $entry array.
            if (!empty($entry[$numeric_field_id])) {
                $val = $entry[$numeric_field_id];
            } elseif (!empty($entry[$var_tag])) {
                $val = $entry[$var_tag];
            } elseif (!empty($entry[$bidsketch_id_slug])) {
                $val = $entry[$numeric_field_id];
            }

            // Field type: name combines First Name & Last Name.
            // we'll need to find keys that are prefixed by that field's ID such as 5.3 or 5.6 which should correspond to first and last names
            elseif ($field_rec['type'] == "name") {
                $regex = '#^' . preg_quote($id) . '\.#si';
                $name_keys_of_fields = preg_grep($regex, array_keys($entry));
                
                $first_name_key = array_shift($name_keys_of_fields);
                $last_name_key = array_shift($name_keys_of_fields);

                if (isset($entry[$first_name_key])) {
                    $custom_fields4bidsketch['first_name'] = $entry[$first_name_key];
                }

                if (isset($entry[$last_name_key])) {
                    $custom_fields4bidsketch['last_name'] = $entry[$last_name_key];
                }

                // we've parsed the fields so let's move to the next field.
                continue;
            } elseif ($field_rec['type'] == "checkbox") { // handle checkboxes by looping through their values e.g. ID.val1, ID.val2
                $val = array();

                for ($i = 1; $i < 20; $i++) {
                    $checkbox_name = "$numeric_field_id.$i";

                    if (!empty($entry[$checkbox_name])) {
                        $val[] = $entry[$checkbox_name];
                    }
                }
            } else {
                self::log_debug("Can't find value for this entry so proceed to next. Tag: [$var_tag], Numeric ID: [$numeric_field_id], Bidsketch ID Slugs: [$bidsketch_id_slug]");
                continue;
            }

            // Let's make sure that the email is always lowercase to avoid
            // situations where the record won't be saved because of case register issue.
            if (preg_match('#email#si', $bidsketch_id_slug)) {
                $bidsketch_id_slug = strtolower($bidsketch_id_slug);
            }

            $custom_fields4bidsketch[$bidsketch_id_slug] = $val;
        }

        if (empty($custom_fields4bidsketch['email'])) {
            self::log_debug("Email not mapped/found. Can't send to Bidsketch, sorry. Fields4Bidsketch: " . print_r($custom_fields4bidsketch, true));
            return false;
        }
        
        self::log_debug("Calling - add_client: params: " . print_r($custom_fields4bidsketch, true));

        try {
            $subscriber_rec = $api->add_client($custom_fields4bidsketch);
        } catch (Exception $e) {
            self::log_debug("Transaction failed" . $e->getMessage());
        }

        if (($subscriber_rec !== false) && !empty($subscriber_rec)) {
            self::log_debug("Transaction successful");
        } else {
            self::log_error("Transaction failed. Error " . $api->get_error_code() . " - " . $api->get_error_message());
        }
    }

    public static function uninstall() {
        if (!GF_Bidsketch_AddOn::has_access("gravityforms_bidsketch_uninstall"))
            die(__("You don't have adequate permission to uninstall Bidsketch Add-On.", "bidsketchgravityforms"));

        //droping all tables
        GF_Bidsketch_AddOnData::drop_tables();

        //removing options
        delete_option("gf_bidsketch_settings");
        delete_option("gf_bidsketch_version");

        //Deactivating plugin
        $plugin = "bidsketchgravityforms/bidsketch.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array) get_option('recently_activated'));
    }

    public static function is_optin($form, $settings, $entry) {
        $config = $settings["meta"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);

        if (empty($field) || !$config["optin_enabled"]) {
            return true;
        }

        $operator = isset($config["optin_operator"]) ? $config["optin_operator"] : "";
        $field_value = RGFormsModel::get_lead_field_value($entry, $field);
        $is_value_match = RGFormsModel::is_value_match($field_value, $config["optin_value"], $operator);
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array(), $entry);

        $is_optin = $is_value_match && $is_visible;

        return $is_optin;
    }

    private static function is_gravityforms_installed() {
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported() {
        if (class_exists("GFCommon")) {
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        } else {
            return false;
        }
    }

    protected static function has_access($required_permission) {
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if ($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    protected static function get_base_url() {
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected static function get_base_path() {
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

    public static function set_logging_supported($plugins) {
        $plugins[self::$slug] = "bidsketch";
        return $plugins;
    }

    private static function log_error($message) {
        if (class_exists("GFLogging")) {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
        }
    }

    private static function log_debug($message) {
        if (class_exists("GFLogging")) {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
        }
    }

    /**
     * This searches for text buffer to see if there is an email field.
     * It is supposed to be surrounded by single or double quotes.
     * @param string $buff
     * @return bool
     */
    public static function has_email_field($buff) {
        $yes = preg_match('#[\'"]email[\'"]#si', $buff);

        return $yes;
    }
}

if (!function_exists("rgget")) {

    function rgget($name, $array = null) {
        if (!isset($array))
            $array = $_GET;

        if (isset($array[$name]))
            return $array[$name];

        return "";
    }

}

if (!function_exists("rgpost")) {
    function rgpost($name, $do_stripslashes = true) {
        if (isset($_POST[$name])) {
            return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];
        }
        
        return "";
    }
}

if (!function_exists("rgar")) {

    function rgar($array, $name) {
        if (isset($array[$name]))
            return $array[$name];

        return '';
    }

}


if (!function_exists("rgempty")) {

    function rgempty($name, $array = null) {
        if (!$array)
            $array = $_POST;

        $val = rgget($name, $array);
        return empty($val);
    }

}


if (!function_exists("rgblank")) {

    function rgblank($text) {
        return empty($text) && strval($text) != "0";
    }

}
