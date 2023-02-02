<?php
/**
 * common.php
 *
 * @created      03.12.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

use chillerlan\DotEnv\DotEnv;
use chillerlan\OAuth\Core\AccessToken;
use codemasher\TwitterArchive\TwitterArchiveOptions;
use Psr\Log\LogLevel;

require_once __DIR__.'/../vendor/autoload.php';

$env     = (new DotEnv(__DIR__.'/../config', '.env', false))->load();
$options = new TwitterArchiveOptions;

// HTTPOptions
$options->ca_info      = realpath(__DIR__.'/../config/cacert.pem'); // https://curl.haxx.se/ca/cacert.pem
$options->user_agent   = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)';
// OAuthOptionsTrait
$options->key          = $env->get('TWITTER_KEY') ?? '';
$options->secret       = $env->get('TWITTER_SECRET') ?? '';
$options->callbackURL  = $env->get('TWITTER_CALLBACK_URL') ?? '';
$options->sessionStart = true;
// TwitterArchiveOptions
$options->loglevel     = LogLevel::INFO;
$options->buildDir     = __DIR__.'/../.build';
$options->storageDir   = __DIR__.'/../storage';
$options->publicDir    = __DIR__.'/../public';
$options->archiveDir   = 'D:\twitter-2023-01-02-4ef7bfc3ecc0f8b46c0054d289ed4741e5a8fd5990a5938875c8232659373d27';

// invoke a token for the OAuth client
$tokenJson = file_get_contents(__DIR__.'/../config/Twitter.token.json');
$token     = (new AccessToken)->fromJSON(json: $tokenJson);
