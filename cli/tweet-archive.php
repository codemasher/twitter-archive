<?php
/**
 * archive.php
 *
 * @created      11.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

use chillerlan\DotEnv\DotEnv;
use chillerlan\HTTP\Psr18\CurlClient;
use chillerlan\OAuth\Core\AccessToken;
use chillerlan\OAuth\OAuthOptions;
use codemasher\TwitterArchive\TwitterArchive;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;

require_once __DIR__.'/../vendor/autoload.php';

ini_set('date.timezone', 'Europe/Amsterdam');

$env = (new DotEnv(__DIR__.'/../config', '.env', false))->load();

$o = [
	// HTTPOptionsTrait
	'ca_info'         => realpath(__DIR__.'/../config/cacert.pem'), // https://curl.haxx.se/ca/cacert.pem
	// OAuthOptionsTrait
	'key'             => $env->TWITTER_KEY ?? '',
	'secret'          => $env->TWITTER_SECRET ?? '',
	'callbackURL'     => $env->TWITTER_CALLBACK_URL ?? '',
	'sessionStart'    => true,
	'sessionTokenVar' => 'twitter-archive-token',
];

/** @var \chillerlan\Settings\SettingsContainerInterface $options */
$options = new class($o) extends OAuthOptions{

	protected int    $sleepTimer         = 60;
	protected bool   $enforceRateLimit   = true;
	protected string $storageDir         = __DIR__.'/../storage';
	protected string $publicDir          = __DIR__.'/../public';
	protected bool   $includeMedia       = true;

};

// a log handler for STDOUT (or STDERR if you prefer)
$logHandler = (new StreamHandler('php://stdout', LogLevel::INFO))
	->setFormatter((new LineFormatter(null, 'Y-m-d H:i:s', true, true))->setJsonPrettyPrint(true));
// a logger instance
$logger     = new Logger('log', [$logHandler]); // PSR-3
$http       = new CurlClient($options, null, $logger); // PSR-18
$archive    = new TwitterArchive($http, $options, $logger);

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
