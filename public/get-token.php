<?php
/**
 * @link https://developer.twitter.com/en/docs/basics/authentication/overview/oauth
 * @link https://developer.twitter.com/en/docs/basics/authentication/overview/application-only
 *
 * @created      10.07.2017
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2017 Smiley
 * @license      MIT
 */

use chillerlan\DotEnv\DotEnv;
use chillerlan\HTTP\Psr18\CurlClient;
use chillerlan\HTTP\Utils\MessageUtil;
use chillerlan\OAuth\OAuthOptions;
use chillerlan\OAuth\Providers\Twitter\Twitter;
use chillerlan\OAuth\Storage\SessionStorage;

require_once __DIR__.'/../vendor/autoload.php';

ini_set('date.timezone', 'Europe/Amsterdam');

$cfgdir = realpath(__DIR__.'/../config');
$env    = (new DotEnv($cfgdir, '.env', false))->load();

$options_arr = [
	// OAuthOptions
	'key'              => $env->get('TWITTER_KEY') ?? '',
	'secret'           => $env->get('TWITTER_SECRET') ?? '',
	'callbackURL'      => $env->get('TWITTER_CALLBACK_URL') ?? '',
	'sessionStart'     => true,

	// HTTPOptions
	'ca_info'          => $cfgdir.'/cacert.pem',
	'userAgent'        => 'chillerlanPhpOAuth/4.0.0 +https://github.com/codemasher/php-oauth-core',
];


$options = new OAuthOptions($options_arr);
$http    = new CurlClient($options);
$storage = new SessionStorage($options);
$twitter = new Twitter($http, $storage, $options);

$servicename = $twitter->serviceName;

// step 2: redirect to the provider's login screen
if(isset($_GET['login']) && $_GET['login'] === $servicename){
	header('Location: '.$twitter->getAuthURL());
}
// step 3: receive the access token
elseif(isset($_GET['oauth_token']) && isset($_GET['oauth_verifier'])){
	$token = $twitter->getAccessToken($_GET['oauth_token'], $_GET['oauth_verifier']);

	// save the token [...]
	$screen_name = $token->extraParams['screen_name'];
	$user_id     = $token->extraParams['user_id'];

	file_put_contents($cfgdir.'/Twitter.token.json', $token->toJSON());

	// access granted, redirect
	header('Location: ?granted='.$servicename);
}
// step 4: verify the token and use the API
elseif(isset($_GET['granted']) && $_GET['granted'] === $servicename){
	echo '<pre>'.print_r(MessageUtil::decodeJSON($twitter->verifyCredentials()), true).'</pre>';
	echo '<textarea cols="120" rows="3" onclick="this.select();">'.$storage->getAccessToken($servicename)->toJSON().'</textarea>';
}
// step 1 (optional): display a login link
else{
	echo '<a href="?login='.$servicename.'">connect with '.$servicename.'!</a>';
}

exit;
