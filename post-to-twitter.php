<?php
/*
Plugin Name: Post to Twitter
Plugin URI: http://www.idmarketing.com.br/post-to-twitter/
Description: A very simple integration between your WordPress blog and <a href="http://twitter.com">Twitter</a>. Send your blog posts to Twitter in a simple way. <a href="options-general.php?page=post-to-twitter.php">Configure your settings here</a>.
Version: 0.8
Author: Nauro Rezende Jr
Author URI: http://www.idmarketing.com.br
*/

// Copyright (c) 2008 Nauro Rezende Jr. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// This is an add-on for WordPress
// http://wordpress.org/
//
// Thanks to John Ford ( http://www.aldenta.com ) for his contributions.
// Thanks to Dougal Campbell ( http://dougal.gunters.org ) for his contributions.
//
// Based on Twitter Tool from Alex King
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
// **********************************************************************

load_plugin_textdomain('post-to-twitter');

if (!function_exists('is_admin_page')) {
	function is_admin_page() {
		if (function_exists('is_admin')) {
			return is_admin();
		}
		if (function_exists('check_admin_referer')) {
			return true;
		}
		else {
			return false;
		}
	}
}

class post_to_twitter {

	function post_to_twitter() {
		$this->options = array(
			'twitter_username'
			, 'twitter_password'
			, 'create_blog_posts'
			, 'tweet_prefix'
			, 'notify_twitter'
			, 'give_ptt_credit'
		);
		$this->twitter_username = '';
		$this->twitter_password = '';
		$this->create_blog_posts = '1';
		$this->blog_post_author = '1';
		$this->blog_post_category = '1';
		$this->notify_twitter = '1';
		$this->give_ptt_credit = '1';
		// not included in options
		$this->update_hash = '';
		$this->tweet_prefix = 'New Blog Post';
		$this->last_digest_post = '';
		$this->last_tweet_download = '';
		$this->doing_tweet_download = '0';
		$this->doing_digest_post = '0';
		$this->version = '0.6';
	}
	
	// INSTALL FUNCTIONS
	function install() {
		global $wpdb;

		$charset_collate = '';
		if ( version_compare(mysql_get_server_info(), '4.1.0', '>=') ) {
			if (!empty($wpdb->charset)) {
				$charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
			}
			if (!empty($wpdb->collate)) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}
		foreach ($this->options as $option) {
			add_option('idptt_'.$option, $this->$option);
		}
		add_option('idptt_update_hash', '');
	}

	// - RECUPERA O VALOR DAS OPÇÕES NO BANCO DE DADOS
	function get_settings() {
		foreach ($this->options as $option) {
			$this->$option = get_option('idptt_'.$option);
		}
	}

	// ATAULIZA AS FUNÇÕES
	function update_settings() {
		if (current_user_can('manage_options')) {
			if (get_option('idptt_create_digest') != '1' && $this->create_digest == 1) {
				$this->initiate_digests();
			}
			$this->sidebar_tweet_count = intval($this->sidebar_tweet_count);
			if ($this->sidebar_tweet_count == 0) {
				$this->sidebar_tweet_count = '3';
			}
			foreach ($this->options as $option) {
				update_option('idptt_'.$option, $this->$option);
			}
		}
	}

	function populate_settings() {
		foreach ($this->options as $option) {
			if (isset($_POST['idptt_'.$option])) {
				$this->$option = stripslashes($_POST['idptt_'.$option]);
			}
		}
	}

	// ENVIA O POST AO TWITTER
	function do_tweet($tweet = '') {
		if (empty($this->twitter_username) 
			|| empty($this->twitter_password) 
			|| empty($tweet)
			|| empty($tweet->tw_text)
		) {
			return;
		}
		require_once(ABSPATH.WPINC.'/class-snoopy.php');
		$snoop = new Snoopy;
		$snoop->agent = 'Post to Twitter http://www.idmarketing.com.br/post-to-twitter/';
		$snoop->rawheaders = array(
			  'X-Twitter-Client' => 'Post to Twitter'
			, 'X-Twitter-Client-Version' => $this->version
			, 'X-Twitter-Client-URL' => 'http://www.idmarketing.com.br/post-to-twitter/'
		);
		$snoop->user = $this->twitter_username;
		$snoop->pass = $this->twitter_password;
		$snoop->submit('http://twitter.com/statuses/update.json', array('status' => $tweet->tw_text, 'source' => 'posttotwitter')
		);
		if (strpos($snoop->response_code, '200')) {
			update_option('idptt_last_tweet_download', strtotime('-28 minutes'));
			return true;
		}
		return false;
	}


	// MONTA O POST PARA ENVIO AO TWITTER
	function do_blog_post_tweet($post_id = 0) {
		if ($this->notify_twitter == '0'
			|| $post_id == 0
			|| get_post_meta($post_id, 'idptt_tweeted', true) == '1'
		) {
			return;
		}
		$post = get_post($post_id);

		$this->tweet_format = $this->tweet_prefix.': %s %s'; // Monta o prefix para o tweet
		$tweet = new idptt_tweet;
		$tweet->tw_text = sprintf(__($this->tweet_format, 'post-to-twitter'), html_entity_decode($post->post_title,ENT_QUOTES,"UTF-8"), get_permalink($post_id));
		$this->do_tweet($tweet);
		add_post_meta($post_id, 'idptt_tweeted', '1', true);
	}
	
}

class idptt_tweet {
	function idptt_tweet(
		$tw_id = ''
		, $tw_text = ''
		, $tw_created_at = ''
	) {
		$this->id = '';
		$this->modified = '';
		$this->tw_created_at = $tw_created_at;
		$this->tw_text = $tw_text;
		$this->tw_id = $tw_id;
	}
	
	function twdate_to_time($date) {
		$parts = explode(' ', $date);
		$date = strtotime($parts[1].' '.$parts[2].', '.$parts[5].' '.$parts[3]);
		return $date;
	}
	
