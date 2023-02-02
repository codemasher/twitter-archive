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
use InvalidArgumentException;
use Psr\Log\LogLevel;
use function file_exists;
use function is_dir;
use function is_readable;
use function realpath;
use function sprintf;
use function str_replace;

/**
 *
 */
class TwitterArchiveOptions extends OAuthOptions{

	protected string  $loglevel                = LogLevel::INFO;
	protected bool    $enforceRateLimit        = true;
	protected string  $filename                = 'twitter-archive';
	protected bool    $includeMedia            = false;
	protected bool    $fromCachedApiResponses  = true;
	protected bool    $fetchFromArchive        = true;
	protected bool    $fetchFromAPISearch      = false;
	protected bool    $fetchFromAdaptiveSearch = false;
	protected ?string $adaptiveRequestToken    = null;
	protected ?string $adaptiveGuestToken      = null;
	protected bool    $scanRTs                 = true;
	protected ?string $archiveDir              = null;
	protected string  $buildDir;
	protected string  $storageDir;
	protected string  $publicDir;
	protected string  $query;

	/**
	 *
	 */
	protected function set_adaptiveRequestToken(string $adaptiveRequestToken):void{
		$this->adaptiveRequestToken = str_replace('Bearer ', '', $adaptiveRequestToken);
	}

	/**
	 *
	 */
	protected function set_buildDir(string $buildDir):void{
		$this->buildDir = Util::mkdir($buildDir);
	}

	/**
	 *
	 */
	protected function set_storageDir(string $storageDir):void{
		$this->storageDir = Util::mkdir($storageDir);
	}

	/**
	 *
	 */
	protected function set_publicDir(string $publicDir):void{
		$this->publicDir = Util::mkdir($publicDir);
	}

	/**
	 *
	 */
	protected function set_archiveDir(string $archiveDir):void{

		if(!is_dir($archiveDir) || !is_readable($archiveDir)){
			throw new InvalidArgumentException(sprintf('directory not readable: %s', $archiveDir));
		}

		if(!file_exists($archiveDir.'/data/account.js')){
			throw new InvalidArgumentException('invalid twitter archive dir');
		}

		$this->archiveDir = realpath($archiveDir);
	}
}
