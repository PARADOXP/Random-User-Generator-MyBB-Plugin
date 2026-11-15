<?php
/**
 * Random User Generator Plugin for MyBB
 * 
 * An advanced plugin that helps admins create users with random usernames
 * using external username generator APIs. Supports multiple username styles
 * including local names, fancy names, single/multi-word names.
 * 
 * @author Advanced MyBB Plugin
 * @version 1.0.0
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

// Add hooks for admin control panel
if(defined('IN_ADMINCP'))
{
    $plugins->add_hook("admin_user_menu", "random_user_generator_admin_menu");
    $plugins->add_hook("admin_user_action_handler", "random_user_generator_admin_action");
    $plugins->add_hook("admin_user_permissions", "random_user_generator_admin_permissions");
    $plugins->add_hook("admin_load", "random_user_generator_admin");
}

/**
 * Plugin information
 */
function random_user_generator_info()
{
    return array(
        "name"          => "Random User Generator",
        "description"   => "Advanced plugin that helps admins create users with random usernames using API. Supports local names, fancy names, single/multi-word usernames with various styles.",
        "website"       => "https:mybb.epizy.com",
        "author"        => "PARADOX987",
        "authorsite"    => "https://community.mybb.com/user-134311.html",
        "version"       => "1.1.0",
        "compatibility" => "18*",
        "codename"      => "random_user_generator"
    );
}

/**
 * Check if plugin is installed
 */
function random_user_generator_is_installed()
{
    global $db;
    return $db->table_exists("random_user_generator_history");
}

/**
 * Install plugin
 */
function random_user_generator_install()
{
    global $db;
    
    $collation = $db->build_create_table_collation();
    
    // Create table to store generation history
    if(!$db->table_exists('random_user_generator_history'))
    {
        switch($db->type)
        {
            case "pgsql":
                $db->write_query("CREATE TABLE ".TABLE_PREFIX."random_user_generator_history (
                    id serial,
                    uid int NOT NULL,
                    username varchar(120) NOT NULL,
                    email varchar(220) NOT NULL,
                    password varchar(255) NOT NULL,
                    generation_type varchar(50) NOT NULL,
                    generated_by int NOT NULL,
                    dateline bigint NOT NULL,
                    PRIMARY KEY (id)
                );");
                break;
            case "sqlite":
                $db->write_query("CREATE TABLE ".TABLE_PREFIX."random_user_generator_history (
                    id INTEGER PRIMARY KEY,
                    uid int NOT NULL,
                    username varchar(120) NOT NULL,
                    email varchar(220) NOT NULL,
                    password varchar(255) NOT NULL,
                    generation_type varchar(50) NOT NULL,
                    generated_by int NOT NULL,
                    dateline bigint NOT NULL
                );");
                break;
            default:
                $db->write_query("CREATE TABLE ".TABLE_PREFIX."random_user_generator_history (
                    id int unsigned NOT NULL auto_increment,
                    uid int unsigned NOT NULL,
                    username varchar(120) NOT NULL,
                    email varchar(220) NOT NULL,
                    password varchar(255) NOT NULL,
                    generation_type varchar(50) NOT NULL,
                    generated_by int unsigned NOT NULL,
                    dateline bigint unsigned NOT NULL,
                    PRIMARY KEY (id),
                    KEY uid (uid)
                ) ENGINE=MyISAM{$collation};");
                break;
        }
    }

    // Upgrade existing table with password column if missing
    if($db->table_exists('random_user_generator_history') && !$db->field_exists('password', 'random_user_generator_history'))
    {
        if($db->type == 'pgsql')
        {
            $db->write_query("ALTER TABLE ".TABLE_PREFIX."random_user_generator_history ADD COLUMN password varchar(255) NOT NULL DEFAULT ''");
        }
        elseif($db->type == 'sqlite')
        {
            $db->write_query("ALTER TABLE ".TABLE_PREFIX."random_user_generator_history ADD COLUMN password varchar(255) NOT NULL DEFAULT ''");
        }
        else
        {
            $db->write_query("ALTER TABLE ".TABLE_PREFIX."random_user_generator_history ADD password varchar(255) NOT NULL AFTER email");
        }
    }
}

/**
 * Uninstall plugin
 */
function random_user_generator_uninstall()
{
    global $db, $mybb;
    
    if($mybb->request_method != 'post')
    {
        global $page, $lang;
        $lang->load('config_random_user_generator');
        
        $page->output_confirm_action(
            'index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=random_user_generator',
            $lang->rug_uninstall_message,
            $lang->rug_uninstall
        );
    }
    
    // Delete settings group
    $db->delete_query('settinggroups', "name='random_user_generator'");
    
    // Remove the settings
    $db->delete_query('settings', "name LIKE 'rug_%'");
    
    // Rebuild settings
    rebuild_settings();
    
    // Drop table if confirmed
    if(!isset($mybb->input['no']))
    {
        $db->drop_table('random_user_generator_history');
    }
}

/**
 * Activate plugin
 */
