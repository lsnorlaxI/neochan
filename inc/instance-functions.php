<?php
require_once("inc/8chan-functions.php");
require_once("inc/functions.php");

function max_posts_per_hour($post)
{
	global $config, $board;
	
	if (!$config['hour_max_threads']) {
		return false;
	}

	if ($post['op']) {
		$identity = Session::getIdentity();
		$query = prepare(sprintf('SELECT COUNT(*) AS `count` FROM ``posts_%s`` WHERE `thread` IS NULL AND FROM_UNIXTIME(`time`) > DATE_SUB(NOW(), INTERVAL 1 HOUR);', $board['uri']));
		$query->bindValue(':ip', $identity);
		$query->execute() or error(db_error($query));
		$r = $query->fetch(PDO::FETCH_ASSOC);

		return ($r['count'] > $config['hour_max_threads']);
	}
}

function filename_func($a)
{
	$f = basename($a['filename'], '.'.$a['extension']);
	$f = str_replace(array("\0", "\n", "<", ">", "/", "&"), array("?", "?", "«", "»", "⁄", "and"), $f);
	return $f;
}
