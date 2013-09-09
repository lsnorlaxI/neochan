<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

defined('TINYBOARD') or exit;

class Filter {
	public $flood_check;
	private $condition;
	
	public function __construct(array $arr) {
		foreach ($arr as $key => $value)
			$this->$key = $value;		
	}
	
	public function match(array $post, $condition, $match) {
		$condition = strtolower($condition);
		
		switch($condition) {
			case 'custom':
				if (!is_callable($match))
					error('Custom condition for filter is not callable!');
				return $match($post);
			case 'flood-match':
				if (!is_array($match))
					error('Filter condition "flood-match" must be an array.');
								
				// Filter out "flood" table entries which do not match this filter.
				
				$flood_check_matched = array();
				
				foreach ($this->flood_check as $flood_post) {
					foreach ($match as $flood_match_arg) {
						switch ($flood_match_arg) {
							case 'ip':
								if ($flood_post['ip'] != $_SERVER['REMOTE_ADDR'])
									continue 3;
								break;
							case 'body':
								if ($flood_post['posthash'] != make_comment_hex($post['body_nomarkup']))
									continue 3;
								break;
							case 'file':
								if (!isset($post['filehash']))
									return false;
								if ($flood_post['filehash'] != $post['filehash'])
									continue 3;
								break;
							case 'board':
								if ($flood_post['board'] != $post['board'])
									continue 3;
								break;
							case 'isreply':
								if ($flood_post['isreply'] == $post['op'])
									continue 3;
								break;
							default:
								error('Invalid filter flood condition: ' . $flood_match_arg);
						}
					}
					$flood_check_matched[] = $flood_post;
				}
				
				$this->flood_check = $flood_check_matched;
				
				return !empty($this->flood_check);
			case 'flood-time':
				foreach ($this->flood_check as $flood_post) {
					if (time() - $flood_post['time'] <= $match) {
						return true;
					}
				}
				return false;
			case 'flood-count':
				$count = 0;
				foreach ($this->flood_check as $flood_post) {
					if (time() - $flood_post['time'] <= $this->condition['flood-time']) {
						++$count;
					}
				}
				return $count >= $match;
			case 'name':
				return preg_match($match, $post['name']);
			case 'trip':
				return $match === $post['trip'];
			case 'email':
				return preg_match($match, $post['email']);
			case 'subject':
				return preg_match($match, $post['subject']);
			case 'body':
				return preg_match($match, $post['body_nomarkup']);
			case 'filename':
				if (!$post['has_file'])
					return false;
				return preg_match($match, $post['filename']);
			case 'extension':
				if (!$post['has_file'])
					return false;
				return preg_match($match, $post['body']);
			case 'ip':
				return preg_match($match, $_SERVER['REMOTE_ADDR']);
			case 'op':
				return $post['op'] == $match;
			case 'has_file':
				return $post['has_file'] == $match;
			default:
				error('Unknown filter condition: ' . $condition);
		}
	}
	
	public function action() {
		global $board;
		
		switch($this->action) {
			case 'reject':
				error(isset($this->message) ? $this->message : 'Posting throttled by filter.');
			case 'ban':
				if (!isset($this->reason))
					error('The ban action requires a reason.');
				
				$reason = $this->reason;
				
				if (isset($this->expires))
					$expires = time() + $this->expires;
				else
					$expires = 0; // Ban indefinitely
				
				if (isset($this->reject))
					$reject = $this->reject;
				else
					$reject = true;
				
				if (isset($this->all_boards))
					$all_boards = $this->all_boards;
				else
					$all_boards = false;
				
				$query = prepare("INSERT INTO ``bans`` VALUES (NULL, :ip, :mod, :set, :expires, :reason, :board, 0)");
				$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
				$query->bindValue(':mod', -1);
				$query->bindValue(':set', time());
				
				if ($expires)
					$query->bindValue(':expires', $expires);
				else
					$query->bindValue(':expires', null, PDO::PARAM_NULL);
				
				if ($reason)
					$query->bindValue(':reason', $reason);
				else
					$query->bindValue(':reason', null, PDO::PARAM_NULL);
				
				
				if ($all_boards)
					$query->bindValue(':board', null, PDO::PARAM_NULL);
				else
					$query->bindValue(':board', $board['uri']);
				
				$query->execute() or error(db_error($query));
				
				if ($reject) {
					if (isset($this->message))
						error($message);
					
					checkBan($board['uri']);
					exit;
				}
				
				break;
			default:
				error('Unknown filter action: ' . $this->action);
		}
	}
	
	public function check(array $post) {
		foreach ($this->condition as $condition => $value) {
			if ($condition[0] == '!') {
				$NOT = true;
				$condition = substr($condition, 1);
			} else $NOT = false;
			
			if ($this->match($post, $condition, $value) == $NOT)
				return false;
		}
		return true;
	}
}

function purge_flood_table() {
	global $config;
	
	// Determine how long we need to keep a cache of posts for flood prevention. Unfortunately, it is not
	// aware of flood filters in other board configurations. You can solve this problem by settings the
	// config variable $config['flood_cache'] (seconds).
	
	if (isset($config['flood_cache'])) {
		$max_time = &$config['flood_cache'];
	} else {
		$max_time = 0;
		foreach ($config['filters'] as $filter) {
			if (isset($filter['condition']['flood-time']))
				$max_time = max($max_time, $filter['condition']['flood-time']);
		}
	}
	
	$time = time() - $max_time;
	
	query("DELETE FROM ``flood`` WHERE `time` < $time") or error(db_error());
}

function do_filters(array $post) {
	global $config;
	
	if (!isset($config['filters']) || empty($config['filters']))
		return;
	
	foreach ($config['filters'] as $filter) {
		if (isset($filter['condition']['flood-match'])) {
			$has_flood = true;
			break;
		}
	}
	
	if (isset($has_flood)) {
		if ($post['has_file']) {
			$query = prepare("SELECT * FROM ``flood`` WHERE `ip` = :ip OR `posthash` = :posthash OR `filehash` = :filehash");
			$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
			$query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
			$query->bindValue(':filehash', $post['filehash']);
		} else {
			$query = prepare("SELECT * FROM ``flood`` WHERE `ip` = :ip OR `posthash` = :posthash");
			$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
			$query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
		}
		$query->execute() or error(db_error($query));
		$flood_check = $query->fetchAll(PDO::FETCH_ASSOC);
	} else {
		$flood_check = false;
	}
	
	foreach ($config['filters'] as $filter_array) {
		$filter = new Filter($filter_array);
		$filter->flood_check = $flood_check;
		if ($filter->check($post))
			$filter->action();
	}
	
	purge_flood_table();
}

