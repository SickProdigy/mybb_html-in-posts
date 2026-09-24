<?php
/***************************************************************************
 *
 *  HTML in Posts plugin (/inc/plugins/htmlposts.php)
 *  Author: Diogo Parrinha and SickProdigy
 *  Copyright: © 2026 SickProdigy
 *
 *
 *  License: license.txt
 *
 *  This plugin adds the possibility to use HTML in posts.
 *
 ***************************************************************************/

/****************************************************************************
	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
****************************************************************************/

// do NOT remove for security reasons!
if(!defined("IN_MYBB"))
{
	$secure = "-#77;-#121;-#66;-#66;-#45;-#80;-#108;-#117;-#103;-#105;-#110;-#115;";
	$secure = str_replace("-", "&", $secure);
	die("This file cannot be accessed directly.".$secure);
}

// add hooks
$plugins->add_hook('parse_message_start', 'htmlposts_parse');
$plugins->add_hook('parse_message_end', 'htmlposts_restore_parser_options');
$plugins->add_hook('datahandler_post_insert_post', 'htmlposts_authorize_insert');
$plugins->add_hook('datahandler_post_insert_thread_post', 'htmlposts_authorize_insert');
$plugins->add_hook('datahandler_post_update', 'htmlposts_authorize_update');
$plugins->add_hook('datahandler_post_insert_merge', 'htmlposts_authorize_merge');
$plugins->add_hook('parse_quoted_message', 'htmlposts_escape_quoted_html');

function htmlposts_info()
{
	return array(
		"name"			=> "HTML in Posts",
		"description"	=> "This plugin adds the possibility to use HTML in posts.",
		"website"		=> "https://github.com/sickprodigy/mybb_html-in-posts",
		"author"		=> "Diogo Parrinha and SickProdigy",
		"authorsite"	=> "https://www.sickgaming.net",
		"version"		=> "1.9",
		"guid" 			=> "1e7c24cc5352de0fbc1e7be40ef1ad60",
		"compatibility"	=> "18*"
	);
}


function htmlposts_install()
{
	htmlposts_prepare_schema();
}

function htmlposts_is_installed()
{
	global $db;

	return $db->field_exists('htmlposts_authorized', 'posts');
}

function htmlposts_uninstall()
{
	global $db;

	if($db->field_exists('htmlposts_authorized', 'posts'))
	{
		$db->drop_column('posts', 'htmlposts_authorized');
	}

	$db->delete_query("settinggroups", "name = 'htmlposts'");
	$db->delete_query('settings', 'name IN (\'htmlposts_groups\',\'htmlposts_uids\',\'htmlposts_forums\')');
	rebuild_settings();
}

function htmlposts_ensure_schema()
{
	global $db;

	if($db->field_exists('htmlposts_authorized', 'posts'))
	{
		return false;
	}

	if($db->type == 'pgsql')
	{
		$type = "smallint NOT NULL default '0'";
	}
	else if($db->type == 'sqlite')
	{
		$type = "integer NOT NULL default '0'";
	}
	else
	{
		$type = "tinyint(1) unsigned NOT NULL default '0'";
	}

	$db->add_column('posts', 'htmlposts_authorized', $type);
	return true;
}

function htmlposts_has_legacy_settings()
{
	global $db;
	$query = $db->simple_select(
		'settings',
		'sid',
		"name = 'htmlposts_groups'",
		array('limit' => 1)
	);

	return (bool)$db->fetch_array($query);
}

function htmlposts_prepare_schema()
{
	$migrate_existing_posts = htmlposts_has_legacy_settings();
	$schema_added = htmlposts_ensure_schema();

	if($schema_added && $migrate_existing_posts)
	{
		htmlposts_migrate_existing_authorizations();
	}
}