	function tweet_post_exists() {
		global $wpdb;
		$test = $wpdb->get_results("
			SELECT *
			FROM $wpdb->postmeta
			WHERE meta_key = 'idptt_twitter_id'
			AND meta_value = '$this->tw_id'
		");
		if (count($test) > 0) {
			return true;
		}
		return false;
	}
	
	function tweet_is_post_notification() {
		global $idptt;
		if (substr($this->tw_text, 0, strlen($idptt->tweet_prefix)) == $idptt->tweet_prefix) {
			return true;
		}
		return false;
	}
	
	function add() {
		global $wpdb, $idptt;
		$wpdb->query("
			INSERT
			INTO $wpdb->idptt
			( tw_id
			, tw_text
			, tw_created_at
			, modified
			)
			VALUES
			( '".$wpdb->escape($this->tw_id)."'
			, '".$wpdb->escape($this->tw_text)."'
			, '".date('Y-m-d H:i:s', $this->tw_created_at)."'
			, NOW()
			)
		");
		do_action('idptt_add_tweet', $this);
		if ($idptt->create_blog_posts == '1' && !$this->tweet_post_exists() && !$this->tweet_is_post_notification()) {
			$idptt->do_tweet_post($this);
		}
	}
}

function idptt_login_test($username, $password) {
	require_once(ABSPATH.WPINC.'/class-snoopy.php');
	$snoop = new Snoopy;
	$snoop->agent = 'Post to Twitter http://www.idmarketing.com.br/post-to-twitter/';
	$snoop->user = $username;
	$snoop->pass = $password;
	$snoop->fetch('http://twitter.com/statuses/user_timeline.json');
	if (strpos($snoop->response_code, '200')) {
		return true;
	}
	else {
		return false;
	}
}

function idptt_update_tweets() {
	// let the last update run for 10 minutes
	if (time() - intval(get_option('idptt_doing_tweet_download')) < 600) {
		return;
	}
	update_option('idptt_doing_tweet_download', time());
	global $wpdb, $idptt;
	if (empty($idptt->twitter_username) || empty($idptt->twitter_password)) {
		update_option('idptt_doing_tweet_download', '0');
		die();
	}
	require_once(ABSPATH.WPINC.'/class-snoopy.php');
	$snoop = new Snoopy;
	$snoop->agent = 'Post to Twitter http://www.idmarketing.com.br/post-to-twitter/';
	$snoop->user = $idptt->twitter_username;
	$snoop->pass = $idptt->twitter_password;
	$snoop->fetch('http://twitter.com/statuses/user_timeline.json');

	if (!strpos($snoop->response_code, '200')) {
		update_option('idptt_doing_tweet_download', '0');
		return;
	}

	$data = $snoop->results;

	$hash = md5($data);
	if ($hash == get_option('idptt_update_hash')) {
		update_option('idptt_doing_tweet_download', '0');
		return;
	}
	$json = new Services_JSON();
	$tweets = $json->decode($data);
	if (is_array($tweets) && count($tweets) > 0) {
		$tweet_ids = array();
		foreach ($tweets as $tweet) {
			$tweet_ids[] = $wpdb->escape($tweet->id);
		}
		$existing_ids = $wpdb->get_col("
			SELECT tw_id
			FROM $wpdb->idptt
			WHERE tw_id
			IN ('".implode("', '", $tweet_ids)."')
		");
		$new_tweets = array();
		foreach ($tweets as $tw_data) {
			if (!$existing_ids || !in_array($tw_data->id, $existing_ids)) {
				$tweet = new idptt_tweet(
					$tw_data->id
					, $tw_data->text
				);
				$tweet->tw_created_at = $tweet->twdate_to_time($tw_data->created_at);
				$new_tweets[] = $tweet;
			}
		}
		foreach ($new_tweets as $tweet) {
			$tweet->add();
		}
	}
	update_option('idptt_update_hash', $hash);
	update_option('idptt_last_tweet_download', time());
	update_option('idptt_doing_tweet_download', '0');
	if ($idptt->create_digest == '1' && strtotime(get_option('idptt_last_digest_post')) < strtotime(date('Y-m-d 00:00:00', ak_gmmktime()))) {
		$idptt->do_digest_post();
	}
}

function idptt_notify_twitter($post_id) {
	global $idptt;
	$idptt->do_blog_post_tweet($post_id);
}
add_action('publish_post', 'idptt_notify_twitter');

function idptt_latest_tweet() {
	global $wpdb, $idptt;
	$tweets = $wpdb->get_results("
		SELECT *
		FROM $wpdb->idptt
		GROUP BY tw_id
		ORDER BY tw_created_at DESC
		LIMIT 1
	");
	if (count($tweets) == 1) {
		foreach ($tweets as $tweet) {
			$output = idptt_make_clickable(wp_specialchars($tweet->tw_text)).' <a href="http://twitter.com/'.$idptt->twitter_username.'/statuses/'.$tweet->tw_id.'">'.idptt_relativeTime($tweet->tw_created_at, 3).'</a>';
		}
	}
	else {
		$output = __('No tweets available at the moment.', 'post-to-twitter');
	}
	print($output);
}

function idptt_make_clickable($tweet) {
	if (substr($tweet, 0, 1) == '@' && substr($tweet, 1, 1) != ' ') {
		$space = strpos($tweet, ' ');
		$username = substr($tweet, 1, $space - 1);
		$tweet = '<a href="http://twitter.com/'.$username.'">@'.$username.'</a>'.substr($tweet, $space);
	}
	if (function_exists('make_chunky')) {
		return make_chunky($tweet);
	}
	else {
		return make_clickable($tweet);
	}
}

function idptt_tweet_form($type = 'input', $extra = '') {
	$output = '';
	if (current_user_can('publish_posts')) {
		$output .= '
<form action="'.get_bloginfo('wpurl').'/index.php" method="post" id="idptt_tweet_form" '.$extra.'>
	<fieldset>
		';
		switch ($type) {
			case 'input':
				$output .= '
				<p><input type="text" size="20" maxlength="140" id="idptt_tweet_text" name="idptt_tweet_text" onkeyup="idpttCharCount();" /></p>
				<input type="hidden" name="ak_action" value="idptt_post_tweet_sidebar" />
				<script type="text/javascript">
				//<![CDATA[
				function idpttCharCount() {
					var count = document.getElementById("idptt_tweet_text").value.length;
					if (count > 0) {
						document.getElementById("idptt_char_count").innerHTML = 140 - count;
					}
					else {
						document.getElementById("idptt_char_count").innerHTML = "";
					}
				}
				setTimeout("idpttCharCount();", 500);
				document.getElementById("idptt_tweet_form").setAttribute("autocomplete", "off");
				//]]>
				</script>
				';
			break;
		}
		$output .= '
		<p>
			<input type="submit" id="idptt_tweet_submit" name="idptt_tweet_submit" value="'.__('Post Tweet!', 'post-to-twitter').'" />
			<span id="idptt_char_count"></span>
		</p>
		<div class="clear"></div>
	</fieldset>
</form>
		';
	}
	return $output;
}

function idptt_init() {
	global $wpdb, $idptt;
	$idptt = new post_to_twitter;
	$wpdb->idptt = $wpdb->prefix.'ptt_twitter';
	if (isset($_GET['activate']) && $_GET['activate'] == 'true') {
		$tables = $wpdb->get_col("
			SHOW TABLES
		");
		if (!in_array($wpdb->idptt, $tables)) {
			$idptt->install();
		}
	}
	$idptt->get_settings();
}

add_action('init', 'idptt_init');

function idptt_head() {
	global $idptt;
	if ($idptt->tweet_from_sidebar) {
		print('
			<link rel="stylesheet" type="text/css" href="'.get_bloginfo('wpurl').'/index.php?ak_action=idptt_css" />
			<script type="text/javascript" src="'.get_bloginfo('wpurl').'/index.php?ak_action=idptt_js"></script>
		');
	}
}
add_action('wp_head', 'idptt_head');

function idptt_head_admin() {
	print('
		<link rel="stylesheet" type="text/css" href="'.get_bloginfo('wpurl').'/index.php?ak_action=idptt_css_admin" />
		<script type="text/javascript" src="'.get_bloginfo('wpurl').'/index.php?ak_action=idptt_js_admin"></script>
	');
}
add_action('admin_head', 'idptt_head_admin');

function idptt_request_handler() {
	global $wpdb, $idptt;
	if (!empty($_GET['ak_action'])) {
		switch($_GET['ak_action']) {
			case 'idptt_update_tweets':
				idptt_update_tweets();
				header('Location: '.get_bloginfo('wpurl').'/wp-admin/options-general.php?page=post-to-twitter.php&tweets-updated=true');
				die();
				break;
			case 'idptt_js':
			header("Content-type: text/javascript");
?>
function idpttPostTweet() {
	var tweet_field = $('idptt_tweet_text');
	var tweet_text = tweet_field.value;
	if (tweet_text == '') {
		return;
	}
	var tweet_msg = $("idptt_tweet_posted_msg");
	var idpttAjax = new Ajax.Updater(
		tweet_msg,
		"<?php bloginfo('wpurl'); ?>/index.php",
		{
			method: "post",
			parameters: "ak_action=idptt_post_tweet_sidebar&idptt_tweet_text=" + tweet_text,
			onComplete: idpttSetReset
		}
	);
	tweet_field.value = '';
	tweet_field.focus();
	$('idptt_char_count').innerHTML = '';
	tweet_msg.style.display = 'block';
}
function idpttSetReset() {
	setTimeout('idpttReset();', 2000);
}
function idpttReset() {
	$('idptt_tweet_posted_msg').style.display = 'none';
}
<?php
				die();
				break;
			case 'idptt_css':
			header("Content-type: text/css");
?>
#idptt_tweet_form {
	margin: 0;
	padding: 5px 0;
}
#idptt_tweet_form fieldset {
	border: 0;
}
#idptt_tweet_form fieldset #idptt_tweet_submit {
	float: right;
	margin-right: 10px;
}
#idptt_tweet_form fieldset #idptt_char_count {
	color: #666;
}
#idptt_tweet_posted_msg {
	background: #ffc;
	display: none;
	margin: 0 0 5px 0;
	padding: 5px;
}
#idptt_tweet_form div.clear {
	clear: both;
	float: none;
}
<?php
				die();
				break;
			case 'idptt_js_admin':
			header("Content-type: text/javascript");
?>
function idpttTestLogin() {
	var username = encodeURIComponent($('idptt_twitter_username').value);
	var password = encodeURIComponent($('idptt_twitter_password').value);
	var result = $('idptt_login_test_result');
	result.className = 'idptt_login_result_wait';
	result.innerHTML = '<?php _e('Testing...', 'post-to-twitter'); ?>';
	var idpttAjax = new Ajax.Updater(
		result,
		"<?php bloginfo('wpurl'); ?>/index.php",
		{
			method: "post",
			parameters: "ak_action=idptt_login_test&idptt_twitter_username=" + username + "&idptt_twitter_password=" + password,
			onComplete: idpttTestLoginResult
		}
	);
}
function idpttTestLoginResult() {
	$('idptt_login_test_result').className = 'idptt_login_result';
	Fat.fade_element('idptt_login_test_result');
}
<?php
				die();
				break;
			case 'idptt_css_admin':
			header("Content-type: text/css");
?>
#ipdtt_footer { 
	text-indent:15px;
	font-size: 8pt
}
#idptt_tweet_form {
	margin: 0;
	padding: 5px 0;
}
#idptt_tweet_form fieldset {
	border: 0;
}
#idptt_tweet_form fieldset textarea {
	width: 95%;
}
#idptt_tweet_form fieldset #idptt_tweet_submit {
	float: right;
	margin-right: 50px;
}
#idptt_tweet_form fieldset #idptt_char_count {
	color: #666;
}
#ptt_post_to_twitter fieldset.options p span {
	color: #666;
	display: block;
}
#ak_readme {
	height: 300px;
	width: 95%;
}
#ptt_post_to_twitter #idptt_login_test_result {
	display: inline;
	padding: 3px;
}
#ptt_post_to_twitter fieldset.options p span.idptt_login_result_wait {
	background: #ffc;
}
#ptt_post_to_twitter fieldset.options p span.idptt_login_result {
	background: #CFEBF7;
	color: #000;
}
<?php
				die();
				break;
		}
	}
	if (!empty($_POST['ak_action'])) {
		switch($_POST['ak_action']) {
			case 'idptt_update_settings':
				$idptt->populate_settings();
				$idptt->update_settings();
				header('Location: '.get_bloginfo('wpurl').'/wp-admin/options-general.php?page=post-to-twitter.php&updated=true');
				die();
				break;
			case 'idptt_login_test':
				$test = @idptt_login_test(
					@stripslashes($_POST['idptt_twitter_username'])
					, @stripslashes($_POST['idptt_twitter_password'])
				);
				if ($test) {
					die(__("Login succeeded, you're good to go.", 'post-to-twitter'));
				}
				else {
					die(__("Login failed, double-check that username and password.", 'post-to-twitter'));
				}
				break;
		}
	}
}
add_action('init', 'idptt_request_handler', 10);