function random_user_generator_activate()
{
    global $db, $lang;
    
    $lang->load('config_random_user_generator');
    
    // Settings group
    $group = array(
        'name'        => 'random_user_generator',
        'title'       => $db->escape_string($lang->rug_settings_title),
        'description' => $db->escape_string($lang->rug_settings_desc),
        'isdefault'   => 0
    );
    
    $query = $db->simple_select('settinggroups', 'gid', "name='random_user_generator'");
    
    if($gid = (int)$db->fetch_field($query, 'gid'))
    {
        $db->update_query('settinggroups', $group, "gid='{$gid}'");
    }
    else
    {
        $query = $db->simple_select('settinggroups', 'MAX(disporder) AS disporder');
        $disporder = (int)$db->fetch_field($query, 'disporder');
        $group['disporder'] = ++$disporder;
        $gid = (int)$db->insert_query('settinggroups', $group);
    }
    
    // Mark old settings for deletion
    $db->update_query('settings', array('description' => 'RUGDELETEMARKER'), "gid='{$gid}'");
    
    // Define settings
    $settings = array(
        'api_choice' => array(
            'title'       => $lang->rug_api_choice,
            'description' => $lang->rug_api_choice_desc,
            'optionscode' => "select\nrandomuser=Random User API\nuinames=UI Names API\nfakerapi=Faker API\ncustom=Custom API",
            'value'       => 'randomuser'
        ),
        'custom_api_url' => array(
            'title'       => $lang->rug_custom_api_url,
            'description' => $lang->rug_custom_api_url_desc,
            'optionscode' => 'text',
            'value'       => ''
        ),
        'default_usergroup' => array(
            'title'       => $lang->rug_default_usergroup,
            'description' => $lang->rug_default_usergroup_desc,
            'optionscode' => 'text',
            'value'       => '2'
        ),
        'default_password_length' => array(
            'title'       => $lang->rug_default_password_length,
            'description' => $lang->rug_default_password_length_desc,
            'optionscode' => 'text',
            'value'       => '12'
        ),
        'email_domain' => array(
            'title'       => $lang->rug_email_domain,
            'description' => $lang->rug_email_domain_desc,
            'optionscode' => 'text',
            'value'       => 'example.com'
        ),
        'send_welcome_email' => array(
            'title'       => $lang->rug_send_welcome_email,
            'description' => $lang->rug_send_welcome_email_desc,
            'optionscode' => 'yesno',
            'value'       => '0'
        ),
        'api_timeout' => array(
            'title'       => $lang->rug_api_timeout,
            'description' => $lang->rug_api_timeout_desc,
            'optionscode' => 'text',
            'value'       => '10'
        )
    );
    
    $disporder = 0;
    foreach($settings as $key => $setting)
    {
        $key = "rug_{$key}";
        
        $setting = array_map(array($db, 'escape_string'), $setting);
        
        ++$disporder;
        $setting = array_merge(
            array(
                'description' => '',
                'optionscode' => 'yesno',
                'value'       => 0,
                'disporder'   => $disporder
            ),
            $setting
        );
        
        $setting['name'] = $db->escape_string($key);
        $setting['gid'] = $gid;
        
        $query = $db->simple_select('settings', 'sid', "gid='{$gid}' AND name='{$setting['name']}'");
        
        if($sid = $db->fetch_field($query, 'sid'))
        {
            unset($setting['value']);
            $db->update_query('settings', $setting, "sid='{$sid}'");
        }
        else
        {
            $db->insert_query('settings', $setting);
        }
    }
    
    // Delete deprecated entries
    $db->delete_query('settings', "gid='{$gid}' AND description='RUGDELETEMARKER'");
    
    rebuild_settings();
}

/**
 * Deactivate plugin
 */
function random_user_generator_deactivate()
{
    // Nothing to do on deactivation
}

/**
 * Add menu item to admin panel
 */
function random_user_generator_admin_menu($sub_menu)
{
    global $lang;
    $lang->load('config_random_user_generator');
    
    $sub_menu['85'] = array(
        "id"    => "random_user_generator",
        "title" => $lang->rug_menu_title,
        "link"  => "index.php?module=user-random_user_generator"
    );
    
    return $sub_menu;
}

/**
 * Add action handler
 */
function random_user_generator_admin_action($action)
{
    $action['random_user_generator'] = array(
        "active" => "random_user_generator",
        "file"   => ""
    );
    
    return $action;
}

/**
 * Add admin permissions
 */
function random_user_generator_admin_permissions($admin_permissions)
{
    global $lang;
    $lang->load('config_random_user_generator');
    
    $admin_permissions['random_user_generator'] = $lang->rug_can_use;
    
    return $admin_permissions;
}

/**
 * Main admin page handler
 */
