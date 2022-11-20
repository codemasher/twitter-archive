<?php
/**
 * Class TwitterArchive
 *
 * @created      11.11.2022
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2022 smiley
 * @license      MIT
 */

namespace codemasher\TwitterArchive;

use chillerlan\HTTP\Utils\MessageUtil;
use chillerlan\OAuth\Core\AccessToken;
use chillerlan\OAuth\Providers\Twitter\Twitter;
use chillerlan\OAuth\Providers\Twitter\TwitterCC;
use chillerlan\OAuth\Storage\MemoryStorage;
use chillerlan\OAuth\Storage\OAuthStorageInterface;
use chillerlan\Settings\SettingsContainerInterface;
use finfo;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use function array_chunk;
use function array_column;
use function array_diff;
use function array_key_exists;
use function array_map;
use function array_merge;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function htmlentities;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_writable;
use function json_encode;
use function mkdir;
use function preg_replace;
use function realpath;
use function sleep;
use function sprintf;
use function str_replace;
use function strtolower;
use function strtotime;
use function time;
use function trim;
use function utf8_encode;
use const DIRECTORY_SEPARATOR;
use const ENT_NOQUOTES;
use const FILEINFO_MIME_TYPE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 *
 */
class TwitterArchive{

	protected ClientInterface            $http;
	protected SettingsContainerInterface $options;
	protected LoggerInterface            $logger;
	protected OAuthStorageInterface      $storage;
	protected Twitter                    $twitter;
	protected TwitterCC                  $twitterCC;

	protected string $storageDir;
	protected string $publicDir;
	protected string $mediaDir;
	protected string $profileDir;
	protected string $userID;
	protected string $screenName;
	protected int    $userCreated;
	protected bool   $hasToken = false;

	public function __construct(
		ClientInterface            $http,
		SettingsContainerInterface $options,
		LoggerInterface            $logger = null
	){
		$this->http      = $http;
		$this->options   = $options;
		$this->logger    = $logger ?? new NullLogger;
		$this->twitter   = new Twitter($this->http, new MemoryStorage, $this->options, $this->logger);
		$this->twitterCC = new TwitterCC($this->http, new MemoryStorage, $this->options, $this->logger);

		$storageDir = realpath($this->options->storageDir);
		$publicDir  = realpath($this->options->publicDir);

		if(empty($storageDir) || !file_exists($storageDir) || !is_dir($storageDir) || !is_writable($storageDir)){
			throw new InvalidArgumentException('invalid storage dir');
		}

		if(empty($publicDir) || !file_exists($publicDir) || !is_dir($publicDir) || !is_writable($publicDir)){
			throw new InvalidArgumentException('invalid public dir');
		}

		$this->storageDir = $storageDir;
		$this->publicDir  = $publicDir;
		$this->mediaDir   = $publicDir.DIRECTORY_SEPARATOR.'media';
		$this->profileDir = $publicDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'profile';

		if(!file_exists($this->mediaDir)){
			mkdir($this->mediaDir);
			mkdir($this->profileDir);
		}

		$this->twitterCC->getClientCredentialsToken();
	}

	/**
	 * Import a user token from an external source and store it in the database
	 */
	public function importUserToken(AccessToken $token):self{
		$user = $this->verifyToken($token);

		if($user === null){
			throw new InvalidArgumentException('invalid token');
		}

		$this->userID      = (string)$user->id;
		$this->screenName  = $user->screen_name;
		$this->userCreated = strtotime($user->created_at);

		$this->storage     = new MemoryStorage;

		// store the token and switch to db storage
		$this->storage->storeAccessToken($this->twitter->serviceName, $token);
		$this->twitter->setStorage($this->storage);

		return $this;
	}

