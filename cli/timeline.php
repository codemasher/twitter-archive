<?php
/**
 * Twitter timeline backup
 *
 * Required:
 *   - PHP 8.1+
 *     - Windows: https://windows.php.net/downloads/releases/php-8.1.12-Win32-vs16-x64.zip
 *     - Linux: https://www.digitalocean.com/community/tutorials/how-to-install-php-8-1-and-set-up-a-local-development-environment-on-ubuntu-22-04
 *       - apt-add-repository ppa:ondrej/php -y
 *       - apt-get update
 *       - apt-get install -y php8.1-cli php8.1-common php8.1-curl
 *   - cURL extension enabled
 *
 * @see https://github.com/pauldotknopf/twitter-dump
 *
 * @created      17.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

require_once __DIR__.'/functions.php';

/*
 * How to get the request token:
 *
 *   - open https://twitter.com/search in a webbrowser (chrome or firefox recommended)
 *   - open the developer console (press F12)
 *   - type anything in the twitter search box, hit enter
 *   - go to the "network" tab in the dev console and filter the requests for "adaptive.json"
 *   - click that line, a new tab for that request appears
 *   - there, in the "headers" tab, scroll to "request headers" and look for "Authorization: Bearer ..."
 *   - right click that line, select "copy value" and paste it below, should look like: 'Bearer AAAANRILgAAAAAAnNwI...'
 */
$token = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs=1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

/*
 * The search query
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
 * @see https://help.twitter.com/en/using-twitter/advanced-tweetdeck-features
 *
 * try:
 *   - "@username" timeline including replies
 *   - "@username include:nativeretweets filter:nativeretweets" for RTs (returns RTs of the past week only)
 *   - "to:username" for @mentions and replies
 */
$query = '@username include:nativeretweets';

/*
 * continue/run from stored responses, useful if the run gets interrupted for whatever reason
 */
$fromFile = true;

/*
 * the storage path for the raw responses, a different directory per query is recommended
 */
$dir = __DIR__.'/from-username';

/* ==================== stop editing here ===================== */

if(!file_exists($dir)){
	mkdir($dir);
}

$dir      = realpath($dir);
$filename = sprintf('%s/%s.json', $dir, md5($query));

$tl = getTimeline($query, $fromFile);
$js = json_encode($tl, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);

file_put_contents($filename, $js);

echo sprintf("data for '%s' saved in: %s\n", $query, realpath($filename));

$tl = json_decode(file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR);

echo sprintf('fetched %s tweets', count($tl));


exit;

/* ===================== here be dragons ====================== */

/**
 * retrieves the timeline for the given query and parese the response data
 */
function getTimeline(string $query, bool $fromFile = false):array{
	global $dir;

	$tweets     = [];
	$users      = [];
	$timeline   = [];
	$lastCursor = '';
	$count      = 0;

	while(true){
		$filename = sprintf('%s/%s-%d.json', $dir, md5($query), $count);

		if($fromFile && file_exists($filename)){
			$response = file_get_contents($filename);
		}
		else{

			[$response, $status, $headers] = search($query, $lastCursor);

			if($status === 429){
#				var_dump($headers); // @todo: examine headers if x-rate-limit-reset is set

				// just sleep for a bit
				sleep(10);

				continue;
			}
			elseif($status !== 200 || empty($response)){
				break;
			}

			file_put_contents($filename, $response);
		}

		if(!parseResponse($response, $tweets, $users, $timeline, $lastCursor)){
			break;
		}

		echo sprintf("[%s] fetched data for '%s', cursor: %s\n", $count, $query, $lastCursor);

		$count++;

		if(empty($lastCursor)){
			break;
		}

		if(!$fromFile || !file_exists($filename)){
			sleep(2);
		}
	}

	foreach($timeline as $id => &$v){
		$tweet = $tweets[$id];

		if($tweet['quoted_status_id'] !== null && isset($tweets[$tweet['quoted_status_id']])){
			$qt = $tweets[$tweet['quoted_status_id']];

			if(isset($users[$qt['user_id']])){
				$qt['user'] = $users[$qt['user_id']];
			}

			$tweet['quoted_status'] = $qt;
		}

		$tweet['user'] = $users[$tweet['user_id']];

		$v = $tweet;
	}

	return $timeline;
}