function random_user_generator_admin()
{
    global $mybb, $db, $page, $lang;
    
    if($mybb->get_input('module') != 'user-random_user_generator')
    {
        return;
    }
    
    // Auto-upgrade: add password column to existing tables
    if($db->table_exists('random_user_generator_history') && !$db->field_exists('password', 'random_user_generator_history'))
    {
        if($db->type == 'pgsql')
        {
            $db->write_query("ALTER TABLE ".TABLE_PREFIX."random_user_generator_history ADD COLUMN password varchar(255) NOT NULL DEFAULT ''");
        }
        elseif($db->type == 'sqlite')
        {
            $db->write_query("ALTER TABLE ".TABLE_PREFIX."random_user_generator_history ADD COLUMN password varchar(255) NOT NULL DEFAULT ''");
        }
        else
        {
            $db->write_query("ALTER TABLE ".TABLE_PREFIX."random_user_generator_history ADD password varchar(255) NOT NULL AFTER email");
        }
    }
    
    $lang->load('config_random_user_generator');
    
    $page->add_breadcrumb_item($lang->rug_menu_title);
    
    $action = $mybb->get_input('action');
    
    // Handle form submission
    $generated_users = array();
    $failed_users = 0;
    
    if($mybb->request_method == "post" && $action == "generate")
    {
        verify_post_check($mybb->get_input('my_post_key'));
        
        $generation_type = $mybb->get_input('generation_type');
        $num_users = (int)$mybb->get_input('num_users', MyBB::INPUT_INT);
        $usergroup = (int)$mybb->get_input('usergroup', MyBB::INPUT_INT);
        $username_length = (int)$mybb->get_input('username_length', MyBB::INPUT_INT);
        $avatar_style = $mybb->get_input('avatar_style');
        
        // Validate inputs
        $errors = array();
        
        if($num_users < 1 || $num_users > 100)
        {
            $errors[] = $lang->rug_error_invalid_number;
        }
        
        if(empty($generation_type))
        {
            $errors[] = $lang->rug_error_no_type;
        }
        
        if(empty($errors))
        {
            
            // Display header before generation
            $page->output_header($lang->rug_menu_title);
            
            $sub_tabs = array();
            $sub_tabs['generate'] = array(
                'title'       => $lang->rug_generate_users,
                'link'        => "index.php?module=user-random_user_generator",
                'description' => $lang->rug_generate_users_desc
            );
            $sub_tabs['history'] = array(
                'title'       => $lang->rug_history,
                'link'        => "index.php?module=user-random_user_generator&action=history",
                'description' => $lang->rug_history_desc
            );
            
            $page->output_nav_tabs($sub_tabs, 'generate');
            
            // Fetch all usernames in batch if possible (avoids N sequential HTTP requests)
            $batch_data = random_user_generator_fetch_batch($generation_type, $num_users, $username_length);
            
            if(!empty($batch_data))
            {
                // Process batch results
                foreach($batch_data as $data)
                {
                    $result = random_user_generator_create_user_with_data($data, $usergroup, $avatar_style);
                    
                    if($result['success'])
                    {
                        $generated_users[] = $result['user'];
                    }
                    else
                    {
                        $failed_users++;
                    }
                }
            }
            
            // Fallback: if batch returned fewer results than requested, generate remaining individually
            $remaining = $num_users - count($generated_users) - $failed_users;
            for($i = 0; $i < $remaining; $i++)
            {
                $result = random_user_generator_create_user($generation_type, $usergroup, $username_length, $avatar_style);
                
                if($result['success'])
                {
                    $generated_users[] = $result['user'];
                }
                else
                {
                    $failed_users++;
                }
            }
            
            // Display results
            if(!empty($generated_users))
            {
                $table = new Table;
                $table->construct_header($lang->rug_username);
                $table->construct_header($lang->rug_email);
                $table->construct_header($lang->rug_password);
                $table->construct_header($lang->rug_actions, array('width' => '20%'));
                
                foreach($generated_users as $user)
                {
                    $table->construct_cell(htmlspecialchars_uni($user['username']));
                    $table->construct_cell(htmlspecialchars_uni($user['email']));
                    $table->construct_cell(htmlspecialchars_uni($user['password']));
                    $table->construct_cell(
                        "<a href=\"index.php?module=user-users&action=edit&uid={$user['uid']}\">{$lang->rug_edit}</a>"
                    );
                    $table->construct_row();
                }
                
                $table->output($lang->sprintf($lang->rug_generated_success, count($generated_users)));
                
                if($failed_users > 0)
                {
                    $page->output_alert($lang->sprintf($lang->rug_generation_failures, $failed_users), 'error');
                }
            }
            else
            {
                $page->output_alert($lang->rug_no_users_generated, 'error');
            }
            
            $page->output_footer();
            exit;
        }
    }
    
    // Display history page
    if($action == "history")
    {
        $page->output_header($lang->rug_menu_title);
        
        $sub_tabs = array();
        $sub_tabs['generate'] = array(
            'title'       => $lang->rug_generate_users,
            'link'        => "index.php?module=user-random_user_generator",
            'description' => $lang->rug_generate_users_desc
        );
        $sub_tabs['history'] = array(
            'title'       => $lang->rug_history,
            'link'        => "index.php?module=user-random_user_generator&action=history",
            'description' => $lang->rug_history_desc
        );
        
        $page->output_nav_tabs($sub_tabs, 'history');
        
        // Get history from database
        $query = $db->simple_select(
            'random_user_generator_history',
            '*',
            '',
            array('order_by' => 'dateline', 'order_dir' => 'DESC', 'limit' => 100)
        );
        
        if($db->num_rows($query) > 0)
        {
            $table = new Table;
            $table->construct_header($lang->rug_username);
            $table->construct_header($lang->rug_email);
            $table->construct_header('Password');
            $table->construct_header($lang->rug_type);
            $table->construct_header($lang->rug_date);
            $table->construct_header($lang->rug_generated_by);
            
            while($row = $db->fetch_array($query))
            {
                $generator = get_user($row['generated_by']);
                $table->construct_cell(htmlspecialchars_uni($row['username']));
                $table->construct_cell(htmlspecialchars_uni($row['email']));
                $table->construct_cell(htmlspecialchars_uni($row['password'] ?? ''));
                $table->construct_cell(htmlspecialchars_uni($row['generation_type']));
                $table->construct_cell(my_date('relative', $row['dateline']));
                $table->construct_cell(htmlspecialchars_uni($generator['username']));
                $table->construct_row();
            }
            
            $table->output($lang->rug_history_title);
        }
        else
        {
            $page->output_alert($lang->rug_no_history, 'info');
        }
        
        $page->output_footer();
        exit;
    }
    
    // Display main generation form
    $page->output_header($lang->rug_menu_title);
    
    $sub_tabs = array();
    $sub_tabs['generate'] = array(
        'title'       => $lang->rug_generate_users,
        'link'        => "index.php?module=user-random_user_generator",
        'description' => $lang->rug_generate_users_desc
    );
    $sub_tabs['history'] = array(
        'title'       => $lang->rug_history,
        'link'        => "index.php?module=user-random_user_generator&action=history",
        'description' => $lang->rug_history_desc
    );
    
    $page->output_nav_tabs($sub_tabs, 'generate');
    
    // Show errors if any
    if(!empty($errors))
    {
        $page->output_inline_error($errors);
    }
    
    $form = new Form("index.php?module=user-random_user_generator&action=generate", "post");
    
    $form_container = new FormContainer($lang->rug_generation_options);
    
    // Generation type selector
    $generation_types = array(
        'local_name'      => $lang->rug_type_local_name,
        'fancy_name'      => $lang->rug_type_fancy_name,
        'single_word'     => $lang->rug_type_single_word,
        'two_words'       => $lang->rug_type_two_words,
        'multi_word'      => $lang->rug_type_multi_word,
        'username_number' => $lang->rug_type_username_number,
        'creative'        => $lang->rug_type_creative
    );
    
    $form_container->output_row(
        $lang->rug_generation_type . " <em>*</em>",
        $lang->rug_generation_type_desc,
        $form->generate_select_box('generation_type', $generation_types, $mybb->get_input('generation_type'), array('id' => 'generation_type')),
        'generation_type'
    );
    
    // Number of users
    $form_container->output_row(
        $lang->rug_num_users . " <em>*</em>",
        $lang->rug_num_users_desc,
        $form->generate_numeric_field('num_users', $mybb->get_input('num_users', MyBB::INPUT_INT) ?: 1, array('id' => 'num_users', 'min' => 1, 'max' => 100)),
        'num_users'
    );
    
    // Usergroup selector
    $query = $db->simple_select("usergroups", "gid, title", "gid != '1'", array('order_by' => 'title'));
    $usergroups = array();
    while($usergroup_option = $db->fetch_array($query))
    {
        $usergroups[$usergroup_option['gid']] = htmlspecialchars_uni($usergroup_option['title']);
    }
    
    $form_container->output_row(
        $lang->rug_usergroup . " <em>*</em>",
        $lang->rug_usergroup_desc,
        $form->generate_select_box('usergroup', $usergroups, $mybb->get_input('usergroup') ?: $mybb->settings['rug_default_usergroup'], array('id' => 'usergroup')),
        'usergroup'
    );
    
    // Username length option
    $form_container->output_row(
        $lang->rug_username_length,
        $lang->rug_username_length_desc,
        $form->generate_numeric_field('username_length', $mybb->get_input('username_length', MyBB::INPUT_INT) ?: 0, array('id' => 'username_length', 'min' => 0, 'max' => 50)),
        'username_length'
    );
    
    // Avatar style selector
    $avatar_styles = array(
        'avataaars'   => 'Avataaars (human avatars)',
        'bottts'      => 'Bottts (robot avatars)',
        'identicon'   => 'Identicon (identicons)',
        'initials'    => 'Initials (initials)',
        'lorelei'     => 'Lorelei (female avatars)',
        'adventurer'  => 'Adventurer (adventurer style)'
    );
    
    $form_container->output_row(
        'Avatar Style',
        'Select the DiceBear avatar style to assign to generated users.',
        $form->generate_select_box('avatar_style', $avatar_styles, $mybb->get_input('avatar_style') ?: 'avataaars', array('id' => 'avatar_style')),
        'avatar_style'
    );
    
    $form_container->end();
    
    $buttons[] = $form->generate_submit_button($lang->rug_generate_button);
    $form->output_submit_wrapper($buttons);
    
    $form->end();
    
    // Display API information
    $form_container = new FormContainer($lang->rug_api_info_title);
    $form_container->output_row(
        $lang->rug_current_api,
        '',
        random_user_generator_get_api_name($mybb->settings['rug_api_choice'])
    );
    $form_container->end();
    
    $page->output_footer();
}

