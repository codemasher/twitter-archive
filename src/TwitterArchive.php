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

use chillerlan\HTTP\Psr17\FactoryHelpers;
use chillerlan\HTTP\Psr17\RequestFactory;
use chillerlan\HTTP\Psr17\ResponseFactory;
use chillerlan\HTTP\Psr18\CurlClient;
use chillerlan\HTTP\Utils\MessageUtil;
use chillerlan\HTTP\Utils\QueryUtil;
use chillerlan\OAuth\Core\AccessToken;
use chillerlan\OAuth\Providers\Twitter\Twitter;
use chillerlan\OAuth\Storage\MemoryStorage;
use chillerlan\Settings\SettingsContainerInterface;
use InvalidArgumentException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_chunk;
use function array_column;
use function array_diff;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function json_decode;
use function md5;
use function print_r;
use function realpath;
use function sleep;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function time;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const SORT_DESC;

/**
 *
 */
class TwitterArchive{

	protected ClientInterface            $http;
	protected RequestFactoryInterface    $requestFactory;
	protected ResponseFactoryInterface   $responseFactory;
	protected SettingsContainerInterface $options;
	protected LoggerInterface            $logger;
	protected Twitter                    $twitter;

	/** @var Array<\codemasher\TwitterArchive\Tweet|null> */
	protected array  $tempTimeline = [];
	/** @var Array<\codemasher\TwitterArchive\Tweet> */
	protected array  $tempTweets   = [];
	/** @var Array<\codemasher\TwitterArchive\User> */
	protected array  $tempUsers    = [];
	protected array  $tempRTs      = [];
	protected string $lastCursor   = '';
	protected User   $user;

	/**
	 *
	 */
	public function __construct(SettingsContainerInterface $options){
		$this->options = $options;

		if(empty($this->options->archiveDir)){
			throw new InvalidArgumentException('archive dir is required');
		}

		// log formatter
		$formatter = (new LineFormatter("[%datetime%] %channel%.%level_name%: %message%\n", 'Y-m-d H:i:s', true, true))
			->setJsonPrettyPrint(true);
		// a log handler for STDOUT (or STDERR if you prefer)
		$logHandler = (new StreamHandler('php://stdout', $this->options->loglevel))
			->setFormatter($formatter);

		// invoke the worker instances
		$this->logger          = new Logger('log', [$logHandler]); // PSR-3
		$this->http            = new CurlClient(options: $this->options, logger: $this->logger); // PSR-18
		$this->twitter         = new Twitter($this->http, new MemoryStorage, $this->options, $this->logger);
		$this->requestFactory  = new RequestFactory;
		$this->responseFactory = new ResponseFactory;
	}

