<?php

// SQLite session handler
// version: 1.0
// Author: Saemon Zixel, 2012-2018, http://saemonzixel.ru/
// Based on http://github.com/kafene/PdoSqliteSessionHandler
// Public Domain. Do with it all you want.

// Session lifetime = 1 month
// ini_set('session.gc_maxlifetime', 3600*24*30);
// ini_set('session.cookie_lifetime', 3600*24*30);

global $session_sqlite_db, $session_sqlite_table;

/**
* Re-initialize existing session, or creates a new one.
* Called when a session starts or when session_start() is invoked.
*
* @param string $savePath The path where to store/retrieve the session.
* @param string $name The session name.
*/
function session_sqlite_open($savePath, $sessionName) {
	global $session_sqlite_db, $session_sqlite_table;
	
	if (!is_null($session_sqlite_db)) {
		trigger_error('Bad call to open(): connection already opened.', E_USER_NOTICE);
	} 

	if (false === realpath($savePath)) {
		mkdir($savePath, 0700, true);
	}

	if (empty($savePath)) { 
		if(php_sapi_name() != 'cli') 
			error_log("Session save path is empty! (use /tmp)", E_USER_ERROR);
			
		$savePath = '/tmp';
	}
	
	if (!is_dir($savePath) || !is_writable($savePath)) {
		trigger_error("Invalid session save path - $savePath", E_USER_ERROR);
	}

	$dbOptions = array(
		PDO::ATTR_TIMEOUT => 2, // 2 sec timeout
		PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING // PDO::ERRMODE_EXCEPTION,
//             PDO::ATTR_AUTOCOMMIT => false,
	);

	$dsn = 'sqlite:'.$savePath.DIRECTORY_SEPARATOR.'sessions.sqlite';
	$pdo = $session_sqlite_db = new PDO($dsn, NULL, NULL, $dbOptions);
	$table = $session_sqlite_table = '"'.strtolower($sessionName).'"';

	$pdo->exec("PRAGMA page_size=4096"); // default 1k
	$pdo->exec("PRAGMA journal_mode=WAL"); // enable WAL-mode (sqlite 3.7+ нужен)
	$pdo->exec('PRAGMA journal_size_limit = '.(4 * 1024 * 1024)); // size of WAL-journal = 4Mb
	$pdo->exec('PRAGMA synchronous = 1'); // 2-FULL, 1-NORMAL, 0-OFF 
	$pdo->exec('PRAGMA temp_store=MEMORY');
	$pdo->exec('PRAGMA cache_size = 4000'); // double cache size in RAM
	$pdo->exec('PRAGMA encoding="UTF-8"');
	$pdo->exec('PRAGMA auto_vacuum=FULL');
	$pdo->exec('PRAGMA synchronous=NORMAL');
// 	$pdo->exec('PRAGMA secure_delete=1');
// 	$pdo->exec('PRAGMA writable_schema=0');

	$pdo->exec(
		"CREATE TABLE IF NOT EXISTS {$table} (
			id TEXT PRIMARY KEY NOT NULL,
			data TEXT CHECK (TYPEOF(data) = 'text') NOT NULL DEFAULT '',
			time INTEGER CHECK (TYPEOF(time) = 'integer') NOT NULL
		)"
	); // time DEFAULT (strftime('%s', 'now'))

	return true;
}

/**
* Closes the current session.
*/
function session_sqlite_close() {
	global $session_sqlite_db;
		
	$session_sqlite_db = null;
	return true;
}

/**
* Returns an encoded string of the read data.
* If nothing was read, it must return an empty string.
* This value is returned internally to PHP for processing.
*
* @param string $id The session id.
*
* @return string
*/
function session_sqlite_read($id) {
	global $session_sqlite_db, $session_sqlite_table;

	$file = session_save_path()."/sess_$id";
	if(file_exists($file)) {
		$row = $session_sqlite_db->query("SELECT id FROM $session_sqlite_table WHERE id = ".$session_sqlite_db->quote($id))->fetch();
		if(empty($row))
			session_sqlite_write($id, file_get_contents($file), filemtime($file));
	}
		
	$sql = "SELECT data FROM {$session_sqlite_table} WHERE id = :id LIMIT 1";
	$sth = $session_sqlite_db->prepare($sql);
	$sth->bindParam(':id', $id, PDO::PARAM_STR);
	$sth->execute();
	$rows = $sth->fetchAll(PDO::FETCH_NUM);
	return $rows ? $rows[0][0] : '';
}

/**
* Writes the session data to the session storage.
*
* Called by session_write_close(),
* when session_register_shutdown() fails,
* or during a normal shutdown.
*
* close() is called immediately after this function.
*
* @param string $id The session id.
* @param string $data The encoded session data.
*
* @return boolean
*/
function session_sqlite_write($id, $data) {
	global $session_sqlite_db, $session_sqlite_table;
	
	$sql = "REPLACE INTO {$session_sqlite_table} (id, data, time) VALUES (:id, :data, :time)";
	$sth = $session_sqlite_db->prepare($sql);
	$sth->bindParam(':id', $id, PDO::PARAM_STR);
	$sth->bindValue(':data', $data, PDO::PARAM_STR);
	$sth->bindValue(':time', time(), PDO::PARAM_INT);
	return $sth->execute();
}

/**
* Destroys a session.
*
* Called by session_regenerate_id() (with $destroy = TRUE),
* session_destroy() and when session_decode() fails.
*
* @param string $id The session ID being destroyed.
*
* @return boolean
*/
function session_sqlite_destroy($id) {
	global $session_sqlite_db, $session_sqlite_table;
	
	$sql = "DELETE FROM {$session_sqlite_table} WHERE id = :id";
	$sth = $this->getDb()->prepare($sql);
	$sth->bindParam(':id', $id, PDO::PARAM_STR);
	return $sth->execute();
}

/**
* Cleans up expired sessions.
* Called by session_start(), based on session.gc_divisor,
* session.gc_probability and session.gc_lifetime settings.
*
* @param string $lifetime Sessions that have not updated for
*     the last `$lifetime` seconds will be removed.
*
* @return boolean
*/
function session_sqlite_gc($lifetime) {
	global $session_sqlite_db, $session_sqlite_table;
		
// 	error_log('session_sqlite_gc = '.json_encode($session_sqlite_db->query("SELECT count(*) as cnt FROM {$session_sqlite_table}  WHERE time < ".(time() - $lifetime))->fetch()));
		
	$sql = "DELETE FROM {$session_sqlite_table} WHERE time < :time";
	$sth = $session_sqlite_db->prepare($sql);
	$sth->bindValue(':time', time() - $lifetime, PDO::PARAM_INT);
	$result = $sth->execute();
	
	error_log('session_sqlite_gc = '.$sth->rowCount());
	return $result;
}

if(is_readable(session_save_path().DIRECTORY_SEPARATOR.'sessions.sqlite')) {
	// connect our session handler
	session_set_save_handler(
		'session_sqlite_open',
		'session_sqlite_close',
		'session_sqlite_read',
		'session_sqlite_write',
		'session_sqlite_destroy',
		'session_sqlite_gc'
	);

	register_shutdown_function('session_write_close');
}
else 
	error_log(session_save_path().DIRECTORY_SEPARATOR.'sessions.sqlite - not readable! (switch to default session handler)');