/**
 * Create a random user
 */
function random_user_generator_create_user($generation_type, $usergroup, $username_length = 0, $avatar_style = 'avataaars')
{
    global $db, $mybb;
    
    require_once MYBB_ROOT."inc/datahandlers/user.php";
    
    // Get username from API
    $username_data = random_user_generator_fetch_username($generation_type, $username_length);
    
    if(!$username_data['success'])
    {
        return array('success' => false, 'error' => 'API_ERROR');
    }
    
    $username = $username_data['username'];
    $email = $username_data['email'];
    
    // Check for duplicate username and append number if needed
    $base_username = $username;
    $suffix = 1;
    while(random_user_generator_username_exists($username))
    {
        $username = $base_username . $suffix;
        $suffix++;
        
        // Update email to match
        $email_domain = $mybb->settings['rug_email_domain'] ?: 'example.com';
        $email_domain = str_replace('@', '', trim($email_domain));
        $email_username = strtolower(preg_replace('/[^a-z0-9]/', '', $username));
        $email = $email_username . '@' . $email_domain;
        
        if($suffix > 100)
        {
            return array('success' => false, 'error' => 'DUPLICATE_LIMIT');
        }
    }
    
    // Generate random password
    $password_length = (int)$mybb->settings['rug_default_password_length'];
    if($password_length < 8) $password_length = 12;
    
    $password = random_str($password_length, true);
    
    // Set up user handler
    $userhandler = new UserDataHandler('insert');
    
    // Generate DiceBear avatar URL
    $avatar_style = preg_replace('/[^a-z0-9_-]/', '', $avatar_style) ?: 'avataaars';
    $avatar_url = 'https://api.dicebear.com/7.x/' . $avatar_style . '/svg?seed=' . urlencode($username);
    
    // Set the data for the new user
    $new_user = array(
        "username"    => $username,
        "password"    => $password,
        "password2"   => $password,
        "email"       => $email,
        "email2"      => $email,
        "usergroup"   => $usergroup,
        "avatar"      => $avatar_url,
        "avatartype"  => "remote",
        "usertitle"   => "",
        "signature"   => "",
        "website"     => "",
        "skype"       => "",
        "google"      => "",
        "language"    => "",
        "timezone"    => "",
        "dateformat"  => "",
        "timeformat"  => "",
        "notepad"     => ""
    );
    
    $userhandler->set_data($new_user);
    
    // Validate the user
    if(!$userhandler->validate_user())
    {
        $errors = $userhandler->get_friendly_errors();
        return array('success' => false, 'error' => 'VALIDATION_ERROR', 'details' => $errors);
    }
    
    // Insert user
    $user_info = $userhandler->insert_user();
    
    // Log in history
    $db->insert_query('random_user_generator_history', array(
        'uid'             => (int)$user_info['uid'],
        'username'        => $db->escape_string($username),
        'email'           => $db->escape_string($email),
        'password'        => $db->escape_string($password),
        'generation_type' => $db->escape_string($generation_type),
        'generated_by'    => (int)$mybb->user['uid'],
        'dateline'        => TIME_NOW
    ));
    
    // Log admin action
    log_admin_action($user_info['uid'], $username);
    
    return array(
        'success'  => true,
        'user'     => array(
            'uid'      => $user_info['uid'],
            'username' => $username,
            'email'    => $email,
            'password' => $password
        )
    );
}