function idptt_admin_tweet_form() {
	global $idptt;
	if ( $_GET['tweet-posted'] ) {
		print('
			<div id="message" class="updated fade">
				<p>'.__('Tweet posted.', 'post-to-twitter').'</p>
			</div>
		');
	}
	if (empty($idptt->twitter_username) || empty($idptt->twitter_password)) {
		print('
<p>Please enter your <a href="http://twitter.com">Twitter</a> account information in your <a href="options-general.php?page=post-to-twitter.php">Post to Twitter Options</a>.</p>		
		');
	}
}

function idptt_options_form() {
	global $wpdb, $idptt;

	$categories = get_categories('hide_empty=0');
	$cat_options = '';
	foreach ($categories as $category) {
		if ($category->term_id == $idptt->blog_post_category) {
			$selected = 'selected="selected"';
		}
		else {
			$selected = '';
		}
		$cat_options .= "\n\t<option value='$category->term_id' $selected>$category->name</option>";
	}

	$authors = get_users_of_blog();
	$author_options = '';
	foreach ($authors as $user) {
		$usero = new WP_User($user->user_id);
		$author = $usero->data;
		// Only list users who are allowed to publish
		if (! $usero->has_cap('publish_posts')) {
			continue;
		}
		if ($author->ID == $idptt->blog_post_author) {
			$selected = 'selected="selected"';
		}
		else {
			$selected = '';
		}
		$author_options .= "\n\t<option value='$author->ID' $selected>$author->user_nicename</option>";
	}
	$yes_no = array(
		'create_blog_posts'
		, 'notify_twitter'
		, 'give_ptt_credit'
	);
	foreach ($yes_no as $key) {
		$var = $key.'_options';
		if ($idptt->$key == '0') {
			$$var = '
				<option value="0" selected="selected">'.__('No', 'post-to-twitter').'</option>
				<option value="1">'.__('Yes', 'post-to-twitter').'</option>
			';
		}
		else {
			$$var = '
				<option value="0">'.__('No', 'post-to-twitter').'</option>
				<option value="1" selected="selected">'.__('Yes', 'post-to-twitter').'</option>
			';
		}
	}
	if ( $_GET['tweets-updated'] ) {
		print('
			<div id="message" class="updated fade">
				<p>'.__('Tweets updated.', 'post-to-twitter').'</p>
			</div>
		');
	}
	print('
			<div class="wrap">
				<h2>'.__('Post to Twitter Options', 'post-to-twitter').'</h2>
				<form id="ptt_post_to_twitter" name="ptt_post_to_twitter" action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="post">
					<fieldset class="options">
						<p>
							<label for="idptt_twitter_username">'.__('Twitter Username:', 'post-to-twitter').'</label>
							<input type="text" size="25" name="idptt_twitter_username" id="idptt_twitter_username" value="'.$idptt->twitter_username.'" />
						</p>
						<p>
							<label for="idptt_twitter_password">'.__('Twitter Password:', 'post-to-twitter').'</label>
							<input type="password" size="25" name="idptt_twitter_password" id="idptt_twitter_password" value="'.$idptt->twitter_password.'" />
						</p>
						<p>
							<input type="button" name="idptt_login_test" id="idptt_login_test" value="'.__('Test Login Info', 'post-to-twitter').'" onclick="idpttTestLogin(); return false;" />
							<span id="idptt_login_test_result"></span>
						</p>
						<!-- Tweet prefix -->
						<p>
							<label for="idptt_twitter_prefix">'.__('Tweet prefix:', 'post-to-twitter').'</label>
							<input type="text" size="25" name="idptt_tweet_prefix" id="idptt_twitter_username" value="'.$idptt->tweet_prefix.'" />
						</p>
						<p>
							<label for="idptt_notify_twitter">'.__('Create a tweet when you post in your blog?', 'post-to-twitter').'</label>
							<select name="idptt_notify_twitter" id="idptt_notify_twitter">'.$notify_twitter_options.'</select>
						</p>
						<!--
						<p>
							<label for="idptt_give_ptt_credit">'.__('Give Post to Twitter credit?', 'post-to-twitter').'</label>
							<select name="idptt_give_ptt_credit" id="idptt_give_ptt_credit">'.$give_ptt_credit_options.'</select>
						</p>
						-->
						<input type="hidden" name="ak_action" value="idptt_update_settings" />
					</fieldset>
					<p class="submit">
						<input type="submit" name="submit" value="'.__('Update Post to Twitter Options', 'post-to-twitter').'" />
					</p>
				</form>
			</div>
    		<div id="ipdtt_footer"><p>Post To Twitter is drive to you by <a href="http://www.idmarketing.com.br/" target="_new">ID Comunica&ccedil;&atilde;o</a></p></div>

	');
}

function idptt_menu_items() {
	if (current_user_can('manage_options')) {
		add_options_page(
			__('Post to Twitter Options', 'post-to-twitter')
			, __('Post to Twitter', 'post-to-twitter')
			, 10
			, basename(__FILE__)
			, 'idptt_options_form'
		);
	}
}
add_action('admin_menu', 'idptt_menu_items');

if (!function_exists('trim_add_elipsis')) {
	function trim_add_elipsis($string, $limit = 100) {
		if (strlen($string) > $limit) {
			$string = substr($string, 0, $limit)."...";
		}
		return $string;
	}
}

if (!function_exists('ak_gmmktime')) {
	function ak_gmmktime() {
		return gmmktime() - get_option('gmt_offset') * 3600;
	}
}


if (!class_exists('Services_JSON')) {

// PEAR JSON class

/**
* Converts to and from JSON format.
*
* JSON (JavaScript Object Notation) is a lightweight data-interchange
* format. It is easy for humans to read and write. It is easy for machines
* to parse and generate. It is based on a subset of the JavaScript
* Programming Language, Standard ECMA-262 3rd Edition - December 1999.
* This feature can also be found in  Python. JSON is a text format that is
* completely language independent but uses conventions that are familiar
* to programmers of the C-family of languages, including C, C++, C#, Java,
* JavaScript, Perl, TCL, and many others. These properties make JSON an
* ideal data-interchange language.
*
* This package provides a simple encoder and decoder for JSON notation. It
* is intended for use with client-side Javascript applications that make
* use of HTTPRequest to perform server communication functions - data can
* be encoded into JSON notation for use in a client-side javascript, or
* decoded from incoming Javascript requests. JSON format is native to
* Javascript, and can be directly eval()'ed with no further parsing
* overhead
*
* All strings should be in ASCII or UTF-8 format!
*
* LICENSE: Redistribution and use in source and binary forms, with or
* without modification, are permitted provided that the following
* conditions are met: Redistributions of source code must retain the
* above copyright notice, this list of conditions and the following
* disclaimer. Redistributions in binary form must reproduce the above
* copyright notice, this list of conditions and the following disclaimer
* in the documentation and/or other materials provided with the
* distribution.
*
* THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED
* WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
* MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN
* NO EVENT SHALL CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
* INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
* BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
* OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
* ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
* TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
* USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
* DAMAGE.
*
* @category
* @package     Services_JSON
* @author      Michal Migurski <mike-json@teczno.com>
* @author      Matt Knapp <mdknapp[at]gmail[dot]com>
* @author      Brett Stimmerman <brettstimmerman[at]gmail[dot]com>
* @copyright   2005 Michal Migurski
* @version     CVS: $Id: JSON.php,v 1.31 2006/06/28 05:54:17 migurski Exp $
* @license     http://www.opensource.org/licenses/bsd-license.php
* @link        http://pear.php.net/pepr/pepr-proposal-show.php?id=198
*/

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_SLICE',   1);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_STR',  2);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_ARR',  3);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_OBJ',  4);

/**
* Marker constant for Services_JSON::decode(), used to flag stack state
*/
define('SERVICES_JSON_IN_CMT', 5);

/**
* Behavior switch for Services_JSON::decode()
*/
define('SERVICES_JSON_LOOSE_TYPE', 16);

/**
* Behavior switch for Services_JSON::decode()
*/
define('SERVICES_JSON_SUPPRESS_ERRORS', 32);

/**
* Converts to and from JSON format.
*
* Brief example of use:
*
* <code>
* // create a new instance of Services_JSON
* $json = new Services_JSON();
*
* // convert a complexe value to JSON notation, and send it to the browser
* $value = array('foo', 'bar', array(1, 2, 'baz'), array(3, array(4)));
* $output = $json->encode($value);
*
* print($output);
* // prints: ["foo","bar",[1,2,"baz"],[3,[4]]]
*
* // accept incoming POST data, assumed to be in JSON notation
* $input = file_get_contents('php://input', 1000000);
* $value = $json->decode($input);
* </code>
*/
class Services_JSON
{
   /**
    * constructs a new JSON instance
    *
    * @param    int     $use    object behavior flags; combine with boolean-OR
    *
    *                           possible values:
    *                           - SERVICES_JSON_LOOSE_TYPE:  loose typing.
    *                                   "{...}" syntax creates associative arrays
    *                                   instead of objects in decode().
    *                           - SERVICES_JSON_SUPPRESS_ERRORS:  error suppression.
    *                                   Values which can't be encoded (e.g. resources)
    *                                   appear as NULL instead of throwing errors.
    *                                   By default, a deeply-nested resource will
    *                                   bubble up with an error, so all return values
    *                                   from encode() should be checked with isError()
    */
    function Services_JSON($use = 0)
    {
        $this->use = $use;
    }

   /**
    * convert a string from one UTF-16 char to one UTF-8 char
    *
    * Normally should be handled by mb_convert_encoding, but
    * provides a slower PHP-only method for installations
    * that lack the multibye string extension.
    *
    * @param    string  $utf16  UTF-16 character
    * @return   string  UTF-8 character
    * @access   private
    */
    function utf162utf8($utf16)
    {
        // oh please oh please oh please oh please oh please
        if(function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16');
        }

        $bytes = (ord($utf16{0}) << 8) | ord($utf16{1});

        switch(true) {
            case ((0x7F & $bytes) == $bytes):
                // this case should never be reached, because we are in ASCII range
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0x7F & $bytes);

            case (0x07FF & $bytes) == $bytes:
                // return a 2-byte UTF-8 character
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0xC0 | (($bytes >> 6) & 0x1F))
                     . chr(0x80 | ($bytes & 0x3F));

            case (0xFFFF & $bytes) == $bytes:
                // return a 3-byte UTF-8 character
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0xE0 | (($bytes >> 12) & 0x0F))
                     . chr(0x80 | (($bytes >> 6) & 0x3F))
                     . chr(0x80 | ($bytes & 0x3F));
        }

        // ignoring UTF-32 for now, sorry
        return '';
    }

   /**
    * convert a string from one UTF-8 char to one UTF-16 char
    *
    * Normally should be handled by mb_convert_encoding, but
    * provides a slower PHP-only method for installations
    * that lack the multibye string extension.
    *
    * @param    string  $utf8   UTF-8 character
    * @return   string  UTF-16 character
    * @access   private
    */
    function utf82utf16($utf8)
    {
        // oh please oh please oh please oh please oh please
        if(function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($utf8, 'UTF-16', 'UTF-8');
        }

        switch(strlen($utf8)) {
            case 1:
                // this case should never be reached, because we are in ASCII range
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return $utf8;

            case 2:
                // return a UTF-16 character from a 2-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr(0x07 & (ord($utf8{0}) >> 2))
                     . chr((0xC0 & (ord($utf8{0}) << 6))
                         | (0x3F & ord($utf8{1})));

            case 3:
                // return a UTF-16 character from a 3-byte UTF-8 char
                // see: http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                return chr((0xF0 & (ord($utf8{0}) << 4))
                         | (0x0F & (ord($utf8{1}) >> 2)))
                     . chr((0xC0 & (ord($utf8{1}) << 6))
                         | (0x7F & ord($utf8{2})));
        }

        // ignoring UTF-32 for now, sorry
        return '';
    }

   /**
    * encodes an arbitrary variable into JSON format
    *
    * @param    mixed   $var    any number, boolean, string, array, or object to be encoded.
    *                           see argument 1 to Services_JSON() above for array-parsing behavior.
    *                           if var is a strng, note that encode() always expects it
    *                           to be in ASCII or UTF-8 format!
    *
    * @return   mixed   JSON string representation of input var or an error if a problem occurs
    * @access   public
    */
    function encode($var)
    {
        switch (gettype($var)) {
            case 'boolean':
                return $var ? 'true' : 'false';

            case 'NULL':
                return 'null';

            case 'integer':
                return (int) $var;

            case 'double':
            case 'float':
                return (float) $var;

            case 'string':
                // STRINGS ARE EXPECTED TO BE IN ASCII OR UTF-8 FORMAT
                $ascii = '';
                $strlen_var = strlen($var);

               /*
                * Iterate over every character in the string,
                * escaping with a slash or encoding to UTF-8 where necessary
                */
                for ($c = 0; $c < $strlen_var; ++$c) {

                    $ord_var_c = ord($var{$c});

                    switch (true) {
                        case $ord_var_c == 0x08:
                            $ascii .= '\b';
                            break;
                        case $ord_var_c == 0x09:
                            $ascii .= '\t';
                            break;
                        case $ord_var_c == 0x0A:
                            $ascii .= '\n';
                            break;
                        case $ord_var_c == 0x0C:
                            $ascii .= '\f';
                            break;
                        case $ord_var_c == 0x0D:
                            $ascii .= '\r';
                            break;

                        case $ord_var_c == 0x22:
                        case $ord_var_c == 0x2F:
                        case $ord_var_c == 0x5C:
                            // double quote, slash, slosh
                            $ascii .= '\\'.$var{$c};
                            break;

                        case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)):
                            // characters U-00000000 - U-0000007F (same as ASCII)
                            $ascii .= $var{$c};
                            break;

                        case (($ord_var_c & 0xE0) == 0xC0):
                            // characters U-00000080 - U-000007FF, mask 110XXXXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c, ord($var{$c + 1}));
                            $c += 1;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xF0) == 0xE0):
                            // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}));
                            $c += 2;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xF8) == 0xF0):
                            // characters U-00010000 - U-001FFFFF, mask 11110XXX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}));
                            $c += 3;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xFC) == 0xF8):
                            // characters U-00200000 - U-03FFFFFF, mask 111110XX
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}),
                                         ord($var{$c + 4}));
                            $c += 4;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;

                        case (($ord_var_c & 0xFE) == 0xFC):
                            // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                            // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                            $char = pack('C*', $ord_var_c,
                                         ord($var{$c + 1}),
                                         ord($var{$c + 2}),
                                         ord($var{$c + 3}),
                                         ord($var{$c + 4}),
                                         ord($var{$c + 5}));
                            $c += 5;
                            $utf16 = $this->utf82utf16($char);
                            $ascii .= sprintf('\u%04s', bin2hex($utf16));
                            break;
                    }
                }

                return '"'.$ascii.'"';

            case 'array':
               /*
                * As per JSON spec if any array key is not an integer
                * we must treat the the whole array as an object. We
                * also try to catch a sparsely populated associative
                * array with numeric keys here because some JS engines
                * will create an array with empty indexes up to
                * max_index which can cause memory issues and because
                * the keys, which may be relevant, will be remapped
                * otherwise.
                *
                * As per the ECMA and JSON specification an object may
                * have any string as a property. Unfortunately due to
                * a hole in the ECMA specification if the key is a
                * ECMA reserved word or starts with a digit the
                * parameter is only accessible using ECMAScript's
                * bracket notation.
                */

                // treat as a JSON object
                if (is_array($var) && count($var) && (array_keys($var) !== range(0, sizeof($var) - 1))) {
                    $properties = array_map(array($this, 'name_value'),
                                            array_keys($var),
                                            array_values($var));

                    foreach($properties as $property) {
                        if(Services_JSON::isError($property)) {
                            return $property;
                        }
                    }

                    return '{' . join(',', $properties) . '}';
                }

                // treat it like a regular array
                $elements = array_map(array($this, 'encode'), $var);

                foreach($elements as $element) {
                    if(Services_JSON::isError($element)) {
                        return $element;
                    }
                }

                return '[' . join(',', $elements) . ']';

            case 'object':
                $vars = get_object_vars($var);

                $properties = array_map(array($this, 'name_value'),
                                        array_keys($vars),
                                        array_values($vars));

                foreach($properties as $property) {
                    if(Services_JSON::isError($property)) {
                        return $property;
                    }
                }

                return '{' . join(',', $properties) . '}';

            default:
                return ($this->use & SERVICES_JSON_SUPPRESS_ERRORS)
                    ? 'null'
                    : new Services_JSON_Error(gettype($var)." can not be encoded as JSON string");
        }
    }

   /**
    * array-walking function for use in generating JSON-formatted name-value pairs
    *
    * @param    string  $name   name of key to use
    * @param    mixed   $value  reference to an array element to be encoded
    *
    * @return   string  JSON-formatted name-value pair, like '"name":value'
    * @access   private
    */
    function name_value($name, $value)
    {
        $encoded_value = $this->encode($value);

        if(Services_JSON::isError($encoded_value)) {
            return $encoded_value;
        }

        return $this->encode(strval($name)) . ':' . $encoded_value;
    }

   /**
    * reduce a string by removing leading and trailing comments and whitespace
    *
    * @param    $str    string      string value to strip of comments and whitespace
    *
    * @return   string  string value stripped of comments and whitespace
    * @access   private
    */
    function reduce_string($str)
    {
        $str = preg_replace(array(

                // eliminate single line comments in '// ...' form
                '#^\s*//(.+)$#m',

                // eliminate multi-line comments in '/* ... */' form, at start of string
                '#^\s*/\*(.+)\*/#Us',

                // eliminate multi-line comments in '/* ... */' form, at end of string
                '#/\*(.+)\*/\s*$#Us'

            ), '', $str);

        // eliminate extraneous space
        return trim($str);
    }

   /**
    * decodes a JSON string into appropriate variable
    *
    * @param    string  $str    JSON-formatted string
    *
    * @return   mixed   number, boolean, string, array, or object
    *                   corresponding to given JSON input string.
    *                   See argument 1 to Services_JSON() above for object-output behavior.
    *                   Note that decode() always returns strings
    *                   in ASCII or UTF-8 format!
    * @access   public
    */
    function decode($str)
    {
        $str = $this->reduce_string($str);

        switch (strtolower($str)) {
            case 'true':
                return true;

            case 'false':
                return false;

            case 'null':
                return null;

            default:
                $m = array();

                if (is_numeric($str)) {
                    // Lookie-loo, it's a number

                    // This would work on its own, but I'm trying to be
                    // good about returning integers where appropriate:
                    // return (float)$str;

                    // Return float or int, as appropriate
                    return ((float)$str == (integer)$str)
                        ? (integer)$str
                        : (float)$str;

                } elseif (preg_match('/^("|\').*(\1)$/s', $str, $m) && $m[1] == $m[2]) {
                    // STRINGS RETURNED IN UTF-8 FORMAT
                    $delim = substr($str, 0, 1);
                    $chrs = substr($str, 1, -1);
                    $utf8 = '';
                    $strlen_chrs = strlen($chrs);

                    for ($c = 0; $c < $strlen_chrs; ++$c) {

                        $substr_chrs_c_2 = substr($chrs, $c, 2);
                        $ord_chrs_c = ord($chrs{$c});

                        switch (true) {
                            case $substr_chrs_c_2 == '\b':
                                $utf8 .= chr(0x08);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\t':
                                $utf8 .= chr(0x09);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\n':
                                $utf8 .= chr(0x0A);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\f':
                                $utf8 .= chr(0x0C);
                                ++$c;
                                break;
                            case $substr_chrs_c_2 == '\r':
                                $utf8 .= chr(0x0D);
                                ++$c;
                                break;

                            case $substr_chrs_c_2 == '\\"':
                            case $substr_chrs_c_2 == '\\\'':
                            case $substr_chrs_c_2 == '\\\\':
                            case $substr_chrs_c_2 == '\\/':
                                if (($delim == '"' && $substr_chrs_c_2 != '\\\'') ||
                                   ($delim == "'" && $substr_chrs_c_2 != '\\"')) {
                                    $utf8 .= $chrs{++$c};
                                }
                                break;

                            case preg_match('/\\\u[0-9A-F]{4}/i', substr($chrs, $c, 6)):
                                // single, escaped unicode character
                                $utf16 = chr(hexdec(substr($chrs, ($c + 2), 2)))
                                       . chr(hexdec(substr($chrs, ($c + 4), 2)));
                                $utf8 .= $this->utf162utf8($utf16);
                                $c += 5;
                                break;

                            case ($ord_chrs_c >= 0x20) && ($ord_chrs_c <= 0x7F):
                                $utf8 .= $chrs{$c};
                                break;

                            case ($ord_chrs_c & 0xE0) == 0xC0:
                                // characters U-00000080 - U-000007FF, mask 110XXXXX
                                //see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 2);
                                ++$c;
                                break;

                            case ($ord_chrs_c & 0xF0) == 0xE0:
                                // characters U-00000800 - U-0000FFFF, mask 1110XXXX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 3);
                                $c += 2;
                                break;

                            case ($ord_chrs_c & 0xF8) == 0xF0:
                                // characters U-00010000 - U-001FFFFF, mask 11110XXX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 4);
                                $c += 3;
                                break;

                            case ($ord_chrs_c & 0xFC) == 0xF8:
                                // characters U-00200000 - U-03FFFFFF, mask 111110XX
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 5);
                                $c += 4;
                                break;

                            case ($ord_chrs_c & 0xFE) == 0xFC:
                                // characters U-04000000 - U-7FFFFFFF, mask 1111110X
                                // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8
                                $utf8 .= substr($chrs, $c, 6);
                                $c += 5;
                                break;

                        }

                    }

                    return $utf8;

                } elseif (preg_match('/^\[.*\]$/s', $str) || preg_match('/^\{.*\}$/s', $str)) {
                    // array, or object notation

                    if ($str{0} == '[') {
                        $stk = array(SERVICES_JSON_IN_ARR);
                        $arr = array();
                    } else {
                        if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                            $stk = array(SERVICES_JSON_IN_OBJ);
                            $obj = array();
                        } else {
                            $stk = array(SERVICES_JSON_IN_OBJ);
                            $obj = new stdClass();
                        }
                    }

                    array_push($stk, array('what'  => SERVICES_JSON_SLICE,
                                           'where' => 0,
                                           'delim' => false));

                    $chrs = substr($str, 1, -1);
                    $chrs = $this->reduce_string($chrs);

                    if ($chrs == '') {
                        if (reset($stk) == SERVICES_JSON_IN_ARR) {
                            return $arr;

                        } else {
                            return $obj;

                        }
                    }

                    //print("\nparsing {$chrs}\n");

                    $strlen_chrs = strlen($chrs);

                    for ($c = 0; $c <= $strlen_chrs; ++$c) {

                        $top = end($stk);
                        $substr_chrs_c_2 = substr($chrs, $c, 2);

                        if (($c == $strlen_chrs) || (($chrs{$c} == ',') && ($top['what'] == SERVICES_JSON_SLICE))) {
                            // found a comma that is not inside a string, array, etc.,
                            // OR we've reached the end of the character list
                            $slice = substr($chrs, $top['where'], ($c - $top['where']));
                            array_push($stk, array('what' => SERVICES_JSON_SLICE, 'where' => ($c + 1), 'delim' => false));
                            //print("Found split at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                            if (reset($stk) == SERVICES_JSON_IN_ARR) {
                                // we are in an array, so just push an element onto the stack
                                array_push($arr, $this->decode($slice));

                            } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                                // we are in an object, so figure
                                // out the property name and set an
                                // element in an associative array,
                                // for now
                                $parts = array();
                                
                                if (preg_match('/^\s*(["\'].*[^\\\]["\'])\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                    // "name":value pair
                                    $key = $this->decode($parts[1]);
                                    $val = $this->decode($parts[2]);

                                    if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                        $obj[$key] = $val;
                                    } else {
                                        $obj->$key = $val;
                                    }
                                } elseif (preg_match('/^\s*(\w+)\s*:\s*(\S.*),?$/Uis', $slice, $parts)) {
                                    // name:value pair, where name is unquoted
                                    $key = $parts[1];
                                    $val = $this->decode($parts[2]);

                                    if ($this->use & SERVICES_JSON_LOOSE_TYPE) {
                                        $obj[$key] = $val;
                                    } else {
                                        $obj->$key = $val;
                                    }
                                }

                            }

                        } elseif ((($chrs{$c} == '"') || ($chrs{$c} == "'")) && ($top['what'] != SERVICES_JSON_IN_STR)) {
                            // found a quote, and we are not inside a string
                            array_push($stk, array('what' => SERVICES_JSON_IN_STR, 'where' => $c, 'delim' => $chrs{$c}));
                            //print("Found start of string at {$c}\n");

                        } elseif (($chrs{$c} == $top['delim']) &&
                                 ($top['what'] == SERVICES_JSON_IN_STR) &&
                                 ((strlen(substr($chrs, 0, $c)) - strlen(rtrim(substr($chrs, 0, $c), '\\'))) % 2 != 1)) {
                            // found a quote, we're in a string, and it's not escaped
                            // we know that it's not escaped becase there is _not_ an
                            // odd number of backslashes at the end of the string so far
                            array_pop($stk);
                            //print("Found end of string at {$c}: ".substr($chrs, $top['where'], (1 + 1 + $c - $top['where']))."\n");

                        } elseif (($chrs{$c} == '[') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a left-bracket, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_ARR, 'where' => $c, 'delim' => false));
                            //print("Found start of array at {$c}\n");

                        } elseif (($chrs{$c} == ']') && ($top['what'] == SERVICES_JSON_IN_ARR)) {
                            // found a right-bracket, and we're in an array
                            array_pop($stk);
                            //print("Found end of array at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        } elseif (($chrs{$c} == '{') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a left-brace, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_OBJ, 'where' => $c, 'delim' => false));
                            //print("Found start of object at {$c}\n");

                        } elseif (($chrs{$c} == '}') && ($top['what'] == SERVICES_JSON_IN_OBJ)) {
                            // found a right-brace, and we're in an object
                            array_pop($stk);
                            //print("Found end of object at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        } elseif (($substr_chrs_c_2 == '/*') &&
                                 in_array($top['what'], array(SERVICES_JSON_SLICE, SERVICES_JSON_IN_ARR, SERVICES_JSON_IN_OBJ))) {
                            // found a comment start, and we are in an array, object, or slice
                            array_push($stk, array('what' => SERVICES_JSON_IN_CMT, 'where' => $c, 'delim' => false));
                            $c++;
                            //print("Found start of comment at {$c}\n");

                        } elseif (($substr_chrs_c_2 == '*/') && ($top['what'] == SERVICES_JSON_IN_CMT)) {
                            // found a comment end, and we're in one now
                            array_pop($stk);
                            $c++;

                            for ($i = $top['where']; $i <= $c; ++$i)
                                $chrs = substr_replace($chrs, ' ', $i, 1);

                            //print("Found end of comment at {$c}: ".substr($chrs, $top['where'], (1 + $c - $top['where']))."\n");

                        }

                    }

                    if (reset($stk) == SERVICES_JSON_IN_ARR) {
                        return $arr;

                    } elseif (reset($stk) == SERVICES_JSON_IN_OBJ) {
                        return $obj;

                    }

                }
        }
    }

    /**
     * @todo Ultimately, this should just call PEAR::isError()
     */
    function isError($data, $code = null)
    {
        if (class_exists('pear')) {
            return PEAR::isError($data, $code);
        } elseif (is_object($data) && (get_class($data) == 'services_json_error' ||
                                 is_subclass_of($data, 'services_json_error'))) {
            return true;
        }

        return false;
    }
}

if (class_exists('PEAR_Error')) {

    class Services_JSON_Error extends PEAR_Error
    {
        function Services_JSON_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {
            parent::PEAR_Error($message, $code, $mode, $options, $userinfo);
        }
    }

} else {

    /**
     * @todo Ultimately, this class shall be descended from PEAR_Error
     */
    class Services_JSON_Error
    {
        function Services_JSON_Error($message = 'unknown error', $code = null,
                                     $mode = null, $options = null, $userinfo = null)
        {

        }
    }

}

}
?>