	/**
	 * prepare and fire a http request through PSR-7/PSR-18
	 */
	protected function cachedRequest(string $endpoint, array $params, int $count = null):?ResponseInterface{
		$cachedir = Util::mkdir(sprintf('%s/%s', $this->options->buildDir, Util::string2url($endpoint)));
		$filename = sprintf(
			'%s/%s%s.json',
			$cachedir,
			md5(implode(',', array_keys($params)).implode(',', array_values($params))),
			($count !== null ? '-'.$count : '')
		);

		// try to fetch from cached data
		if($this->options->fromCachedApiResponses && file_exists($filename)){
			$stream = FactoryHelpers::create_stream(file_get_contents($filename));

			return $this->responseFactory->createResponse()->withBody($stream);
		}

		$retry = 0;

		while(true){

			if($retry > 3){
				return null;
			}

			try{
				$response = $this->twitter->request($endpoint, $params);
			}
			catch(Throwable $e){
				$this->logger->error(sprintf('http client error: %s', $e->getMessage()));

				return null;
			}

			$status   = $response->getStatusCode();

			if($status === 200){
				file_put_contents($filename, MessageUtil::getContents($response));

				return $response;
			}
			elseif($status === 429){
				$reset = (int)$response->getHeaderLine('x-rate-limit-reset');
				$now   = time();


				// header might be not set - just pause for a bit
				if($reset < $now){
					sleep(10);

					continue;
				}

				$sleep = $reset - $now + 5;
				$this->logger->notice(sprintf('HTTP/429 - going to sleep for %d seconds', $sleep));

				sleep($sleep);
			}
			else{
				$this->logger->error(MessageUtil::toString($response));

				$retry++;
			}

		}

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
	 * Import a user token from an external source
	 */
	public function importUserToken(AccessToken $token):self{
		$user = $this->verifyToken($token);

		if(!$user instanceof User){
			throw new InvalidArgumentException('invalid token');
		}

		$this->user = $user;

		// store the token and switch to db storage
		$storage = new MemoryStorage;
		$storage->storeAccessToken($this->twitter->serviceName, $token);
		$this->twitter->setStorage($storage);

		return $this;
	}

	/**
	 * tries to verify a user token and returns the user object on success, null otherwise
	 *
	 * @see https://developer.twitter.com/en/docs/accounts-and-users/manage-account-settings/api-reference/get-account-verify_credentials GET account/verify_credentials
	 */
	protected function verifyToken(AccessToken $token):?User{
		// use a temporary storage to verify the token
		$storage = (new MemoryStorage)->storeAccessToken($this->twitter->serviceName, $token);
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

				if(!empty($user->id) && !empty($user->screen_name)){
					$this->logger->info(sprintf('imported token for user: %s', $user->screen_name));

					return new User($user, true);
				}

				$this->logger->error(sprintf('unknown response error: %s', print_r($user, true)));

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
				$this->logger->error(sprintf('response error: HTTP/%s %s (#%d)', $status, $response->getReasonPhrase(), $retry));
			}

		}