	/**
	 * tries to verify a user token and returns the user object on success, null otherwise
	 */
	protected function verifyToken(AccessToken $token):?object{
		$this->hasToken = false;
		// use a temporary storage to verify the token
		$storage = new MemoryStorage;
		$storage->storeAccessToken($this->twitter->serviceName, $token);
		$this->twitter->setStorage($storage);

		$retry = 0;

		while(true){

			if($retry > 3){
				$this->logger->error('too many verification retries');
				break;
			}

			try{
				$retry++;
				$response = $this->twitter->verifyCredentials(['include_entities' => 'false', 'skip_status' => 'true']);
			}
			catch(Throwable $e){
				$this->logger->error(sprintf('http client error: %s', $e->getMessage()));

				continue;
			}


			$status = $response->getStatusCode();

			// yay
			if($status === 200){
				$user = MessageUtil::decodeJSON($response);

				if(isset($user->id, $user->screen_name)){
					$this->hasToken = true;

					return $user;
				}

				break;
			}
			// invalid
			elseif($status === 401){
				// @todo: remove token?
				$this->logger->notice(sprintf('invalid token for user: %s', $token->extraParams['screen_name'] ?? ''));

				break;
			}
			// request limit
			elseif($status === 429){
				$this->sleepOn429($response);
			}
			// nay
			else{
				$this->logger->error(sprintf('response error: HTTP/%s %s', $status, $response->getReasonPhrase()));

				break;
			}

		}

		return null;
	}

	/**
	 * evaluates the rate limit header and sleeps until reset
	 */
	protected function sleepOn429(ResponseInterface $response):void{
		$reset = (int)$response->getHeaderLine('x-rate-limit-reset');
		$now   = time();

		// header might be not set - just pause for a bit
		if($reset < $now){
			sleep(5);

			return;
		}

		$sleep = $reset - $now + 5;

		$this->logger->info(sprintf('HTTP/429 - going to sleep for %d seconds', $sleep));

		sleep($sleep);
	}

	/**
	 * fetches a list of IDs from the given endpoint
	 */
	protected function fetchIDs(string $endpointMethod, array $params):array{

		$endpoints = [
			'blocksIds'             => 61, // user/app 15/900s
			'followersIds'          => 61,
			'friendsIds'            => 61,
			'statusesRetweetersIds' => 3, // app, user: 12
		];

		if(!array_key_exists($endpointMethod, $endpoints)){
			throw new InvalidArgumentException(sprintf('invalid endpoint "%s"', $endpointMethod));
		}

		// use app auth on certain endpoints for improved request limits
		$client = !$this->hasToken && in_array($endpointMethod, ['followersIds', 'friendsIds']) ? 'twitterCC' : 'twitter';
		$params = array_merge(['cursor' => -1, 'stringify_ids' => 'false'], $params);
		$ids    = [];

		while(true){

			try{
				$response = $this->{$client}->{$endpointMethod}($params);
			}
			catch(Throwable $e){
				$this->logger->error(sprintf('http client error: %s', $e->getMessage()));

				continue;
			}

			$status = $response->getStatusCode();

			if($status === 429){
				$this->sleepOn429($response);

				continue;
			}
			elseif($status !== 200){
				$this->logger->error(sprintf('response error: HTTP/%s %s', $status, $response->getReasonPhrase()));

				break;
			}

			$json = MessageUtil::decodeJSON($response);

			if(isset($json->ids) && !empty($json->ids)){
				$ids = array_merge($ids, array_map('intval', $json->ids));
			}

			if(empty($json->next_cursor_str)){
				break;
			}

			$params['cursor'] = $json->next_cursor_str;

			if($this->options->enforceRateLimit){
				$this->logger->info(sprintf(
					'enforcing limit for "%s": going to sleep for %ss',
					$endpointMethod,
					$endpoints[$endpointMethod]
				));

				sleep($endpoints[$endpointMethod]); // take a break
			}
		}

		return $ids;
	}

	/**
	 * @throws \JsonException
	 */
	protected function saveToJson(string $filename, array $data):void{
		$json = json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
		$json = str_replace('    ' ,"\t" ,$json);

		file_put_contents($this->storageDir.DIRECTORY_SEPARATOR.$filename.'.json', $json);
	}

	/**
	 *
	 */
	public function getFollowers(string $screen_name = null):self{
		$screen_name ??= $this->screenName;

		$ids = $this->fetchIDs('followersIds', ['screen_name' => $screen_name], true);
		$this->saveToJson($screen_name.'_followers', $this->getProfiles($ids));

		return $this;
	}

	/**
	 *
	 */
	public function getFollowing(string $screen_name = null):self{
		$screen_name ??= $this->screenName;

		$ids = $this->fetchIDs('friendsIds', ['screen_name' => $screen_name], true);
		$this->saveToJson($screen_name.'_following', $this->getProfiles($ids));

		return $this;
	}

	/**
	 *
	 */
	protected function getProfiles(array $ids):array{
		$profiles = [];

		foreach(array_chunk($ids, 100) as $chunk){
			$profiles = array_merge($profiles, $this->fetchProfiles($chunk));
		}

		return $profiles;
	}

