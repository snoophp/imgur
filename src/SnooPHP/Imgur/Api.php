<?php

namespace SnooPHP\Imgur;

use \CURLFile;

use SnooPHP\Curl\Curl;

/**
 * Perform raw api requests or use dedicated methods
 * 
 * Requests can be saved in a dedicated cache
 * 
 * @author Sneppy
 */
class Api
{
	/**
	 * @var string $clientId application client id
	 */
	protected $clientId;

	/**
	 * @var string $clientSecret application secret id
	 */
	protected $clientSecret;

	/**
	 * @var object $token user access token, used for api requests
	 */
	protected $token;

	/**
	 * @var string $lastResult store last request result (raw)
	 */
	protected $lastResult;

	/**
	 * @var string $version api version (default: 3)
	 */
	protected $version = "3";

	/**
	 * @var string $cacheClass cache class
	 */
	protected $cacheClass;

	/**
	 * @var string $defaultCacheClass
	 */
	protected static $defaultCacheClass = "SnooPHP\Cache\NullCache";

	/**
	 * @const ENDPOINT facebook api endpoint
	 */
	const ENDPOINT = "https://api.imgur.com";
	
	// SUBCLASSES
	protected static $imageClass = "SnooPHP\Imgur\Image";
	protected static $albumClass = "SnooPHP\Imgur\Album";

	/**
	 * Create a new instance
	 */
	public function __construct()
	{
		// Set cache class
		$this->cacheClass = static::$defaultCacheClass;
	}

	/**
	 * Return last result
	 * 
	 * @param bool	$json	if true return a php object rather than a json string
	 * 
	 * @return string|object
	 */
	public function lastResult($json = true)
	{
		return $json ? from_json($this->lastResult) : $this->lastResult;
	}

	/**
	 * Returns an empty Image resource
	 * 
	 * @return Image
	 */
	public function image()
	{
		return new static::$imageClass($this, []);
	}

	/**
	 * Returns an empty Album resource
	 *
	 * @return Album
	 */
	public function album()
	{
		return new static::$albumClass($this, []);
	}

	/**
	 * Perform a generic query
	 *
	 * @param string	$query	query string (with parameters)
	 * @param string	$method	query method [default: GET]
	 * @param string|array	$data	data to post [default: []]
	 * 
	 * @return object|bool false if fails
	 */
	public function query($query, $method = "GET", $data = "")
	{
		// If no access token, try to use client token
		if (!$this->token || empty($this->token->access_token))
			$token = $this->anonToken();
		else
			$token = $this->token;

		// Build uri
		$uri	= preg_match("/^https?:\/\//", $query) ? $query : static::ENDPOINT."/{$this->version}/$query";

		// Check if cached result exists
		if (!strcasecmp($method, "GET") && ($record = $this->cacheClass::fetch("$uri|$token"))) return $record;

		// Make api request
		$curl = Curl::create($method, $uri, $data, ["Authorization" => $token]);
		if ($curl && $curl->success())
		{
			// Save record in cache and return it
			$this->lastResult = $curl->content();
			return $this->cacheClass::store("$uri|$token", $this->lastResult);
		}
		else
		{
			$this->lastResult = false;
			return false;
		}
	}

	/**
	 * Get an anonymous token
	 * 
	 * @return string
	 */
	public function anonToken()
	{
		return "Client-ID {$this->clientId}";
	}

	/**
	 * Create a new instance from client id and client secret
	 * 
	 * @param string	$clientId		client id
	 * @param string	$clientSecret	client secret [default: ""]
	 * 
	 * @return Api
	 */
	public static function withClient($clientId, $clientSecret = "")
	{
		$api = new static();
		$api->clientId		= $clientId;
		$api->clientSecret	= $clientSecret;
		return $api;
	}

	/**
	 * Create a new instance from existing access token
	 * 
	 * @param string	$token	provided access token
	 * 
	 * @return Api
	 */
	public static function withToken($token)
	{
		$api = new static();
		$api->token = $token;
		return $api;
	}

	/**
	 * Set or get default cache class for this session
	 * 
	 * @param string|null	$defaultCacheClass	cache full classname
	 * 
	 * @return string
	 */
	public static function defaultCacheClass($defaultCacheClass = null)
	{
		if ($defaultCacheClass) static::$defaultCacheClass = $defaultCacheClass;
		return static::$defaultCacheClass;
	}
}

/**
 * A generic resource returned by the Imgur API
 *
 * @author Sneppy
 */
class Resource
{
	/**
	 * @var Api $api associated api that will be used for further requests (usually the api that created this resource)
	 */
	protected $api;

	/**
	 * Create a new resource
	 * @param Api $api associated api
	 * @param array $data resource data
	 */
	public function __construct(Api $api = null, array $data = [])
	{
		// Set associated api
		$this->api = $api;

		// Add resource data
		foreach ($data as $name => $val) $this->$name = $val;
	}

	/**
	 * Get or set api associated with this resource
	 *
	 * @param Api $api new associated api
	 *
	 * @return Api
	 */
	public function api(Api $api = null)
	{
		if ($api) $this->api = $api;
		return $this->api;
	}
}

/**
 * An image returned by the Imgur API
 *
 * @author Sneppy
 */
class Image extends Resource
{
	/**
	 * Fetch an image by id
	 * 
	 * @param string $id image id
	 *
	 * @return bool
	 */
	public function fetch($id)
	{
		// Check api
		if (!$this->api)
		{
			error_log("imgur error: api not initialized");
			return false;
		}

		$res	= $this->api->query("image/$id");
		$res	= from_json($res);
		if ($res)
			foreach ($res->data as $data => $val) $this->$data = $val;

		return $this;
	}

	/**
	 * Upload an image
	 *
	 * @param string		$image				  image filaname
	 * @param string		$album				  album hash (deletehash for anonymous albums)
	 * @param string		$title				  image title
	 * @param string		$description	image description
	 * @param string		$name				   optional file name
	 *
	 * @return string json response
	 */
	public function upload($image, $album = "", $title = "", $description = "", $name = "")
	{
		// Check api
		if (!$this->api)
		{
			error_log("imgur error: api not initialized");
			return false;
		}

		// Build POST data
		$data = ["image" => new CURLFile($image)];
		if (!empty($album))				$data["album"]			= $album;
		if (!empty($name))				$data["name"]			= $name;
		if (!empty($title))				$data["title"]			= $title;
		if (!empty($description))		$data["description"]	= $description;

		$res	= $this->api->query("image", "POST", $data);
		$res	= from_json($res);
		if ($res)
			foreach ($res->data as $data => $val) $this->$data = $val;

		return $this;
	}
}