function htmlposts_migrate_existing_authorizations()
{
	global $db, $mybb;

	$forum_setting = isset($mybb->settings['htmlposts_forums'])
		? trim((string)$mybb->settings['htmlposts_forums'])
		: '';
	if($forum_setting == '')
	{
		return;
	}

	$where_parts = array();
	if($forum_setting != '-1')
	{
		$forums = htmlposts_parse_id_list($forum_setting);
		if(empty($forums))
		{
			return;
		}
		$where_parts[] = 'fid IN ('.implode(',', $forums).')';
	}

	$group_setting = isset($mybb->settings['htmlposts_groups'])
		? trim((string)$mybb->settings['htmlposts_groups'])
		: '';
	if($group_setting == '-1')
	{
		$db->update_query(
			'posts',
			array('htmlposts_authorized' => 1),
			implode(' AND ', $where_parts)
		);
		return;
	}

	$authorized_uids = isset($mybb->settings['htmlposts_uids'])
		? htmlposts_parse_id_list($mybb->settings['htmlposts_uids'])
		: array();
	$allowed_groups = htmlposts_parse_id_list($group_setting);

	if(!empty($allowed_groups))
	{
		$query = $db->simple_select('users', 'uid,usergroup,additionalgroups');
		while($user = $db->fetch_array($query))
		{
			if(htmlposts_check_permissions($allowed_groups, $user))
			{
				$authorized_uids[(int)$user['uid']] = (int)$user['uid'];
			}
		}
	}

	if(empty($authorized_uids))
	{
		return;
	}

	foreach(array_chunk($authorized_uids, 500, true) as $uid_chunk)
	{
		$chunk_where = $where_parts;
		$chunk_where[] = 'uid IN ('.implode(',', $uid_chunk).')';
		$db->update_query(
			'posts',
			array('htmlposts_authorized' => 1),
			implode(' AND ', $chunk_where)
		);
	}
}

function htmlposts_upsert_setting($setting, $migrate_blank_to_all = false)
{
	global $db;

	$query = $db->simple_select(
		'settings',
		'sid,value',
		"name = '".$db->escape_string($setting['name'])."'",
		array('limit' => 1)
	);
	$existing = $db->fetch_array($query);

	if(!$existing)
	{
		$db->insert_query('settings', $setting);
		return;
	}

	$update = $setting;
	unset($update['name'], $update['value']);
	if($migrate_blank_to_all && $existing['value'] === '')
	{
		$update['value'] = '-1';
	}

	$db->update_query('settings', $update, 'sid='.(int)$existing['sid']);
}

function htmlposts_activate()
{
	global $db;
	htmlposts_prepare_schema();

	// create settings group
	$query = $db->simple_select('settinggroups', 'gid', "name = 'htmlposts'", array('limit' => 1));
	$gid = (int)$db->fetch_field($query, 'gid');

	if(!$gid)
	{
		$insertarray = array(
			'name' => 'htmlposts',
			'title' => 'HTML in Posts',
			'description' => "Settings for HTML in Posts plugin.",
			'disporder' => 100,
			'isdefault' => 0
		);
		$db->insert_query("settinggroups", $insertarray);
		$gid = (int)$db->insert_id();
	}

	if(!$gid)
	{
		die("Failed to create the HTML in Posts settings group.");
	}

	// add settings
	$setting = array(
		"name"			=> "htmlposts_groups",
		"title"			=> "Allowed Groups",
		"description"	=> "Select the groups that may use HTML. Choose All Groups to allow every group, or None to allow only users listed below.",
		"optionscode"	=> "groupselect",
		"value"			=> '4',
		"disporder"		=> 1,
		"gid"			=> $gid
	);

	htmlposts_upsert_setting($setting, true);

	$setting = array(
		"name"			=> "htmlposts_uids",
		"title"			=> "Allowed Users",
		"description"	=> "Enter numeric user IDs separated by commas. The numeric ID appears as uid in the profile URL. Listed users are allowed even when their group is not selected; leave blank for no user overrides.",
		"optionscode"	=> "text",
		"value"			=> "",
		"disporder"		=> 2,
		"gid"			=> $gid
	);

	htmlposts_upsert_setting($setting);

	$setting = array(
		"name"			=> "htmlposts_forums",
		"title"			=> "Affected Forums",
		"description"	=> "Select the forums where this plugin applies. Choose All Forums to affect every forum, or None to disable the plugin in all forums.",
		"optionscode"	=> "forumselect",
		"value"			=> "-1",
		"disporder"		=> 3,
		"gid"			=> $gid
	);

	htmlposts_upsert_setting($setting, true);

	rebuild_settings();
}


