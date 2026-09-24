<?php

define('IN_MYBB', 1);

class HtmlPostsSettingsTestPlugins
{
	function add_hook($hook, $function)
	{
	}
}

class HtmlPostsSettingsTestDb
{
	public $type = 'mysqli';
	public $setting_group = array('gid' => 1, 'name' => 'htmlposts');
	public $settings = array();
	public $inserts = 0;

	function field_exists($field, $table)
	{
		return false;
	}

	function escape_string($value)
	{
		return addslashes($value);
	}

	function simple_select($table, $fields, $where = '', $options = array())
	{
		return array('table' => $table, 'where' => $where);
	}

	function fetch_field($query, $field)
	{
		if($query['table'] == 'settinggroups')
		{
			return $this->setting_group[$field];
		}
		return null;
	}

	function fetch_array($query)
	{
		if($query['table'] != 'settings' || !preg_match("/name = '([^']+)'/", $query['where'], $matches))
		{
			return array();
		}
		return isset($this->settings[$matches[1]]) ? $this->settings[$matches[1]] : array();
	}

	function insert_query($table, $data)
	{
		++$this->inserts;
		if($table == 'settings')
		{
			$data['sid'] = count($this->settings) + 1;
			$this->settings[$data['name']] = $data;
		}
		return $this->inserts;
	}

	function insert_id()
	{
		return 1;
	}

	function update_query($table, $data, $where)
	{
		if($table != 'settings' || !preg_match('/sid=(\d+)/', $where, $matches))
		{
			return;
		}

		foreach($this->settings as $name => $setting)
		{
			if((int)$setting['sid'] == (int)$matches[1])
			{
				$this->settings[$name] = array_merge($setting, $data);
				return;
			}
		}
	}
}

function rebuild_settings()
{
}

function htmlposts_settings_assert($condition, $message)
{
	if(!$condition)
	{
		fwrite(STDERR, "FAIL: ".$message."\n");
		exit(1);
	}
}

$plugins = new HtmlPostsSettingsTestPlugins();
$db = new HtmlPostsSettingsTestDb();
$db->settings = array(
	'htmlposts_groups' => array('sid' => 1, 'name' => 'htmlposts_groups', 'value' => '', 'optionscode' => 'text'),
	'htmlposts_uids' => array('sid' => 2, 'name' => 'htmlposts_uids', 'value' => '12, 14', 'optionscode' => 'text'),
	'htmlposts_forums' => array('sid' => 3, 'name' => 'htmlposts_forums', 'value' => '1, 2', 'optionscode' => 'text'),
);

require dirname(__DIR__).'/Upload/inc/plugins/htmlposts.php';

htmlposts_activate();
htmlposts_settings_assert($db->settings['htmlposts_groups']['value'] === '-1', 'legacy blank groups migrate to All Groups');
htmlposts_settings_assert($db->settings['htmlposts_groups']['optionscode'] === 'groupselect', 'groups use the native selector');
htmlposts_settings_assert($db->settings['htmlposts_forums']['value'] === '1, 2', 'existing forum IDs are preserved');
htmlposts_settings_assert($db->settings['htmlposts_forums']['optionscode'] === 'forumselect', 'forums use the native selector');
htmlposts_settings_assert($db->settings['htmlposts_uids']['value'] === '12, 14', 'user overrides are preserved');
htmlposts_settings_assert($db->inserts === 0, 'upgrade does not duplicate existing settings');

htmlposts_activate();
htmlposts_settings_assert($db->inserts === 0, 'repeated activation remains idempotent');

echo "settings regression tests passed\n";
