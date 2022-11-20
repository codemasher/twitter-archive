<?php
/**
 * common.php
 *
 * @created      20.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

use chillerlan\DotEnv\DotEnv;
use chillerlan\HTTP\Psr18\CurlClient;
use codemasher\TwitterArchive\TwitterArchive;
use codemasher\TwitterArchive\TwitterArchiveOptions;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;

require_once __DIR__.'/../vendor/autoload.php';

ini_set('date.timezone', 'Europe/Amsterdam');

$env     = (new DotEnv(__DIR__.'/../config', '.env', false))->load();
$options = new TwitterArchiveOptions;
// HTTPOptionsTraitm
$options->ca_info      = realpath(__DIR__.'/../config/cacert.pem'); // https://curl.haxx.se/ca/cacert.pem
// OAuthOptionsTrait
$options->key          = $env->TWITTER_KEY ?? '';
$options->secret       = $env->TWITTER_SECRET ?? '';
$options->callbackURL  = $env->TWITTER_CALLBACK_URL ?? '';
$options->sessionStart = true;
// TwitterArchiveOptions
$options->storageDir   = __DIR__.'/../storage';
$options->publicDir    = __DIR__.'/../public';
#$options->includeMedia = true;

// a log handler for STDOUT (or STDERR if you prefer)
$logHandler = (new StreamHandler('php://stdout', LogLevel::INFO))
	->setFormatter((new LineFormatter(null, 'Y-m-d H:i:s', true, true))->setJsonPrettyPrint(true));
// a logger instance
$logger     = new Logger('log', [$logHandler]); // PSR-3
$http       = new CurlClient($options, null, $logger); // PSR-18
$archive    = new TwitterArchive($http, $options, $logger);
