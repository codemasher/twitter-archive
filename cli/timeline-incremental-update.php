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
 * @var \codemasher\TwitterArchive\TwitterArchiveOptions $options
 * @var \chillerlan\OAuth\Core\AccessToken $token
 */
require_once __DIR__.'/common.php';

$screen_name = 'codemasher';
$now = time();

$options->query                   = sprintf('from:%s include:nativeretweets since:%s until:%s', $screen_name, date('Y-m-d', ($now - 86400 * 7)), date('Y-m-d', $now));
$options->filename                = $screen_name;
$options->fromCachedApiResponses  = true;
$options->fetchFromAPISearch      = true;

try{
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


