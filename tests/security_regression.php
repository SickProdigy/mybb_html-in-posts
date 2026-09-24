<?php

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'newreply.php');

define('TABLE_PREFIX', 'mybb_');
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
	public $posts = array();
	public $user_queries = 0;
	public $post_queries = 0;
	private $quote_rows = array();

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
		else if($table == 'posts')
		{
			++$this->post_queries;
		}
		return array('table' => $table, 'where' => $where);
	}

	function query($sql)
	{
		++$this->post_queries;
		$this->quote_rows = array();
		preg_match('/p\.pid IN \(([^)]+)\)/', $sql, $matches);
		foreach(explode(',', isset($matches[1]) ? $matches[1] : '') as $pid)
		{
			$pid = (int)$pid;
			if(isset($this->posts[$pid]))
			{
				$this->quote_rows[] = $this->posts[$pid];
			}
		}
		return array('table' => 'quote_posts');
	}


	function fetch_array($query)
	{
		if($query['table'] == 'quote_posts')
		{
			return empty($this->quote_rows) ? array() : array_shift($this->quote_rows);
		}

		if($query['table'] == 'posts')
		{
			preg_match('/pid=(\d+)/', $query['where'], $matches);
			$pid = isset($matches[1]) ? (int)$matches[1] : 0;
			return isset($this->posts[$pid]) ? $this->posts[$pid] : array();
		}

		preg_match('/uid=(\d+)/', $query['where'], $matches);
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
$mybb->cookies = array('multiquote' => '40|41');
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

$db->users[40] = array('usergroup' => 4, 'additionalgroups' => '');
$db->users[41] = array('usergroup' => 2, 'additionalgroups' => '');
$db->posts[40] = array('pid' => 40, 'fid' => 2, 'uid' => 40, 'usergroup' => 4, 'additionalgroups' => '', 'htmlposts_authorized' => 1);
$db->posts[41] = array('pid' => 41, 'fid' => 2, 'uid' => 41, 'usergroup' => 2, 'additionalgroups' => '', 'htmlposts_authorized' => 0);

$authorized_quote = array(
	'pid' => 40,
	'message' => '<div>authorized source HTML</div>',
);
htmlposts_escape_quoted_html($authorized_quote);
htmlposts_test_assert(
	$authorized_quote['message'] === '<div>authorized source HTML</div>',
	'authorized source HTML remains unchanged when quoted'
);

$unauthorized_quote = array(
	'pid' => 41,
	'message' => '<script>alert(1)</script><img src=x onerror=alert(2)><!-- hidden -->[b]safe MyCode[/b]&lt;already escaped&gt;',
);
htmlposts_escape_quoted_html($unauthorized_quote);
htmlposts_test_assert(
	$unauthorized_quote['message'] === '&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;&lt;!-- hidden --&gt;[b]safe MyCode[/b]&lt;already escaped&gt;',
	'unauthorized source HTML is inert while MyCode and existing entities are preserved'
);
$escaped_quote = $unauthorized_quote['message'];
htmlposts_escape_quoted_html($unauthorized_quote);
htmlposts_test_assert($unauthorized_quote['message'] === $escaped_quote, 'quote escaping is idempotent for multiquote processing');
htmlposts_test_assert($db->post_queries === 1, 'all quoted source authorizations are prefetched in one query');

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
$db->user_queries = 0;
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
