<?php
/**
 * archive.php
 *
 * @created      11.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

use chillerlan\OAuth\Core\AccessToken;

/**
 * @var \Psr\Log\LoggerInterface $logger
 * @var \Psr\Http\Client\ClientInterface $http
 * @var \codemasher\TwitterArchive\TwitterArchiveOptions $options
 * @var \codemasher\TwitterArchive\TwitterArchive $archive
 */
require_once __DIR__.'/common.php';

// If you have a valid twitter OAuth1 token, you can put it in the JSON string below
$tokenJson = '{
	"accessTokenSecret": "accessTokenSecret",
	"accessToken": "accessToken",
	"refreshToken": null,
	"expires": -9002,
	"extraParams": {
		"user_id": "",
		"screen_name": ""
	},
	"provider": "Twitter"
}';

// alternatively, load the token from a file saved via get-token.php
#$tokenJson = file_get_contents(__DIR__.'/../config/Twitter.token.json');

/** @var \chillerlan\OAuth\Core\AccessToken $token */
$token = (new AccessToken)->fromJSON(json: $tokenJson);

// run
$archive
#	->importUserToken(token: $token)
#	->getLists(includeForeign: true)
	// no token required for these endpoints
	// if no token is given, the $screen_name parameter is required
	->getFollowers(screen_name: null)
	->getFollowing(screen_name: null)
;