function htmlposts_deactivate()
{
	// Keep settings and per-post authorization state for later reactivation.
}

function htmlposts_parse_id_list($ids)
{
	static $parsed_id_cache = array();
	$cache_key = (string)$ids;

	if(isset($parsed_id_cache[$cache_key]))
	{
		return $parsed_id_cache[$cache_key];
	}

	$parsed_ids = array();

	if($cache_key == '')
	{
		$parsed_id_cache[$cache_key] = $parsed_ids;
		return $parsed_id_cache[$cache_key];
	}

	foreach(explode(',', $cache_key) as $id)
	{
		$id = trim($id);
		if(!preg_match('/^[0-9]+$/D', $id))
		{
			continue;
		}

		$id = (int)$id;
		if($id > 0)
		{
			$parsed_ids[$id] = $id;
		}
	}

	$parsed_id_cache[$cache_key] = $parsed_ids;
	return $parsed_id_cache[$cache_key];
}

function htmlposts_get_author_groups(&$post)
{
	global $db;
	static $author_groups_cache = array();

	if(isset($post['usergroup']))
	{
		return true;
	}

	if(empty($post['uid']))
	{
		return false;
	}

	$uid = (int)$post['uid'];
	if(isset($author_groups_cache[$uid]))
	{
		$post['usergroup'] = $author_groups_cache[$uid]['usergroup'];
		$post['additionalgroups'] = $author_groups_cache[$uid]['additionalgroups'];
		return true;
	}

	$query = $db->simple_select('users', 'usergroup,additionalgroups', 'uid='.$uid, array('limit' => 1));
	$author_groups_cache[$uid] = $db->fetch_array($query);

	if(empty($author_groups_cache[$uid]))
	{
		return false;
	}

	$post['usergroup'] = $author_groups_cache[$uid]['usergroup'];
	$post['additionalgroups'] = $author_groups_cache[$uid]['additionalgroups'];
	return true;
}

function htmlposts_user_can_use_html($user, $fid)
{
	global $mybb;
	$uid = isset($user['uid']) ? (int)$user['uid'] : 0;
	$forum_setting = trim((string)$mybb->settings['htmlposts_forums']);

	if($forum_setting == '')
	{
		return false;
	}

	if($forum_setting != '-1')
	{
		$forums = htmlposts_parse_id_list($forum_setting);
		if(!isset($forums[(int)$fid]))
		{
			return false;
		}
	}

	if($mybb->settings['htmlposts_uids'] != '')
	{
		$uids = htmlposts_parse_id_list($mybb->settings['htmlposts_uids']);
		if(isset($uids[$uid]))
		{
			return true;
		}
	}

	$group_setting = trim((string)$mybb->settings['htmlposts_groups']);
	if($group_setting == '-1')
	{
		return true;
	}
	if($group_setting == '')
	{
		return false;
	}

	return htmlposts_check_permissions($group_setting, $user);
}

function htmlposts_saved_post_can_use_html(&$post)
{
	global $mybb;

	if(empty($post['htmlposts_authorized']))
	{
		return false;
	}

	if($mybb->settings['htmlposts_uids'] != '')
	{
		$uids = htmlposts_parse_id_list($mybb->settings['htmlposts_uids']);
		if(isset($uids[(int)$post['uid']]))
		{
			return htmlposts_user_can_use_html($post, $post['fid']);
		}
	}

	$group_setting = trim((string)$mybb->settings['htmlposts_groups']);
	if($group_setting != '' && $group_setting != '-1' && !htmlposts_get_author_groups($post))
	{
		return false;
	}

	return htmlposts_user_can_use_html($post, $post['fid']);
}