/**
 * Fetch username from API based on generation type
 */
function random_user_generator_fetch_username($generation_type, $username_length = 0)
{
    global $mybb;
    
    $api_choice = $mybb->settings['rug_api_choice'];
    $timeout = (int)$mybb->settings['rug_api_timeout'] ?: 10;
    
    $result = array('success' => false, 'username' => '', 'email' => '');
    
    try
    {
        switch($api_choice)
        {
            case 'randomuser':
                $result = random_user_generator_fetch_randomuser($generation_type, $timeout, $username_length);
                break;
                
            case 'uinames':
                $result = random_user_generator_fetch_uinames($generation_type, $timeout, $username_length);
                break;
                
            case 'fakerapi':
                $result = random_user_generator_fetch_fakerapi($generation_type, $timeout, $username_length);
                break;
                
            case 'custom':
                $result = random_user_generator_fetch_custom($generation_type, $timeout, $username_length);
                break;
                
            default:
                $result = random_user_generator_fetch_randomuser($generation_type, $timeout, $username_length);
                break;
        }
    }
    catch(Exception $e)
    {
        $result = array('success' => false);
    }
    
    // Fall back to local generation if API fails
    if(!$result['success'])
    {
        $result = random_user_generator_generate_local($generation_type, $username_length);
    }
    
    return $result;
}

/**
 * Fetch from Random User API
 */
function random_user_generator_fetch_randomuser($generation_type, $timeout, $username_length = 0)
{
    global $mybb;
    
    $url = "https://randomuser.me/api/";
    $timeout = max(5, (int)$mybb->settings['rug_api_timeout'] ?: 10);
    
    $response = random_user_generator_http_request($url, $timeout);
    
    if(!$response)
    {
        return array('success' => false);
    }
    
    $data = @json_decode($response, true);
    
    if(!$data || !isset($data['results'][0]))
    {
        return array('success' => false);
    }
    
    $user_data = $data['results'][0];
    
    // Generate username based on type
    $username = random_user_generator_format_username($generation_type, array(
        'first'  => $user_data['name']['first'],
        'last'   => $user_data['name']['last'],
        'login'  => $user_data['login']['username']
    ), $username_length);
    
    // Generate email
    $email_domain = $mybb->settings['rug_email_domain'] ?: 'example.com';
    $email_domain = str_replace('@', '', $email_domain);
    $email_domain = trim($email_domain);
    $email_username = strtolower(preg_replace('/[^a-z0-9]/', '', $username));
    $email = $email_username . '@' . $email_domain;
    
    return array(
        'success'  => true,
        'username' => $username,
        'email'    => $email
    );
}

