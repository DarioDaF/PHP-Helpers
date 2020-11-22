<?php
declare(strict_types=1);

class RedisScript {
	private string $source;
	private string $sha = '';
	function __construct($source) {
		$this->source = $source;
	}
	function eval($redis, array $params = []) {
		if($this->sha === '') {
			$this->sha = sha1($this->source);
		}
		$len = count($params);
		$res = $redis->evalSha($this->sha, $params, $len);
		if($res === false) {
			$res = $redis->eval($this->source, $params, $len);
		}
		if($res === false) {
			throw new RedisException($redis->getLastError());
		}
		return $res;
	}
}

// On redis.conf you can disable ALL save to have only online data

//global $REDIS;
//$REDIS = new Redis();
//$REDIS->pconnect('127.0.0.1'); // Defer connection?