function htmlposts_schema_ready()
{
	global $db;
	static $ready;

	if($ready === null)
	{
		$ready = $db->field_exists('htmlposts_authorized', 'posts');
	}

	return $ready;
}

function htmlposts_authorize_insert(&$datahandler)
{
	global $mybb;

	if(!htmlposts_schema_ready())
	{
		return;
	}

	$authorized = (int)htmlposts_user_can_use_html($mybb->user, $datahandler->data['fid']);
	$datahandler->post_insert_data['htmlposts_authorized'] = $authorized;
	$datahandler->post_update_data['htmlposts_authorized'] = $authorized;
}

function htmlposts_authorize_update(&$datahandler)
{
	global $mybb;

	if(!htmlposts_schema_ready() || !isset($datahandler->data['message']))
	{
		return;
	}

	$datahandler->post_update_data['htmlposts_authorized'] = (int)htmlposts_user_can_use_html(
		$mybb->user,
		$datahandler->data['fid']
	);
}

function htmlposts_authorize_merge(&$datahandler)
{
	global $db, $mybb;

	if(!htmlposts_schema_ready() || empty($datahandler->pid))
	{
		return;
	}

	$authorized = (int)htmlposts_user_can_use_html($mybb->user, $datahandler->data['fid']);
	$db->update_query('posts', array('htmlposts_authorized' => $authorized), 'pid='.(int)$datahandler->pid);
}

function htmlposts_prefetch_quoted_authorizations($current_pid)
{
	global $db, $mybb, $replyto;
	static $source_authorization_cache = array();
	static $prefetched = false;

	if(!$prefetched)
	{
		$quoted_pids = array((int)$current_pid => (int)$current_pid);
		if(!empty($replyto))
		{
			$quoted_pids[(int)$replyto] = (int)$replyto;
		}
		if(!empty($mybb->cookies['multiquote']))
		{
			foreach(explode('|', $mybb->cookies['multiquote']) as $quoted_pid)
			{
				$quoted_pid = (int)$quoted_pid;
				if($quoted_pid > 0)
				{
					$quoted_pids[$quoted_pid] = $quoted_pid;
				}
			}
		}

		$query = $db->query(
			'SELECT p.pid,p.fid,p.uid,p.htmlposts_authorized,'.
			'COALESCE(u.usergroup, 0) AS usergroup,'.
			"COALESCE(u.additionalgroups, '') AS additionalgroups ".
			'FROM '.TABLE_PREFIX.'posts p '.
			'LEFT JOIN '.TABLE_PREFIX.'users u ON (u.uid=p.uid) '.
			'WHERE p.pid IN ('.implode(',', $quoted_pids).')'
		);
		while($source_post = $db->fetch_array($query))
		{
			$source_authorization_cache[(int)$source_post['pid']] =
				htmlposts_saved_post_can_use_html($source_post);
		}
		$prefetched = true;
	}

	return !empty($source_authorization_cache[(int)$current_pid]);
}

function htmlposts_escape_quoted_html(&$quoted_post)
{
	if(!is_array($quoted_post) || !isset($quoted_post['message']) || empty($quoted_post['pid']))
	{
		return $quoted_post;
	}

	if(htmlposts_prefetch_quoted_authorizations((int)$quoted_post['pid']))
	{
		return $quoted_post;
	}

	// MyBB copies the stored message into a new reply before this hook runs.
	// Keep unauthorized source HTML inert so it cannot inherit the replier's authorization.
	$quoted_post['message'] = str_replace(
		array('<', '>'),
		array('&lt;', '&gt;'),
		$quoted_post['message']
	);

	return $quoted_post;
}

// checks permissions for a certain user
function htmlposts_check_permissions($groups, $user)
{
	if(!is_array($groups))
	{
		$groups = htmlposts_parse_id_list($groups);
	}

	if(empty($groups) || empty($user) || !isset($user['usergroup']))
	{
		return false;
	}

	$user_groups = array((int)$user['usergroup']);

	if(!empty($user['additionalgroups']))
	{
		$user_groups = array_merge($user_groups, htmlposts_parse_id_list($user['additionalgroups']));
	}

	foreach($user_groups as $group)
	{
		if(isset($groups[(int)$group]))
		{
			return true;
		}
	}

	return false;
}