/**
 * Fetch from UI Names API
 */
function random_user_generator_fetch_uinames($generation_type, $timeout, $username_length = 0)
{
    global $mybb;
    
    $url = "https://uinames.com/api/";
    $timeout = max(5, (int)$mybb->settings['rug_api_timeout'] ?: 10);
    
    $response = random_user_generator_http_request($url, $timeout);
    
    if(!$response)
    {
        return array('success' => false);
    }
    
    $data = @json_decode($response, true);
    
    if(!$data || !isset($data['name']))
    {
        return array('success' => false);
    }
    
    // Generate username based on type
    $username = random_user_generator_format_username($generation_type, array(
        'first' => $data['name'],
        'last'  => $data['surname']
    ), $username_length);
    
    // Generate email
    $email_domain = $mybb->settings['rug_email_domain'] ?: 'example.com';
    $email_domain = str_replace('@', '', $email_domain);
    $email_domain = trim($email_domain);
    $email_username = strtolower(preg_replace('/[^a-z0-9]/', '', $username));
    $email = $email_username . '@' . $email_domain;
    
    return array(
        'success'  => true,
        'username' => $username,
        'email'    => $email
    );
}

/**
 * Fetch from Faker API
 */
function random_user_generator_fetch_fakerapi($generation_type, $timeout, $username_length = 0)
{
    global $mybb;
    
    $url = "https://fakerapi.it/api/v1/persons?_quantity=1";
    $timeout = max(5, (int)$mybb->settings['rug_api_timeout'] ?: 10);
    
    $response = random_user_generator_http_request($url, $timeout);
    
    if(!$response)
    {
        return array('success' => false);
    }
    
    $data = @json_decode($response, true);
    
    if(!$data || !isset($data['data'][0]))
    {
        return array('success' => false);
    }
    
    $user_data = $data['data'][0];
    
    // Generate username based on type
    $username = random_user_generator_format_username($generation_type, array(
        'first' => $user_data['firstname'],
        'last'  => $user_data['lastname']
    ), $username_length);
    
    // Generate email
    $email_domain = $mybb->settings['rug_email_domain'] ?: 'example.com';
    $email_domain = str_replace('@', '', $email_domain);
    $email_domain = trim($email_domain);
    $email_username = strtolower(preg_replace('/[^a-z0-9]/', '', $username));
    $email = $email_username . '@' . $email_domain;
    
    return array(
        'success'  => true,
        'username' => $username,
        'email'    => $email
    );
}

/**
 * Fetch from custom API
 */
function random_user_generator_fetch_custom($generation_type, $timeout, $username_length = 0)
{
    global $mybb;
    
    $url = $mybb->settings['rug_custom_api_url'];
    
    if(empty($url))
    {
        return array('success' => false);
    }
    
    $timeout = max(5, (int)$mybb->settings['rug_api_timeout'] ?: 10);
    $response = random_user_generator_http_request($url, $timeout);
    
    if(!$response)
    {
        return array('success' => false);
    }
    
    $data = @json_decode($response, true);
    
    if(!$data)
    {
        return array('success' => false);
    }
    
    // Try to extract name fields (flexible parsing)
    $first = $data['first'] ?? $data['firstname'] ?? $data['name'] ?? 'User';
    $last = $data['last'] ?? $data['lastname'] ?? $data['surname'] ?? '';
    
    $username = random_user_generator_format_username($generation_type, array(
        'first' => $first,
        'last'  => $last
    ), $username_length);
    
    // Generate email
    $email_domain = $mybb->settings['rug_email_domain'] ?: 'example.com';
    $email_domain = str_replace('@', '', $email_domain);
    $email_domain = trim($email_domain);
    $email_username = strtolower(preg_replace('/[^a-z0-9]/', '', $username));
    $email = $email_username . '@' . $email_domain;
    
    return array(
        'success'  => true,
        'username' => $username,
        'email'    => $email
    );
}

/**
 * Generate a username locally without an external API
 */