	/**
	 * Fetches up to 100 user profiles
	 *
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/accounts-and-users/follow-search-get-users/api-reference/get-users-lookup
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/data-dictionary/object-model/user
	 */
	protected function fetchProfiles(array $ids):?array{

		if(count($ids) === 0){
			$this->logger->error('no IDs given');

			return null;
		}
		elseif(count($ids) > 100){
			$this->logger->error('too many (>100) IDs given');

			return null;
		}

		try{
			$client   = !$this->hasToken ? 'twitterCC' : 'twitter';
			$response = $this->{$client}->usersLookup([
				'user_id'          => implode(',', $ids),
				'include_entities' => 'false',
				'skip_status'      => 'true',
			]);
		}
		catch(Throwable $e){
			$this->logger->error(sprintf('http client error: %s', $e->getMessage()));

			return null;
		}

		$status = $response->getStatusCode();

		// a 404 means that none of the requested IDs could be found
		if($status === 404){
			$this->logger->warning(sprintf('invalid IDs: %s', implode(', ', $ids)));

			return null;
		}
		// if we hit the request limit, go to sleep for a while
		elseif($status === 429){
			$this->sleepOn429($response);

			return null;
		}
		// if the request fails for some reason, we'll just retry next time
		elseif($status !== 200){
			$this->logger->error(sprintf('HTTP/%s %s', $status, $response->getReasonPhrase()));

			return null;
		}

		$users = MessageUtil::decodeJSON($response);

		if(!is_array($users) || empty($users)){
			$this->logger->warning('response does not contain user data');

			return null;
		}

		// diff the returned IDs against the requested ones
		$returned = array_column($users, 'id');
		$diff     = array_diff($ids, $returned);

		// exclude failed IDs
		if(!empty($diff)){
			$this->logger->warning(sprintf('invalid IDs: %s', implode(', ', $diff)));
		}

		$this->logger->debug(sprintf('added IDs: %s', implode(', ', array_column($users, 'id'))));
		$this->logger->info(sprintf('added: %s profiles', count($users)));

		foreach($users as $i => $user){
			$users[$i] = $this->prepareUserValues($user);
		}

		return $users;
	}

	/**
	 *
	 */
	protected function fetchImage(string $url, string $dest):string{

		if(empty($url)){
			return '';
		}

		try{
			$imageBlob = file_get_contents($url);
			$mime      = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageBlob);

			$ext = match ($mime){
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
			};
		}
		catch(Throwable $e){
			$this->logger->error(sprintf('image download error: %s [%s]', $url, $e->getMessage()));

			return '';
		}

		$imagePath = $dest.'.'.$ext;

		file_put_contents($this->publicDir.$imagePath, $imageBlob);
		$this->logger->info($imagePath);

