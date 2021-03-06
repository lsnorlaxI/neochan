<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

defined('TINYBOARD') or exit;

class Cache 
{
	private static $cache;

	public static function init() 
	{
		global $config;
		
		switch ($config['cache']['enabled']) {
			case 'memcache':
				self::$cache = new Memcache;
				self::$cache->addServer($config['cache']['memcache'][0], $config['cache']['memcache'][1]);
				break;
			case 'memcached':
				self::$cache = new Memcached();
				self::$cache->addServers($config['cache']['memcached']);
				break;
			case 'redis':
				self::$cache = new Redis();
				self::$cache->connect($config['cache']['redis'][0], $config['cache']['redis'][1]);
				if ($config['cache']['redis'][2]) {
					self::$cache->auth($config['cache']['redis'][2]);
				}
				self::$cache->select($config['cache']['redis'][3]) or die('cache select failure');
				break;
			case 'php':
				self::$cache = array();
				break;
		}
	}

	public static function get($key) 
	{
		global $config;

		$data = false;
		switch ($config['cache']['enabled']) {
			case 'memcache':
				// no need break
			case 'memcached':
				if (!self::$cache)
					self::init();
				$data = self::$cache->get($key);
				break;
			case 'apc':
				$data = apc_fetch($key);
				break;
			case 'xcache':
				$data = xcache_get($key);
				break;
			case 'php':
				$data = isset(self::$cache[$key]) ? self::$cache[$key] : false;
				break;
			case 'fs':
				$key = str_replace('/', '::', $key);
				$key = str_replace("\0", '', $key);
				if (!file_exists('tmp/cache/'.$key)) {
					$data = false;
				}
				else {
					$data = file_get_contents('tmp/cache/'.$key);
					$data = json_decode($data, true);
				}
				break;
			case 'redis':
				if (!self::$cache)
					self::init();
				$data = json_decode(self::$cache->get($key), true);
				break;
		}
				
		return $data;
	}

	public static function set($key, $value, $expires = false) 
	{
		global $config;

		if (!$expires)
			$expires = $config['cache']['timeout'];

		if($expires  > 2592000){
			syslog(LOG_WARNING, "[NOTICE] Cache::set - variable \$expires ($expires) looks like unix timestamp");
		}
		
		switch ($config['cache']['enabled']) {
			case 'memcache':
				if (!self::$cache)
					self::init();
				self::$cache->set($key, $value, 0, $expires);
				break;
			case 'memcached':
				if (!self::$cache)
					self::init();
				self::$cache->set($key, $value, $expires);
				break;
			case 'redis':
				if (!self::$cache)
					self::init();
				self::$cache->setex($key, $expires, json_encode($value));
				break;
			case 'apc':
				apc_store($key, $value, $expires);
				break;
			case 'xcache':
				xcache_set($key, $value, $expires);
				break;
			case 'fs':
				$key = str_replace('/', '::', $key);
				$key = str_replace("\0", '', $key);
				file_put_contents('tmp/cache/'.$key, json_encode($value));
				break;
			case 'php':
				self::$cache[$key] = $value;
				break;
		}	
	}
	
	public static function delete($key)
	{
		global $config;

		switch ($config['cache']['enabled']) {
			case 'memcache':				
				// no need break
			case 'memcached':				
				// no need break
			case 'redis':
				if (!self::$cache)
					self::init();
				self::$cache->delete($key);
				break;
			case 'apc':

				apc_delete($key);

				break;
			case 'xcache':
				xcache_unset($key);
				break;
			case 'fs':
				$key = str_replace('/', '::', $key);
				$key = str_replace("\0", '', $key);
				@unlink('tmp/cache/'.$key);
				break;
			case 'php':
				unset(self::$cache[$key]);
				break;
		}		
	}
	
	public static function flush()
	{
		global $config;
		
		switch ($config['cache']['enabled']) {
			case 'memcache':		
				// no need break
			case 'memcached':
				if (!self::$cache)
					self::init();
				return self::$cache->flush();
			case 'apc':
				return apc_clear_cache('user');
			case 'php':
				self::$cache = array();
				break;
			case 'fs':
				$files = glob('tmp/cache/*');
				foreach ($files as $file) {
					unlink($file);
				}
				break;
			case 'redis':
				if (!self::$cache)
					self::init();
				return self::$cache->flushDB();
		}
		
		return false;
	}

	public static function flush_posts($boardname, $thread_id)
	{
		self::delete("div_megaposts_{$boardname}_{$thread_id}");
		self::delete("div_posts_{$boardname}_{$thread_id}");
	
		self::delete("thread_index_{$boardname}_{$thread_id}");
		self::delete("thread_{$boardname}_{$thread_id}");
	
		self::delete("last_post_id_{$boardname}");
		self::delete("div_thread_active_{$boardname}");
	}

	public static function flush_board($boardname)
	{
		self::delete('_cachev2_' . $boardname );
	}

	public static function flush_thread($boardname, $thread_id)
	{
		self::delete('_cachev2_' . $boardname );
		self::delete('_cachev2_' . $boardname . '_' . $thread_id);
	}







}

