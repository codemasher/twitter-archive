<?php
/**
 * Class TwitterArchiveOptions
 *
 * @created      20.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

namespace codemasher\TwitterArchive;

use chillerlan\OAuth\OAuthOptions;

/**
 *
 */
class TwitterArchiveOptions extends OAuthOptions{

	protected int    $sleepTimer         = 60;
	protected bool   $enforceRateLimit   = true;
	protected string $storageDir         = __DIR__.'/../storage';
	protected string $publicDir          = __DIR__.'/../public';
	protected bool   $includeMedia       = false;

}
