<?php

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'newreply.php');

set_error_handler(function($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

class HtmlPostsTestPlugins
{
	function add_hook($hook, $function)
	{
	}
}

class HtmlPostsTestDb
{
	public $updates = array();
	public $users = array();
	public $user_queries = 0;

	function field_exists($field, $table)
	{
		return $field == 'htmlposts_authorized' && $table == 'posts';
	}

	function update_query($table, $data, $where)
	{
		$this->updates[] = array($table, $data, $where);
	}

	function simple_select($table, $fields, $where, $options = array())
	{
		if($table == 'users')
		{
			++$this->user_queries;
		}
		return $where;
	}

	function fetch_array($query)
	{
		preg_match('/uid=(\d+)/', $query, $matches);
		$uid = isset($matches[1]) ? (int)$matches[1] : 0;
		return isset($this->users[$uid]) ? $this->users[$uid] : array();
	}
}

class HtmlPostsTestParser
{
	public $options = array(
		'allow_html' => 0,
		'filter_badwords' => 1,
	);
}

function htmlposts_test_assert($condition, $message)
{
	if(!$condition)
	{
		fwrite(STDERR, "FAIL: ".$message."\n");
		exit(1);
	}
}

$plugins = new HtmlPostsTestPlugins();
$db = new HtmlPostsTestDb();
$db->users[10] = array('usergroup' => 4, 'additionalgroups' => '');
$parser = new HtmlPostsTestParser();
$parser_options = $parser->options;
$mybb = new stdClass();
$mybb->input = array();
$mybb->settings = array(
	'htmlposts_forums' => '-1',
	'htmlposts_uids' => '',
	'htmlposts_groups' => '4',
);
$mybb->user = array(
	'uid' => 10,
	'usergroup' => 4,
	'additionalgroups' => '',
);

require dirname(__DIR__).'/Upload/inc/plugins/htmlposts.php';

$normalized_ids = htmlposts_parse_id_list('4, 6, 4, , 0, -2, bad, 7x');
htmlposts_test_assert($normalized_ids === array(4 => 4, 6 => 6), 'ID settings are normalized and invalid values are ignored');

$mybb->settings['htmlposts_groups'] = '-1';
$all_groups_user = array('uid' => 30, 'usergroup' => 2, 'additionalgroups' => '');
htmlposts_test_assert(htmlposts_user_can_use_html($all_groups_user, 2), 'the native All Groups value allows every group');
$mybb->settings['htmlposts_groups'] = '';
htmlposts_test_assert(!htmlposts_user_can_use_html($all_groups_user, 2), 'the native None group value denies unlisted users');
$mybb->settings['htmlposts_groups'] = '4';
$mybb->settings['htmlposts_forums'] = '';
htmlposts_test_assert(!htmlposts_user_can_use_html($mybb->user, 2), 'the native None forum value disables all forums');
$mybb->settings['htmlposts_forums'] = '-1';

// A group change must not activate HTML in a post that was saved unauthorized.
$post = array(
	'pid' => 1,
	'fid' => 2,
	'uid' => 10,
	'usergroup' => 4,
	'additionalgroups' => '',
	'htmlposts_authorized' => 0,
);
$message = '<strong>unsafe</strong>';
htmlposts_parse($message);
htmlposts_test_assert($parser->options['allow_html'] === 0, 'stored denial survives a later allowed group');
htmlposts_restore_parser_options($message);

$post['htmlposts_authorized'] = 1;
htmlposts_parse($message);
htmlposts_test_assert($parser->options['allow_html'] === 1, 'stored authorization enables HTML');
htmlposts_restore_parser_options($message);
htmlposts_test_assert($parser->options['allow_html'] === 0, 'parser state is restored after an authorized post');

$mybb->input['previewpost'] = 1;
$fid = 2;
htmlposts_parse($message);
htmlposts_test_assert($parser->options['allow_html'] === 1, 'authorized preview behavior is preserved');
htmlposts_restore_parser_options($message);
$mybb->input = array();

$post['usergroup'] = 2;
htmlposts_parse($message);
htmlposts_test_assert($parser->options['allow_html'] === 0, 'current permission revocation disables stored HTML');
htmlposts_restore_parser_options($message);

$db->users[20] = array('usergroup' => 4, 'additionalgroups' => '');
$first_post = array('pid' => 2, 'fid' => 2, 'uid' => 20, 'htmlposts_authorized' => 1);
$second_post = array('pid' => 3, 'fid' => 2, 'uid' => 20, 'htmlposts_authorized' => 1);
htmlposts_test_assert(htmlposts_saved_post_can_use_html($first_post), 'fallback author lookup authorizes the first post');
htmlposts_test_assert(htmlposts_saved_post_can_use_html($second_post), 'cached author lookup authorizes the second post');
htmlposts_test_assert($db->user_queries === 1, 'an author is queried at most once per request');

$handler = new stdClass();
$handler->data = array('fid' => 2, 'message' => '<b>allowed</b>');
$handler->post_insert_data = array();
$handler->post_update_data = array();
$handler->pid = 23;

htmlposts_authorize_insert($handler);
htmlposts_test_assert($handler->post_insert_data['htmlposts_authorized'] === 1, 'authorized insert is persisted');

$mybb->user['usergroup'] = 2;
$handler->post_update_data = array();
htmlposts_authorize_update($handler);
htmlposts_test_assert($handler->post_update_data['htmlposts_authorized'] === 0, 'message edit re-evaluates authorization');

$handler->data = array('fid' => 2, 'subject' => 'subject only');
$handler->post_update_data = array();
htmlposts_authorize_update($handler);
htmlposts_test_assert(!isset($handler->post_update_data['htmlposts_authorized']), 'subject-only edit preserves authorization');

$handler->data = array('fid' => 2, 'message' => 'merged');
htmlposts_authorize_merge($handler);
$last_update = end($db->updates);
htmlposts_test_assert($last_update[1]['htmlposts_authorized'] === 0, 'merged reply persists authorization');
htmlposts_test_assert($last_update[2] === 'pid=23', 'merged reply updates the correct post');

echo "security regression tests passed\n";
restore_error_handler();
