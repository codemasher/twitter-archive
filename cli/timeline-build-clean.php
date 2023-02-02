<?php
/**
 * run.php
 *
 * @created      03.12.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

use chillerlan\OAuth\Core\AccessToken;
use codemasher\TwitterArchive\TwitterArchive;

/**
 * from common.php:
 *
 * @var \codemasher\TwitterArchive\TwitterArchiveOptions $options
 * @var \chillerlan\OAuth\Core\AccessToken $token
 */
require_once __DIR__.'/common.php';

$screen_name = 'codemasher';

try{
	$options->query                   = sprintf('@%s include:nativeretweets', $screen_name);
	$options->filename                = $screen_name;
	$options->fromCachedApiResponses  = true;
	$options->scanRTs                 = true;
	$options->fetchFromArchive        = true;
	$options->fetchFromAdaptiveSearch = true;
	$options->adaptiveRequestToken    = 'Bearer AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs=1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';
	$options->adaptiveGuestToken      = '1612194759040241670';

	// try auto fetching the guest token
	if(preg_match('/gt=(?<guest_token>\d+);/', file_get_contents('https://twitter.com'), $match)){
		$options->adaptiveGuestToken = $match['guest_token'];
	}

	(new TwitterArchive($options))
		->importUserToken($token)
#		->getFollowers()
#		->getFollowing()
#		->getLists(true)
		->compileTimeline()
	;
}
catch(Throwable $e){
	printf('(╯°□°）╯彡┻━┻ %s', $e->getMessage());
}