/**
 * parse the API response and fill the data arrays (passed by reference)
 */
function parseResponse(string $response, array &$tweets, array &$users, array &$timeline, string &$cursor):bool{

	try{
		$json = json_decode($response, false, 512, JSON_THROW_ON_ERROR);
	}
	catch(Throwable $e){
#		var_dump($response); // @todo: handle json error

		return false;
	}

	if(!isset($json->globalObjects->tweets, $json->globalObjects->users, $json->timeline->instructions)){
		return false;
	}

	if(empty((array)$json->globalObjects->tweets)){
		return false;
	}

	foreach($json->globalObjects->tweets as $tweet){
		$tweets[$tweet->id_str] = parseTweet($tweet);
	}

	foreach($json->globalObjects->users as $user){
		$users[$user->id_str] = parseUser($user);
	}

	foreach($json->timeline->instructions as $i){

		if(isset($i->addEntries->entries)){

			foreach($i->addEntries->entries as $instruction){

				if(str_starts_with($instruction->entryId, 'sq-I-t')){
					$timeline[$instruction->content->item->content->tweet->id] = null;
				}
				elseif($instruction->entryId === 'sq-cursor-bottom'){
					$cursor = $instruction->content->operation->cursor->value;
				}

			}

		}
		elseif(isset($i->replaceEntry->entryIdToReplace) && $i->replaceEntry->entryIdToReplace === 'sq-cursor-bottom'){
			$cursor = $i->replaceEntry->entry->content->operation->cursor->value;
		}
		else{
			$cursor = '';
		}
	}

	return true;
}

/**
 * fetch data from the adaptive search API
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/introduction
 */
function search(string $query, string $cursor = null):array{

	// the query parameters from the call to https://twitter.com/i/api/2/search/adaptive.json in original order
	$params = [
		'include_profile_interstitial_type'    => '1',
		'include_blocking'                     => '1',
		'include_blocked_by'                   => '1',
		'include_followed_by'                  => '1',
		'include_want_retweets'                => '1',
		'include_mute_edge'                    => '1',
		'include_can_dm'                       => '1',
		'include_can_media_tag'                => '1',
		'include_ext_has_nft_avatar'           => '1',
		'include_ext_is_blue_verified'         => '1',
		'skip_status'                          => '1',
		'cards_platform'                       => 'Web-12',
		'include_cards'                        => '1',
		'include_ext_alt_text'                 => 'true',
		'include_ext_limited_action_results'   => 'false',
		'include_quote_count'                  => 'true',
		'include_reply_count'                  => '1',
		'tweet_mode'                           => 'extended',
		'include_ext_collab_control'           => 'true',
		'include_entities'                     => 'true',
		'include_user_entities'                => 'true',
		'include_ext_media_color'              => 'false',
		'include_ext_media_availability'       => 'true',
		'include_ext_sensitive_media_warning'  => 'true',
		'include_ext_trusted_friends_metadata' => 'true',
		'send_error_codes'                     => 'true',
		'simple_quoted_tweet'                  => 'true',
		'q'                                    => $query,
#		'social_filter'                        =>'searcher_follows', // @todo
		'tweet_search_mode'                    => 'live',
		'count'                                => '100',
		'query_source'                         => 'typed_query',
		'cursor'                               => $cursor,
		'pc'                                   => '1',
		'spelling_corrections'                 => '1',
		'include_ext_edit_control'             => 'true',
		'ext'                                  => 'mediaStats,highlightedLabel,hasNftAvatar,voiceInfo,enrichments,superFollowMetadata,unmentionInfo,editControl,collab_control,vibe',
	];

	// remove the cursor parameter if it's empty
	if(empty($params['cursor'])){
		unset($params['cursor']);
	}

	return request('https://api.twitter.com/2/search/adaptive.json', $params);
}