		return $imagePath;
	}

	/**
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/data-dictionary/object-model/user
	 * @see https://developer.twitter.com/en/docs/twitter-api/data-dictionary/object-model/user
	 */
	protected function prepareUserValues(object $user):array{

		foreach(['name', 'description', 'location', 'url'] as $var){
			${$var} = preg_replace('/\s+/', ' ', $user->{$var} ?? '');
		}

		foreach($user->entities->description->urls ?? [] as $entity){
			$description = str_replace($entity->url, $entity->expanded_url, $description);
		}

		foreach($user->entities->url->urls ?? [] as $entity){
			$url = str_replace($entity->url, $entity->expanded_url, $url);
		}

		$screenName     = $user->screen_name ?? $user->username;
		$profile_image  = str_replace('_normal.', '.', $user->profile_image_url_https ?? $user->profile_image_url ?? '');
		$profile_banner = $user->profile_banner_url ?? '';

		if($this->options->includeMedia){
			$profile_image  = $this->fetchImage($profile_image, '/media/profile/profile_'.$screenName);
			$profile_banner = $this->fetchImage($profile_banner, '/media/profile/banner_'.$screenName);
		}

		return [
			'id'               => $user->id,
			'screen_name'      => $screenName,
			'name'             => $name,
			'description'      => $description,
			'location'         => $location,
			'url'              => $url,
			'followers_count'  => $user->followers_count ?? $user->public_metrics->followers_count ?? 0,
			'friends_count'    => $user->friends_count ?? $user->public_metrics->following_count ?? 0,
			'statuses_count'   => $user->statuses_count ?? $user->public_metrics->tweet_count ?? 0,
			'favourites_count' => $user->favourites_count ?? 0,
			'created_at'       => strtotime($user->created_at ?? ''),
			'protected'        => (bool)($user->protected ?? false),
			'verified'         => (bool)($user->verified ?? false),
			'muting'           => (bool)($user->muting ?? false),
			'blocking'         => (bool)($user->blocking ?? false),
			'blocked_by'       => (bool)($user->blocked_by ?? false),
			'is_cryptobro'     => $user->ext_has_nft_avatar ?? false,
			'clown_emoji'      => $user->ext_is_blue_verified ?? false,
			'profile_image'    => $profile_image,
			'profile_banner'   => $profile_banner,
		];
	}

	/**
	 *
	 */
	public function getLists(bool $includeForeign = false):self{

		$retry = 0;

		while(true){

			if($retry > 3){
				$this->logger->error('too many getLists retries');
				break;
			}

			try{
				$retry++;
				$response = $this->twitter->lists(['user_id' => $this->userID, 'reverse' => 'true']);
			}
			catch(Throwable $e){
				$this->logger->error(sprintf('http client error: %s', $e->getMessage()));

				continue;
			}

			$status = $response->getStatusCode();

			if($status === 200){
				$json = MessageUtil::decodeJSON($response);

				if(!is_array($json) || empty($json)){
					break;
				}

				foreach($json as $list){

					if(!$includeForeign && $list->user->screen_name !== $this->screenName){
						continue;
					}

					$this->logger->info(sprintf('list: %s', $list->name));

					$members  = $this->fetchList($list);
					$listName = $this->string2url($list->name);

					$this->saveToJson($list->user->screen_name.'-list-'.$listName, $members);
				}

				break;
			}
			elseif($status === 429){
				$this->sleepOn429($response);
			}
			else{
				$this->logger->error(sprintf('response error: HTTP/%s %s', $status, $response->getReasonPhrase()));

				break;
			}

		}

		return $this;
	}

	/**
	 * Fetches all users of a given (private) list from the currently authenticated usr's account
	 */
	protected function fetchList(object $list):array{

		$params = [
			'list_id'           => $list->id,
			'user_id'           => $list->user->id,
			'include_entities'  => 'false',
			'skip_status'       => 'true',
			'count'             => 100,
			'cursor'            => -1,
		];

		$users = [];

		while(true){

			try{
				$response = $this->twitter->listsMembers($params);
			}
			catch(Throwable $e){
				$this->logger->error(sprintf('http client error: %s', $e->getMessage()));

				continue;
			}

			$status = $response->getStatusCode();

			if($status === 429){
				$this->sleepOn429($response);

				continue;
			}
			elseif($status !== 200){
				$this->logger->error(sprintf('response error: HTTP/%s %s', $status, $response->getReasonPhrase()));

				break;
			}

			$json = MessageUtil::decodeJSON($response);

			if(isset($json->users) && !empty($json->users)){
				$users = array_merge($users, array_map([$this, 'prepareUserValues'], $json->users));

				$this->logger->info(sprintf('added: %s profiles', count($json->users)));
			}

			if(empty($json->next_cursor_str)){
				break;
			}

			$params['cursor'] = $json->next_cursor_str;
		}

		return $users;
	}

	/**
	 * Clean special characters out of strings to use them as URL-part
	 *
	 * @link http://de2.php.net/manual/de/function.preg-replace.php#90485
	 * @link http://unicode.e-workers.de
	 */
	protected function string2url(string $str, bool $lowercase = true):string{

		$table = [
			//add more characters if needed
			'Ä'=>'Ae',
			'ä'=>'ae',
			'Ö'=>'Oe',
			'ö'=>'oe',
			'Ü'=>'Ue',
			'ü'=>'ue',
			'ß'=>'ss',
			'@'=>'-at-',
			'.'=>'-',
			'_'=>'-'
		];

		//replace custom unicode characters
		$str      = strtr(trim($str), $table);
		//replace (nearly) all chars which have htmlentities
		$entities = htmlentities(utf8_encode($str), ENT_NOQUOTES, 'UTF-8');
		$str      = preg_replace('#&([a-z]{1,2})(acute|grave|cedil|circ|uml|lig|tilde|ring|slash);#i', '$1', $entities);
		//clean out the rest
		$str      = preg_replace(['([\40])', '([^a-zA-Z0-9-])', '(-{2,})'], '-', $str);

		return trim($lowercase ? strtolower($str) : $str, '-');
	}

}
