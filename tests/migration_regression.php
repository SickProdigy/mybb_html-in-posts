<?php

define('IN_MYBB', 1);

set_error_handler(function($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

class HtmlPostsMigrationTestPlugins
{
	function add_hook($hook, $function)
	{
	}
}

class HtmlPostsMigrationTestDb
{
	public $type = 'mysqli';
	public $schema_exists = false;
	public $legacy_settings = true;
	public $columns_added = 0;
	public $updates = array();
	public $users = array();
	private $results = array();
	private $next_result = 1;

	function field_exists($field, $table)
	{
		return $this->schema_exists;
	}

	function add_column($table, $field, $type)
	{
		$this->schema_exists = true;
		++$this->columns_added;
	}

	function simple_select($table, $fields, $where = '', $options = array())
	{
		$rows = array();
		if($table == 'settings' && $this->legacy_settings)
		{
			$rows[] = array('sid' => 1);
		}
		else if($table == 'users')
		{
			$rows = $this->users;
		}

		$result = $this->next_result++;
		$this->results[$result] = array_values($rows);
		return $result;
	}

	function fetch_array($result)
	{
		if(empty($this->results[$result]))
		{
			return array();
		}
		return array_shift($this->results[$result]);
	}

	function update_query($table, $data, $where)
	{
		$this->updates[] = array($table, $data, $where);
	}
}

function htmlposts_migration_assert($condition, $message)
{
	if(!$condition)
	{
		fwrite(STDERR, "FAIL: ".$message."\n");
		exit(1);
	}
}

$plugins = new HtmlPostsMigrationTestPlugins();
$db = new HtmlPostsMigrationTestDb();
$db->users = array(
	array('uid' => 10, 'usergroup' => 4, 'additionalgroups' => ''),
	array('uid' => 11, 'usergroup' => 2, 'additionalgroups' => '4, 6'),
	array('uid' => 12, 'usergroup' => 2, 'additionalgroups' => ''),
);
$mybb = new stdClass();
$mybb->settings = array(
	'htmlposts_forums' => '2, 3',
	'htmlposts_uids' => '7',
	'htmlposts_groups' => '4',
);

require dirname(__DIR__).'/Upload/inc/plugins/htmlposts.php';

htmlposts_prepare_schema();
htmlposts_migration_assert($db->columns_added === 1, 'an upgrade adds the authorization column once');
htmlposts_migration_assert(count($db->updates) === 1, 'an upgrade performs one authorization snapshot update');
htmlposts_migration_assert(
	$db->updates[0][2] === 'fid IN (2,3) AND uid IN (7,10,11)',
	'the snapshot respects forums, explicit users, primary groups, and additional groups'
);

htmlposts_prepare_schema();
htmlposts_migration_assert($db->columns_added === 1, 'repeated schema preparation is idempotent');
htmlposts_migration_assert(count($db->updates) === 1, 'the authorization snapshot does not run twice');

$db = new HtmlPostsMigrationTestDb();
$db->legacy_settings = false;
htmlposts_prepare_schema();
htmlposts_migration_assert($db->columns_added === 1, 'a fresh install adds the authorization column');
htmlposts_migration_assert(empty($db->updates), 'a fresh install does not authorize historical posts');

echo "migration regression tests passed\n";
restore_error_handler();