		return null;
	}

	/**
	 * fetches a list of IDs from the given endpoint
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function fetchIDs(string $method, array $params):array{

		$endpoints = [
			'blocksIds'    => '/1.1/blocks/ids.json',
			'followersIds' => '/1.1/followers/ids.json',
			'friendsIds'   => '/1.1/friends/ids.json',
		];

		if(!isset($endpoints[$method])){
			throw new InvalidArgumentException(sprintf('invalid endpoint "%s"', $method));
		}

		$params = array_merge(['cursor' => -1, 'stringify_ids' => 'false'], $params);
		$ids    = [];
		$retry  = 0;

		while(true){

			if($retry > 3){
				break;
			}

			$response = $this->cachedRequest($endpoints[$method], $params);

			if(!$response instanceof ResponseInterface){
				$retry++;

				continue;
			}

			$json = MessageUtil::decodeJSON($response);

			if(isset($json->ids) && !empty($json->ids)){
				$ids = array_merge($ids, array_map('intval', $json->ids));
			}

			if(empty($json->next_cursor_str)){
				break;
			}

			$params['cursor'] = $json->next_cursor_str;
			$retry = 0;

			if($this->options->enforceRateLimit){
				$this->logger->info(sprintf('enforcing limit for "%s": going to sleep for 61s', $method));

				sleep(61); // take a break
			}

		}

		return $ids;
	}

	/**
	 * Fetches user profiles
	 *
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/accounts-and-users/follow-search-get-users/api-reference/get-users-lookup GET users/lookup
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/data-dictionary/object-model/user User object
	 */
	protected function getProfiles(array $ids):array{
		$profiles = [];

		foreach(array_chunk($ids, 100) as $i => $chunk){

			$retry  = 0;
			$params = [
				'user_id'          => implode(',', $chunk),
				'include_entities' => 'false',
				'skip_status'      => 'true',
			];

			while(true){

				if($retry > 3){
					$this->logger->error(sprintf('too many usersLookup retries'));

					break;
				}

				$response = $this->cachedRequest('/1.1/users/lookup.json', $params, $i);

				if(!$response instanceof ResponseInterface){
					$retry++;

					continue;
				}

				$users = MessageUtil::decodeJSON($response);

				if(!is_array($users) || empty($users)){
					$this->logger->warning('response does not contain user data');

					break;
				}

				// diff the returned IDs against the requested ones
				$returned = array_column($users, 'id');
				$diff     = array_diff($chunk, $returned);

				// exclude failed IDs
				if(!empty($diff)){
					$this->logger->notice(sprintf('invalid IDs: %s', implode(', ', $diff)));
				}

				$this->logger->debug(sprintf('added IDs: %s', implode(', ', array_column($users, 'id'))));
				$this->logger->info(sprintf('added: %s profiles', count($users)));

				foreach($users as $user){
					$profiles[$user->id] = new User($user, true);
				}

				break;
			}

		}

		return $profiles;
	}

	/**
	 *
	 */
	protected function fetchFollow(string $method, string $screen_name = null):void{
		$screen_name ??= $this->user->screen_name;

		$filename = match($method){
			'followersIds' => 'followers',
			'friendsIds'   => 'following',
		};

		$ids      = $this->fetchIDs($method, ['screen_name' => $screen_name]);
		$profiles = array_values($this->getProfiles($ids));
		$filename = sprintf('%s/%s-%s.json', $this->options->storageDir, $screen_name, $filename);

		Util::saveJSON($filename, $profiles);
		$this->logger->info(sprintf('fetched %s profiles, saved in %s', count($profiles), realpath($filename)));
	}

	/**
	 * @see https://developer.twitter.com/en/docs/accounts-and-users/follow-search-get-users/api-reference/get-followers-ids GET followers/ids
	 */
	public function getFollowers(string $screen_name = null):self{
		$this->fetchFollow('followersIds', $screen_name);

		return $this;
	}

	/**
	 * @see https://developer.twitter.com/en/docs/accounts-and-users/follow-search-get-users/api-reference/get-friends-ids GET friends/ids
	 */
	public function getFollowing(string $screen_name = null):self{
		$this->fetchFollow('friendsIds', $screen_name);

		return $this;
	}

	/**
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/accounts-and-users/create-manage-lists/api-reference/get-lists-memberships GET lists/memberships
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/accounts-and-users/create-manage-lists/api-reference/get-lists-ownerships GET lists/ownerships
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/accounts-and-users/create-manage-lists/api-reference/get-lists-subscriptions GET lists/subscriptions
	 */
	public function getLists(bool $includeForeign = false):self{

		$endpoints = [
			'/1.1/lists/ownerships.json',
			'/1.1/lists/subscriptions.json',
			'/1.1/lists/memberships.json'
		];

		foreach($endpoints as $endpoint){

			$params = [
				'user_id'           => $this->user->id,
				'screen_name'       => $this->user->screen_name,
				'include_entities'  => 'false',
				'skip_status'       => 'true',
				'count'             => 500,
				'cursor'            => -1,
			];

			$retry = 0;

			while(true){

				if($retry > 3){
					$this->logger->error('too many lists retries');
					break;
				}

				$response = $this->cachedRequest($endpoint, $params);

				if(!$response instanceof ResponseInterface){
					$retry++;
					continue;
				}

				$json = MessageUtil::decodeJSON($response);

				foreach($json->lists as $list){

					if(!$includeForeign && $list->user->screen_name !== $this->user->screen_name){
						continue;
					}

					$this->logger->info(sprintf('list: %s', $list->name));

					$members  = $this->fetchListMembers($list);
					$listName = Util::string2url($list->name);
					$filename = sprintf('%s/%s-list-%s.json', $this->options->storageDir, $list->user->screen_name, $listName);

					Util::saveJSON($filename, array_values($members));
					$this->logger->info(sprintf('fetched %s profiles, saved in %s', count($members), realpath($filename)));
				}

				if(empty($json->next_cursor_str)){
					break;
				}

				$params['cursor'] = $json->next_cursor_str;

				$retry = 0;
			}

		}

		return $this;
	}

	/**
	 * Fetches all users of a given (private) list from the currently authenticated usr's account
	 *
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/accounts-and-users/create-manage-lists/api-reference/get-lists-members
	 */
	protected function fetchListMembers(object $list):array{

		$params = [
			'list_id'           => $list->id,
			'user_id'           => $list->user->id,
			'include_entities'  => 'false',
			'skip_status'       => 'true',
			'count'             => 100,
			'cursor'            => -1,
		];

		$users = [];
		$retry = 0;

		while(true){

			if($retry > 3){
				$this->logger->error('too many lists members retries');
				break;
			}

			$response = $this->cachedRequest('/1.1/lists/members.json', $params);

			if(!$response instanceof ResponseInterface){
				$retry++;
				continue;
			}

			$json = MessageUtil::decodeJSON($response);

			if(isset($json->users) && !empty($json->users)){

				foreach($json->users as $user){
					$users[$user->id] = new User($user, true);
				}

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
	 *
	 */
	public function compileTimeline(string $timelineJSON = null):self{
		$this->tempTimeline = [];
		$this->tempTweets   = [];
		$this->tempUsers    = [];
		$this->tempRTs      = [];

		if($timelineJSON){
			$this->importTimeline($timelineJSON);
		}

		if($this->options->fetchFromAdaptiveSearch){
			$this->getTimelineFromAdaptiveSearch();
		}

		if($this->options->fetchFromArchive){
			$this->fetchArchiveTweets();
		}

		if($this->options->fetchFromAPISearch){
			$this->getTimelineFromAPISearch();
		}

		// now fetch the original retweeted tweets
		$this->fetchRetweets();

		// fetch/update user profiles
		$this->fetchUserProfiles();

		// save output
		$this->saveTimeline();

		return $this;
	}

	/**
	 *
	 */
	protected function saveTimeline():void{
		$timeline = new Timeline;

		foreach($this->tempTimeline as $id => $tweet){

			if(!$tweet instanceof Tweet){
				$this->logger->warning(sprintf('not a valid tweet: %s', $id));

				continue;
			}

			$timeline[$id] = $tweet;

			unset($this->tempTimeline[$id]);
		}


		foreach($this->tempUsers as $id => $user){
			$timeline->setUser($user);

			unset($this->tempUsers[$id]);
		}


		$timeline->sortby('id', SORT_DESC);

		// save JSON
		Util::saveJSON(sprintf('%s/%s.json', $this->options->storageDir, $this->options->filename), $timeline);
		$timeline->toHTML($this->options->storageDir);

/*		// create a paginated version
		$timeline->toHTML($this->options->outdir, 1000);

		// create a single html file that contains all tweets
		$timeline->toHTML($this->options->builddir);
		// rename/move
		rename($this->options->builddir.'/index.html', sprintf('%s/%s.html', $this->options->outdir, $this->options->filename));

		// create top* timelines
		$timeline->sortby('retweet_count', SORT_DESC);
		$timeline->toHTML($this->options->builddir, 250, 1);
		// rename
		rename($this->options->builddir.'/index.html', sprintf('%s/%s-top-retweeted.html', $this->options->outdir, $this->options->filename));

		$timeline->sortby('like_count', SORT_DESC);
		$timeline->toHTML($this->options->builddir, 250, 1);
		// rename
		rename($this->options->builddir.'/index.html', sprintf('%s/%s-top-liked.html', $this->options->outdir, $this->options->filename));
*/

		$this->logger->info(sprintf(
			'saved %d tweet(s) from %d user(s) in %s as "%s[.ext]"',
			$timeline->count(),
			$timeline->countUsers(),
			$this->options->storageDir,
			$this->options->filename
		));

	}

	/**
	 *
	 */
	protected function importTimeline($timelineJSON):void{
		$tlJSON = Util::loadJSON($timelineJSON);

		// collect the retweet IDs from the parsed timeline
		foreach($tlJSON->tweets as $tweet){

			if($this->options->scanRTs && str_starts_with($tweet->text, 'RT @')){
				$this->tempRTs[]                = $tweet->id;
				$this->tempTimeline[$tweet->id] = null;

				continue;
			}

			// put the already parsed tweets in the output array
			$this->tempTimeline[$tweet->id] = new Tweet($tweet);
		}

		foreach($tlJSON->users as $user){
			$this->tempUsers[$user->id] = new User($user);
		}

		$this->logger->info(sprintf('parsed %d tweet(s) and %d user(s) from %s', count($tlJSON->tweets), count($tlJSON->users), realpath($timelineJSON)));
	}

	/**
	 * retrieves the timeline for the given query and parese the response data
	 *
	 * @see https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query
	 * @see https://help.twitter.com/en/using-twitter/advanced-tweetdeck-features
	 *
	 * try:
	 *   - "@username" timeline including replies
	 *   - "@username include:nativeretweets filter:nativeretweets" for RTs (returns RTs of the past week only)
	 *   - "to:username" for @mentions and replies
	 *
	 * @throws \JsonException
	 */
	protected function getTimelineFromAdaptiveSearch():void{
		$cachedir         = Util::mkdir($this->options->buildDir.DIRECTORY_SEPARATOR.Util::string2url($this->options->query));
		$count            = 0;
		$retry            = 0;
		$this->lastCursor = '';

		while(true){

			if($retry > 3){
				break;
			}

			// the query parameters from the call to https://twitter.com/i/api/2/search/adaptive.json in original order
			$params = [
				'include_profile_interstitial_type'    => '1',
				'include_blocking'                     => '1',
				'include_blocked_by'                   => '1',
				'include_followed_by'                  => '1',
				'include_want_retweets'                => '1',
				'include_mute_edge'                    => '1',
				'include_can_dm'                       => '1',
				'include_can_media_tag'                => '1',
				'include_ext_has_nft_avatar'           => '1',
				'include_ext_is_blue_verified'         => '1',
				'skip_status'                          => '1',
				'cards_platform'                       => 'Web-12',
				'include_cards'                        => '1',
				'include_ext_alt_text'                 => 'true',
				'include_ext_limited_action_results'   => 'false',
				'include_quote_count'                  => 'true',
				'include_reply_count'                  => '1',
				'tweet_mode'                           => 'extended',
				'include_ext_collab_control'           => 'true',
				'include_entities'                     => 'true',
				'include_user_entities'                => 'true',
				'include_ext_media_color'              => 'false',
				'include_ext_media_availability'       => 'true',
				'include_ext_sensitive_media_warning'  => 'true',
				'include_ext_trusted_friends_metadata' => 'true',
				'send_error_codes'                     => 'true',
				'simple_quoted_tweet'                  => 'true',
				'q'                                    => $this->options->query,
#				'social_filter'                        => 'searcher_follows', // @todo
				'tweet_search_mode'                    => 'live',
				'count'                                => '100',
				'query_source'                         => 'typed_query',
				'cursor'                               => $this->lastCursor,
				'pc'                                   => '1',
				'spelling_corrections'                 => '1',
				'include_ext_edit_control'             => 'true',
				'ext'                                  => 'mediaStats,highlightedLabel,hasNftAvatar,voiceInfo,enrichments,superFollowMetadata,unmentionInfo,editControl,collab_control,vibe',
			];

			// remove the cursor parameter if it's empty
			if(empty($params['cursor'])){
				unset($params['cursor']);
			}

			// try to fetch from cached data
			$filename = sprintf('%s/%s-%d.json', $cachedir, md5($this->options->query), $count);

			if($this->options->fromCachedApiResponses && file_exists($filename)){
				$data = file_get_contents($filename);
			}
			else{
				$url      = QueryUtil::merge('https://api.twitter.com/2/search/adaptive.json', $params);
				$request  = $this->requestFactory->createRequest('GET', $url)
					->withHeader('Authorization', sprintf('Bearer %s', $this->options->adaptiveRequestToken))
					->withHeader('x-guest-token', $this->options->adaptiveGuestToken)
				;

				$response = $this->http->sendRequest($request);
				$status   = $response->getStatusCode();

				sleep(2); // try not to hammer

				if($status === 200){
					$data  = MessageUtil::getContents($response);
					$retry = 0;

					file_put_contents($filename, $data);
				}
				elseif($status === 429){
					$this->sleepOn429($response);

					continue;
				}
				else{
					$this->logger->error(MessageUtil::toString($response));
					$retry++;

					continue;
				}

			}

			$json = json_decode(json: $data, flags: JSON_THROW_ON_ERROR);

			$this->logger->info(sprintf('[%d] fetched data for "%s", cursor: %s', $count, $this->options->query, $this->lastCursor));

			if(empty($json) || !$this->parseAdaptiveSearchResponse($json) || empty($this->lastCursor)){
				break;
			}

			$count++;
		}

		// update timeline
		foreach($this->tempTimeline as $id => &$v){
			$tweet = $this->tempTweets[$id];

			// embed quoted tweets
			if(isset($tweet->quoted_status_id) && isset($this->tempTweets[$tweet->quoted_status_id])){
				$tweet->quoted_status = $this->tempTweets[$tweet->quoted_status_id];
			}

			$v = $tweet;
		}

	}

	/**
	 * parse the API response and fill the data arrays (passed by reference)
	 */
	protected function parseAdaptiveSearchResponse(array|object $json):bool{

		if(
			!isset($json->globalObjects->tweets, $json->globalObjects->users, $json->timeline->instructions)
			|| empty((array)$json->globalObjects->tweets)
		){
			return false;
		}

		foreach($json->globalObjects->tweets as $tweet){
			$tweet = new Tweet($tweet, true);

			// collect retweets
			if(str_starts_with($tweet->text, 'RT @')){
				$this->tempRTs[] = $tweet->id;
			}

			$this->tempTweets[$tweet->id] = $tweet;
		}

		foreach($json->globalObjects->users as $user){
			$this->tempUsers[$user->id_str] = new User($user, true);
		}

		foreach($json->timeline->instructions as $i){

			if(isset($i->addEntries->entries)){

				foreach($i->addEntries->entries as $instruction){

					if(str_starts_with($instruction->entryId, 'sq-I-t')){
						$this->tempTimeline[$instruction->content->item->content->tweet->id] = null;
					}
					elseif($instruction->entryId === 'sq-cursor-bottom'){
						$this->lastCursor = $instruction->content->operation->cursor->value;
					}

				}

			}
			elseif(isset($i->replaceEntry->entryIdToReplace) && $i->replaceEntry->entryIdToReplace === 'sq-cursor-bottom'){
				$this->lastCursor = $i->replaceEntry->entry->content->operation->cursor->value;
			}
			else{
				$this->lastCursor = '';
			}

		}

		return true;
	}

	/**
	 * fetch the remaining tweets from the archive
	 *
	 * @throws \JsonException
	 */
	protected function fetchArchiveTweets():void{
		$archive = file_get_contents($this->options->archiveDir.'/data/tweets.js');
		$archive = str_replace('window.YTD.tweets.part0 = ', '', $archive);
		$archive = json_decode(json: $archive, flags: JSON_THROW_ON_ERROR);
		$archive = array_column($archive, 'tweet');

		$archiveTweets = [];

		foreach($archive as $tweet){
			$id = (int)$tweet->id;

			if(isset($this->tempTimeline[$id])){
				continue;
			}

			if(str_starts_with($tweet->full_text, 'RT @')){
				$this->tempRTs[] = $id;

				continue;
			}

			$archiveTweets[] = $id;
		}

		if(empty($archiveTweets)){
			return;
		}

		foreach(array_chunk($archiveTweets, 100) as $i => $ids){

			// all the fields! (what a fucking mess)
			$v2Params = [
				'ids'          => implode(',', $ids),
				'expansions'   => 'attachments.poll_ids,attachments.media_keys,author_id,entities.mentions.username,geo.place_id,in_reply_to_user_id,referenced_tweets.id,referenced_tweets.id.author_id',
				'media.fields' => 'duration_ms,height,media_key,preview_image_url,type,url,width,public_metrics,alt_text,variants',
				'place.fields' => 'contained_within,country,country_code,full_name,geo,id,name,place_type',
				'poll.fields'  => 'duration_minutes,end_datetime,id,options,voting_status',
				'tweet.fields' => 'attachments,author_id,conversation_id,created_at,entities,geo,id,in_reply_to_user_id,lang,public_metrics,possibly_sensitive,referenced_tweets,reply_settings,source,text,withheld',
				'user.fields'  => 'created_at,description,entities,id,location,name,pinned_tweet_id,profile_image_url,protected,public_metrics,url,username,verified,withheld',
			];

			$v2Response = $this->cachedRequest('/2/tweets', $v2Params);

			if($v2Response === null){
				$this->logger->warning('could not fetch tweets from v1 endpoint');

				continue;
			}

			$v2json = MessageUtil::decodeJSON($v2Response);

			foreach($v2json->data as $v2Tweet){
				$this->tempTimeline[$v2Tweet->id] = new Tweet($v2Tweet, true);
			}

			$this->logger->info(sprintf('[%d] fetched data for %s tweet(s) from archive', $i, count($ids)));
		}

	}

	/**
	 * incremental timeline updates
	 *
	 * @see https://developer.twitter.com/en/docs/twitter-api/v1/tweets/search/api-reference/get-search-tweets
	 */
	protected function getTimelineFromAPISearch():void{

		$params = [
			'q'                => $this->options->query,
			'count'            => 100,
			'include_entities' => 'false',
			'result_type'      => 'mixed',
		];

		$count = 0;
		$retry = 0;

		while(true){

			if($retry > 3){
				break;
			}

			$response = $this->cachedRequest('/1.1/search/tweets.json', $params);

			if(!$response instanceof ResponseInterface){
				$retry++;

				continue;
			}

			$json = MessageUtil::decodeJSON($response);

			if(!isset($json->statuses)){
				break;
			}

			foreach($json->statuses as $tweet){
				$this->tempUsers[$tweet->user->id] = new User($tweet->user, true);

				if(!isset($this->tempTimeline[$tweet->id])){
					$this->tempTimeline[$tweet->id] = new Tweet($tweet, true);
				}

				if(str_starts_with($tweet->text, 'RT @')){
					$this->tempRTs[] = $tweet->id;
				}
			}

			$this->logger->info(sprintf('[%s] fetched %d tweets for "%s", last id: %s', $count, count($json->statuses), $this->options->query, $json->search_metadata->max_id));


			if(!isset($json->search_metadata, $json->search_metadata->next_results) || empty($json->search_metadata->next_results)){
				break;
			}

			$params = QueryUtil::parse($json->search_metadata->next_results);
			$retry  = 0;
			$count++;
		}

	}

	/**
	 * RTs are a mess and the messages are always truncated in the fetched RT status, so we'll need to fetch the original tweets too.
	 * An RT creates a separate status that is saved as old style retweet "RT @username ...", truncated to 140 characters.
	 * Both, v1 and v2 endpoints will only return the truncated text if the RT status id is called.
	 * Only the v2 endpoint returns the id of the original tweet that was retweeted.
	 */
	protected function fetchRTMeta():array{
		$rtIDs = [];

		foreach(array_chunk($this->tempRTs, 100) as $i => $ids){

			$v2Params = [
				'ids'          => implode(',', $ids),
				'tweet.fields' => 'author_id,referenced_tweets,conversation_id,created_at',
			];

			$response = $this->cachedRequest('/2/tweets', $v2Params);

			if(!$response instanceof ResponseInterface){
				$this->logger->warning('could not fetch tweets from /2/tweets');

				continue;
			}

			$json = MessageUtil::decodeJSON($response);

			foreach($json->data as $tweet){

				if(!isset($tweet->referenced_tweets)){
					$this->logger->warning(sprintf('does not look like a retweet: "%s"', $tweet->text ?? ''));

					continue;
				}

				$id   = (int)$tweet->referenced_tweets[0]->id;
				$rtID = (int)$tweet->id;
				// create a parsed tweet for the RT status and save the original tweet id in it
				$this->tempTimeline[$rtID] = new Tweet($tweet, true);
				$this->tempTimeline[$rtID]->retweeted_status_id = $id;
				// to backreference in the next op
				// original tweet id => retweet status id
				$rtIDs[$id] = $rtID;
			}

			$this->logger->info(sprintf('[%d] fetched meta for %s tweet(s)', $i, count($ids)));
		}

		return $rtIDs;
	}

	/**
	 * this is even more of a mess as both, the v1 and v2 endpoints don't return the complete data so we're gonna call both
	 */
	protected function fetchRetweets():void{

		// we're gonna fetch the metadata for the retweet status from the v2 endpoint first
		$rtIDs = $this->fetchRTMeta();

		foreach(array_chunk(array_keys($rtIDs), 100) as $i => $ids){

			$v1Params = [
				'id'                   => implode(',', $ids),
				'trim_user'            => false,
				'map'                  => false,
				'include_ext_alt_text' => true,
				'skip_status'          => true,
				'include_entities'     => true,
			];

			// all the fields! (what a fucking mess)
			$v2Params = [
				'ids'          => implode(',', $ids),
				'expansions'   => 'attachments.poll_ids,attachments.media_keys,author_id,entities.mentions.username,geo.place_id,in_reply_to_user_id,referenced_tweets.id,referenced_tweets.id.author_id',
				'media.fields' => 'duration_ms,height,media_key,preview_image_url,type,url,width,public_metrics,alt_text,variants',
				'place.fields' => 'contained_within,country,country_code,full_name,geo,id,name,place_type',
				'poll.fields'  => 'duration_minutes,end_datetime,id,options,voting_status',
				'tweet.fields' => 'attachments,author_id,conversation_id,created_at,entities,geo,id,in_reply_to_user_id,lang,public_metrics,possibly_sensitive,referenced_tweets,reply_settings,source,text,withheld',
				'user.fields'  => 'created_at,description,entities,id,location,name,pinned_tweet_id,profile_image_url,protected,public_metrics,url,username,verified,withheld',
			];

			$v1Response = $this->cachedRequest('/1.1/statuses/lookup.json', $v1Params);
			$v2Response = $this->cachedRequest('/2/tweets', $v2Params);

			if($v1Response === null || $v2Response === null){
				$this->logger->warning('could not fetch tweets from v1 or v2 endpoints');

				continue;
			}

			$v1json = MessageUtil::decodeJSON($v1Response);
			$v2json = MessageUtil::decodeJSON($v2Response);

			foreach($v1json as $v1Tweet){
				$this->tempUsers[$v1Tweet->user->id]                        = new User($v1Tweet->user, true);
				$this->tempTimeline[$rtIDs[$v1Tweet->id]]->retweeted_status = new Tweet($v1Tweet, true);
			}

			foreach($v2json->data as $v2Tweet){
				$v2Tweet = new Tweet($v2Tweet, true);

				foreach(['user_id', 'text', 'conversation_id', 'place', 'coordinates', 'geo', 'media'] as $field){
					$this->tempTimeline[$rtIDs[$v2Tweet->id]]->retweeted_status->{$field} = $v2Tweet->{$field};
				}

			}

			$this->logger->info(sprintf('[%d] fetched data for %s tweet(s)', $i, count($ids)));
		}

	}

	/**
	 * update profiles
	 */
	protected function fetchUserProfiles():void{
		$u = [];

		foreach($this->tempTimeline as $tweet){

			if($tweet ===  null){
				continue;
			}

			$u[$tweet->user_id] = true;

			if($tweet->in_reply_to_user_id !== null){
				$u[$tweet->in_reply_to_user_id] = true;
			}

			if(isset($tweet->retweeted_status)){
				$u[$tweet->retweeted_status->user_id] = true;
			}

			if(isset($tweet->quoted_status)){
				$u[$tweet->quoted_status->user_id] = true;
			}
		}


		foreach(array_chunk(array_keys($u), 100) as $i => $ids){

			$v1Params = [
				'user_id'          => implode(',', $ids),
				'skip_status'      => true,
				'include_entities' => 'false',
			];

			$v1Response = $this->cachedRequest('/1.1/users/lookup.json', $v1Params);

			if(!$v1Response instanceof ResponseInterface){
				$this->logger->warning('could not fetch user profiles from v1 endpoint');

				continue;
			}

			$json = MessageUtil::decodeJSON($v1Response);

			$this->logger->info(sprintf('[%d] fetched data for %d user profile(s)', $i, count($json)));

			foreach($json as $user){
				$this->tempUsers[$user->id] = new User($user, true);
			}

		}

	}


}