function random_user_generator_generate_local($generation_type, $username_length = 0)
{
    global $mybb;

    $first_names = array('James','Mary','John','Patricia','Robert','Jennifer','Michael','Linda','David','Barbara','William','Elizabeth','Richard','Susan','Joseph','Jessica','Thomas','Sarah','Christopher','Karen','Charles','Lisa','Daniel','Nancy','Matthew','Betty','Anthony','Margaret','Mark','Sandra','Donald','Ashley','Steven','Dorothy','Paul','Kimberly','Andrew','Emily','Joshua','Donna','Kenneth','Michelle','Kevin','Carol','Brian','Amanda','George','Melissa','Timothy','Deborah','Ronald','Stephanie','Edward','Rebecca','Jason','Sharon','Jeffrey','Laura','Ryan','Cynthia','Jacob','Kathleen','Gary','Amy','Nicholas','Shirley','Eric','Angela','Jonathan','Helen','Stephen','Anna','Larry','Brenda','Justin','Pamela','Scott','Nicole','Brandon','Samantha','Samuel','Katherine','Frank','Christine','Raymond','Catherine','Gregory','Virginia','Benjamin','Kathryn','Patrick','Julie','Jack','Alice','Dennis','Heather','Jerry','Teresa','Tyler','Doris','Aaron','Evelyn','Jose','Cheryl');
    $last_names = array('Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez','Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin','Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson','Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores','Green','Adams','Nelson','Baker','Hall','Rivera','Campbell','Mitchell','Carter','Roberts','Gomez','Phillips','Evans','Turner','Diaz','Parker','Cruz','Edwards','Collins','Reyes','Stewart','Morris','Morales','Murphy','Cook','Rogers','Gutierrez','Ortiz','Morgan','Cooper','Peterson','Bailey','Reed','Kelly','Howard','Ramos','Kim','Cox','Ward','Richardson','Watson','Brooks','Chavez','Wood','James','Bennett','Gray','Mendoza','Ruiz','Hughes','Price','Alvarez','Castillo','Sanders','Patel','Myers','Long','Ross','Foster','Jimenez');

    $first = $first_names[array_rand($first_names)];
    $last = $last_names[array_rand($last_names)];
    
    $username = random_user_generator_format_username($generation_type, array(
        'first' => $first,
        'last'  => $last,
        'login' => strtolower($first) . mt_rand(100, 9999)
    ), $username_length);
    
    $email_domain = $mybb->settings['rug_email_domain'] ?: 'example.com';
    $email_domain = str_replace('@', '', trim($email_domain));
    $email_username = strtolower(preg_replace('/[^a-z0-9]/', '', $username));
    $email = $email_username . '@' . $email_domain;
    
    return array(
        'success'  => true,
        'username' => $username,
        'email'    => $email
    );
}

/**
 * Shared HTTP request helper using configured timeout
 */
function random_user_generator_http_request($url, $timeout = 10)
{
    if(function_exists('curl_init'))
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(3, (int)($timeout / 2)));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if($http_code >= 200 && $http_code < 300)
        {
            return $response;
        }
        return false;
    }
    
    return fetch_remote_file($url);
}

/**
 * Fetch multiple usernames in a single batch API call
 * Supports: randomuser.me, fakerapi.it
 */
function random_user_generator_fetch_batch($generation_type, $count, $username_length = 0)
{
    global $mybb;
    
    $api_choice = $mybb->settings['rug_api_choice'];
    $timeout = max(5, (int)$mybb->settings['rug_api_timeout'] ?: 10);
    $results = array();
    
    switch($api_choice)
    {
        case 'randomuser':
            $url = "https://randomuser.me/api/?results=" . (int)$count;
            $response = random_user_generator_http_request($url, $timeout);
            if($response)
            {
                $data = @json_decode($response, true);
                if($data && !empty($data['results']))
                {
                    foreach($data['results'] as $user_data)
                    {
                        $username = random_user_generator_format_username($generation_type, array(
                            'first' => $user_data['name']['first'],
                            'last'  => $user_data['name']['last'],
                            'login' => $user_data['login']['username']
                        ), $username_length);
                        
                        $results[] = array(
                            'username' => $username,
                            'first'    => $user_data['name']['first'],
                            'last'     => $user_data['name']['last']
                        );
                    }
                }
            }
            break;
            
        case 'fakerapi':
            $url = "https://fakerapi.it/api/v1/persons?_quantity=" . (int)$count;
            $response = random_user_generator_http_request($url, $timeout);
            if($response)
            {
                $data = @json_decode($response, true);
                if($data && !empty($data['data']))
                {
                    foreach($data['data'] as $user_data)
                    {
                        $username = random_user_generator_format_username($generation_type, array(
                            'first' => $user_data['firstname'],
                            'last'  => $user_data['lastname']
                        ), $username_length);
                        
                        $results[] = array(
                            'username' => $username,
                            'first'    => $user_data['firstname'],
                            'last'     => $user_data['lastname']
                        );
                    }
                }
            }
            break;
    }
    
    // If API returned fewer results than needed, fill remaining with local generation
    if(count($results) < $count)
    {
        $remaining = $count - count($results);
        for($i = 0; $i < $remaining; $i++)
        {
            $local = random_user_generator_generate_local($generation_type, $username_length);
            if($local['success'])
            {
                $results[] = array('username' => $local['username']);
            }
        }
    }
    
    return $results;
}

/**
 * Create a user from pre-fetched data (no API call)
 */