if (!class_exists("control_html"))
{
    class control_html
    {
        public $html_enabled;
        public $html_stack = array();

        function __construct()
        {
            global $parser;
            $this->html_enabled = isset($parser->options['allow_html']) ? (int)$parser->options['allow_html'] : 0;
        }

        function remember_html()
        {
            global $parser;
            $this->html_stack[] = isset($parser->options['allow_html']) ? (int)$parser->options['allow_html'] : 0;
        }

        function set_html($status)
        {
            $status = (int)$status;
            if ($status != 0 && $status != 1) return false;

            if ($status == 0 && $this->html_enabled == 1)
                return false;

            global $parser;
            $parser->options['allow_html'] = $status;
            global $parser_options;
            if (!empty($parser_options))
                $parser_options['allow_html'] = $status;

            return true;
        }

        function restore_html($restore_parser = true)
        {
            if (empty($this->html_stack)) return false;

            $status = array_pop($this->html_stack);

            if($restore_parser)
            {
                global $parser;
                $parser->options['allow_html'] = $status;
            }
            global $parser_options;
            if (!empty($parser_options))
                $parser_options['allow_html'] = $status;

            return true;
        }
    }
}

function htmlposts_restore_parser_options($message)
{
	global $control_html;

	if(is_object($control_html))
	{
		// MyBB validates output after this hook and must still see the current
		// post's HTML authorization. The next parse resets parser options itself.
		$control_html->restore_html(false);
	}

	return $message;
}

function htmlposts_parse(&$message)
{
	global $mybb, $db;

	if(THIS_SCRIPT == 'portal.php')
	{
		global $announcement;
		$mypost =& $announcement;
	}
	else
	{
		global $post;
		$mypost =& $post;
	}

	if (empty($mypost))
		return; // we're not in postbit so get out of here

	$previewpost = false;

	// we're previewing a post
	if (!empty($mybb->input['previewpost']) && (THIS_SCRIPT == "newthread.php" || THIS_SCRIPT == "newreply.php" || THIS_SCRIPT == "editpost.php"))
	{
		if (THIS_SCRIPT != "editpost.php")
		{
			global $fid;
			$mypost['fid'] = $fid; // no fid is set in $mypost['fid'] when previewing
			$mypost['usergroup'] = $mybb->user['usergroup'];
			$mypost['additionalgroups'] = $mybb->user['additionalgroups'];
			$previewpost = true;
		}
		else
		{
			global $fid;
			$mypost['fid'] = $fid; // no fid is set in $mypost['fid'] when previewing
		}

		$previewpost = true;
	}

	$forum_setting = trim((string)$mybb->settings['htmlposts_forums']);
	if($forum_setting == '')
		return;
	if($forum_setting != '-1')
	{
		$forums = htmlposts_parse_id_list($forum_setting);
		if(!isset($forums[(int)$mypost['fid']]))
			return;
	}

	global $parser, $control_html;

	if (!is_object($parser))
	{
		return; // unfortunately we cannot proceed without a $parser object created
	}

	// Create object if it doesn't exist
	if (!is_object($control_html))
		$control_html = new control_html();

	$control_html->remember_html();

	$authorized = $previewpost
		? htmlposts_user_can_use_html($mybb->user, $mypost['fid'])
		: htmlposts_saved_post_can_use_html($mypost);

	if(!$authorized)
	{
		$control_html->set_html(0);
		return;
	}

	if(!isset($parser->options['filter_badwords']) && !$previewpost) // we're probably parsing a signature, this is not defined there
	{
		// Disable HTML, or at least we'll try to, the function might refuse it
		$control_html->set_html(0);
		return;
	}

	// Enable HTML for allowed users :)
	$control_html->set_html(1);
}