function random_user_generator_create_user_with_data($data, $usergroup, $avatar_style = 'avataaars')
{
    global $db, $mybb;
    
    require_once MYBB_ROOT."inc/datahandlers/user.php";
    
    $username = $data['username'];
    $email_domain = $mybb->settings['rug_email_domain'] ?: 'example.com';
    $email_domain = str_replace('@', '', trim($email_domain));
    $email_username = strtolower(preg_replace('/[^a-z0-9]/', '', $username));
    $email = $email_username . '@' . $email_domain;
    
    // Check for duplicate username and append number if needed
    $base_username = $username;
    $suffix = 1;
    while(random_user_generator_username_exists($username))
    {
        $username = $base_username . $suffix;
        $suffix++;
        
        if($suffix > 100)
        {
            return array('success' => false, 'error' => 'DUPLICATE_LIMIT');
        }
    }
    
    // Generate random password
    $password_length = (int)$mybb->settings['rug_default_password_length'];
    if($password_length < 8) $password_length = 12;
    $password = random_str($password_length, true);
    
    // Generate DiceBear avatar URL
    $avatar_style = preg_replace('/[^a-z0-9_-]/', '', $avatar_style) ?: 'avataaars';
    $avatar_url = 'https://api.dicebear.com/7.x/' . $avatar_style . '/svg?seed=' . urlencode($username);
    
    // Set up user handler
    $userhandler = new UserDataHandler('insert');
    
    $new_user = array(
        "username"    => $username,
        "password"    => $password,
        "password2"   => $password,
        "email"       => $email,
        "email2"      => $email,
        "usergroup"   => $usergroup,
        "avatar"      => $avatar_url,
        "avatartype"  => "remote",
        "usertitle"   => "",
        "signature"   => "",
        "website"     => "",
        "skype"       => "",
        "google"      => "",
        "language"    => "",
        "timezone"    => "",
        "dateformat"  => "",
        "timeformat"  => "",
        "notepad"     => ""
    );
    
    $userhandler->set_data($new_user);
    
    if(!$userhandler->validate_user())
    {
        return array('success' => false, 'error' => 'VALIDATION_ERROR');
    }
    
    $user_info = $userhandler->insert_user();
    
    $db->insert_query('random_user_generator_history', array(
        'uid'             => (int)$user_info['uid'],
        'username'        => $db->escape_string($username),
        'email'           => $db->escape_string($email),
        'password'        => $db->escape_string($password),
        'generation_type' => $db->escape_string('batch'),
        'generated_by'    => (int)$mybb->user['uid'],
        'dateline'        => TIME_NOW
    ));
    
    log_admin_action($user_info['uid'], $username);
    
    return array(
        'success'  => true,
        'user'     => array(
            'uid'      => $user_info['uid'],
            'username' => $username,
            'email'    => $email,
            'password' => $password
        )
    );
}

/**
 * Check if username already exists in the database
 */
function random_user_generator_username_exists($username)
{
    global $db;
    
    $query = $db->simple_select('users', 'uid', "username = '" . $db->escape_string($username) . "'", array('limit' => 1));
    
    return (bool)$db->num_rows($query);
}

/**
 * Clean username - remove special characters and accents
 */
function random_user_generator_clean_username($username)
{
    // Remove accents and special characters
    $username = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $username);
    
    // Remove any remaining non-alphanumeric characters except underscore and dot
    $username = preg_replace('/[^a-zA-Z0-9_.]/', '', $username);
    
    return $username;
}

/**
 * Format username based on generation type
 */
function random_user_generator_format_username($type, $data, $max_length = 0)
{
    $first = ucfirst(strtolower($data['first'] ?? 'User'));
    $last = ucfirst(strtolower($data['last'] ?? ''));
    $login = $data['login'] ?? '';
    
    // Clean names to remove special characters
    $first = random_user_generator_clean_username($first);
    $last = random_user_generator_clean_username($last);
    
    $random_num = mt_rand(10, 9999);
    
    $adjectives = array('Cool', 'Super', 'Mega', 'Ultra', 'Epic', 'Awesome', 'Legendary', 'Pro', 'Master', 'Elite');
    $nouns = array('Gamer', 'Player', 'User', 'Warrior', 'Knight', 'Hero', 'Champion', 'Legend', 'Star', 'Phoenix');
    
    $username = '';
    
    switch($type)
    {
        case 'local_name':
            // FirstLast format
            $username = $first . $last;
            break;
            
        case 'fancy_name':
            // First_Last or First.Last
            $separator = mt_rand(0, 1) ? '_' : '.';
            $username = $first . $separator . $last;
            break;
            
        case 'single_word':
            // Just first name with optional number
            $username = mt_rand(0, 1) ? $first : $first . $random_num;
            break;
            
        case 'two_words':
            // Two words combined
            $adj = $adjectives[array_rand($adjectives)];
            $noun = $nouns[array_rand($nouns)];
            $username = $adj . $noun;
            break;
            
        case 'multi_word':
            // Multiple words with separators
            $adj = $adjectives[array_rand($adjectives)];
            $separator = array('_', '.', '')[mt_rand(0, 2)];
            $username = $adj . $separator . $first . $separator . $last;
            break;
            
        case 'username_number':
            // Username with numbers
            $username = $first . $last . $random_num;
            break;
            
        case 'creative':
            // Creative combination
            $styles = array(
                $first . 'The' . $last,
                'The' . $first . $last,
                $first . $random_num . $last,
                strtolower($first) . '_' . strtolower($last) . '_' . $random_num,
                $adjectives[array_rand($adjectives)] . $first
            );
            $username = $styles[array_rand($styles)];
            break;
            
        default:
            $username = $first . $last;
            break;
    }
    
    // Apply length limit if specified
    if($max_length > 0 && strlen($username) > $max_length)
    {
        $username = substr($username, 0, $max_length);
    }
    
    return $username;
}

/**
 * Get API name for display
 */
function random_user_generator_get_api_name($api_choice)
{
    $apis = array(
        'randomuser' => 'Random User API (randomuser.me)',
        'uinames'    => 'UI Names API (uinames.com)',
        'fakerapi'   => 'Faker API (fakerapi.it)',
        'custom'     => 'Custom API'
    );
    
    return $apis[$api_choice] ?? 'Unknown';
}