<?php

class MySQL_CRUD_API extends REST_CRUD_API {

	protected function connectDatabase($hostname,$username,$password,$database,$port,$socket,$charset) {
		$db = mysqli_connect($hostname,$username,$password,$database,$port,$socket);
		if (mysqli_connect_errno()) {
			throw new \Exception('Connect failed. '.mysqli_connect_error());
		}
		if (!mysqli_set_charset($db,$charset)) {
			throw new \Exception('Error setting charset. '.mysqli_error($db));
		}
		if (!mysqli_query($db,'SET SESSION sql_mode = \'ANSI_QUOTES\';')) {
			throw new \Exception('Error setting ANSI quotes. '.mysqli_error($db));
		}
		return $db;
	}
	
	protected function query($db,$sql,$params) {
		$sql = preg_replace_callback('/\!|\?/', function ($matches) use (&$db,&$params) {
			$param = array_shift($params);
			if ($matches[0]=='!') return preg_replace('/[^a-zA-Z0-9\-_=<>]/','',$param);
			if (is_array($param)) return '('.implode(',',array_map(function($v) use (&$db) {
				return "'".mysqli_real_escape_string($db,$v)."'";
			},$param)).')';
			return "'".mysqli_real_escape_string($db,$param)."'";
		}, $sql);
		//echo "\n$sql\n";
		return mysqli_query($db,$sql);
	}
	
	protected function fetch_assoc($result) {
		return mysqli_fetch_assoc($result);
	}
	
	protected function fetch_row($result) {
		return mysqli_fetch_row($result);
	}

	protected function insert_id($db) {
		return mysqli_insert_id();
	}
		
	protected function affected_rows($db) {
		return mysqli_affected_rows();
	}
	
	protected function close($result) {
		return mysqli_free_result($result);
	}
	
	protected function fetch_fields($result) {
		return mysqli_fetch_fields($result);
	}
	
}

class REST_CRUD_API {

	protected $config;

	protected function mapMethodToAction($method,$request) {
		switch ($method) {
			case 'GET': return count($request)>1?'read':'list';
			case 'PUT': return 'update';
			case 'POST': return 'create';
			case 'DELETE': return 'delete';
			default: $this->exitWith404('method');
		}
	}

	protected function parseRequestParameter($request,$position,$characters,$default) {
		$value = isset($request[$position])?$request[$position]:$default;
		return $characters?preg_replace("/[^$characters]/",'',$value):$value;
	}

	protected function parseGetParameter($get,$name,$characters,$default) {
		$value = isset($get[$name])?$get[$name]:$default;
		return $characters?preg_replace("/[^$characters]/",'',$value):$value;
	}

	protected function applyWhitelist($table,$action,$list) {
		if ($list===false) return $table;
		$list = array_filter($list, function($actions) use ($action) {
			return strpos($actions,$action[0])!==false;
		});
		return array_intersect($table, array_keys($list));
	}

	protected function applyBlacklist($table,$action,$list) {
		if ($list===false) return $table;
		$list = array_filter($list, function($actions) use ($action) {
			return strpos($actions,$action[0])!==false;
		});
		return array_diff($table, array_keys($list));
	}

	protected function applyWhitelistAndBlacklist($table, $action, $whitelist, $blacklist) {
		$table = $this->applyWhitelist($table, $action, $whitelist);
		$table = $this->applyBlacklist($table, $action, $blacklist);
		return $table;
	}

	protected function processTableParameter($table,$database,$db) {
		$tablelist = explode(',',$table);
		$tables = array();
		foreach ($tablelist as $table) {
			$table = str_replace('*','%',$table);
			if ($result = $this->query($db,'SELECT "TABLE_NAME" FROM "INFORMATION_SCHEMA"."TABLES" WHERE "TABLE_NAME" LIKE ? AND "TABLE_SCHEMA" = ?',array($table,$database))) {
				while ($row = $this->fetch_row($result)) $tables[] = $row[0];
				$this->close($result);
			}
		}
		return $tables;
	}

	protected function findSinglePrimaryKey($table,$database,$db) {
		$keys = array();
		if ($result = $this->query($db,'SELECT "COLUMN_NAME" FROM "INFORMATION_SCHEMA"."COLUMNS" WHERE "COLUMN_KEY" = \'PRI\' AND "TABLE_NAME" = ? AND "TABLE_SCHEMA" = ?',array($table[0],$database))) {
			while ($row = $this->fetch_row($result)) $keys[] = $row[0];
			$this->close($result);
		}
		return count($keys)==1?$keys[0]:false;
	}

	protected function exitWith404($type) {
		if (isset($_SERVER['REQUEST_METHOD'])) {
			header('Content-Type:',true,404);
			die("Not found ($type)");
		} else {
			throw new \Exception("Not found ($type)");
		}
	}

	protected function startOutput($callback) {
		if (isset($_SERVER['REQUEST_METHOD'])) {
			if ($callback) {
				header('Content-Type: application/javascript');
				echo $callback.'(';
			} else {
				header('Content-Type: application/json');
			}
		}
	}

	protected function endOutput($callback) {
		if ($callback) {
			echo ');';
		}
	}

	protected function processKeyParameter($key,$table,$database,$db) {
		if ($key) {
			$key = array($key,$this->findSinglePrimaryKey($table,$database,$db));
			if ($key[1]===false) $this->exitWith404('1pk');
		}
		return $key;
	}

	protected function processOrderParameter($order,$table,$database,$db) {
		if ($order) {
			$order = explode(',',$order,2);
			if (count($order)<2) $order[1]='ASC';
			$order[1] = strtoupper($order[1])=='DESC'?'DESC':'ASC';
		}
		return $order;
	}

	protected function processFilterParameter($filter,$match,$db) {
		if ($filter) {
			$filter = explode(':',$filter,2);
			if (count($filter)==2) {
				$filter[2] = 'LIKE';
				if ($match=='contain') $filter[1] = '%'.addcslashes($filter[1], '%_').'%';
				if ($match=='start') $filter[1] = addcslashes($filter[1], '%_').'%';
				if ($match=='end') $filter[1] = '%'.addcslashes($filter[1], '%_');
				if ($match=='exact') $filter[2] = '=';
				if ($match=='lower') $filter[2] = '<';
				if ($match=='upto') $filter[2] = '<=';
				if ($match=='from') $filter[2] = '>=';
				if ($match=='higher') $filter[2] = '>';
				if ($match=='in') {
					$filter[2] = 'IN';
					$filter[1] = explode(',',$filter[1]);

				}
			} else {
				$filter = false;
			}
		}
		return $filter;
	}

	protected function processPageParameter($page) {
		if ($page) {
			$page = explode(',',$page,2);
			if (count($page)<2) $page[1]=20;
			$page[0] = ($page[0]-1)*$page[1];
		}
		return $page;
	}

	protected function retrieveObject($key,$table,$db) {
		if (!$key) return false;
		if ($result = $this->query($db,'SELECT * FROM "!" WHERE "!" = ?',array($table[0],$key[1],$key[0]))) {
			$object = $this->fetch_assoc($result);
			$this->close($result);
		}
		return $object;
	}

	protected function createObject($input,$table,$db) {
		if (!$input) return false;
		$keys = implode('","',split('', str_repeat('!', count($input))));
		$values = implode(',',split('', str_repeat('?', count($input))));
		$params = array_merge(array_keys((array)$input),array_values((array)$input));
		array_unshift($params, $table[0]);
		$this->query($db,'INSERT INTO "!" ("'.$keys.'") VALUES ('.$values.')',$params);
		return $this->insert_id($db);
	}

	protected function updateObject($key,$input,$table,$db) {
		if (!$input) return false;
		$params = array();
		$sql = 'UPDATE "?" SET ';
		$params[] = $table[0];
		foreach (array_keys((array)$input) as $i=>$k) {
			if ($i) $sql .= ',';
			$v = $input->$k;
			$sql .= '"!"=?';
			$params[] = $k;
			$params[] = $v;
		}
		$sql .= ' WHERE "!"=?';
		$params[] = $key[1];
		$params[] = $key[0];
		$this->query($db,$sql,$params);
		return $this->affected_rows($db);
	}

	protected function deleteObject($key,$table,$db) {
		$this->query($db,'DELETE FROM "!" WHERE "!" = ?',array($table[0],$key[1],$key[0]));
		return $this->affected_rows($db);
	}

	protected function findRelations($tables,$database,$db) {
		$collect = array();
		$select = array();
		if (count($tables)>1) {
			$table0 = array_shift($tables);
			
			$result = $this->query($db,'SELECT
								"TABLE_NAME","COLUMN_NAME",
								"REFERENCED_TABLE_NAME","REFERENCED_COLUMN_NAME"
							FROM
								"INFORMATION_SCHEMA"."KEY_COLUMN_USAGE"
							WHERE
								"TABLE_NAME" = ? AND
								"REFERENCED_TABLE_NAME" IN ? AND
								"TABLE_SCHEMA" = ? AND
								"REFERENCED_TABLE_SCHEMA" = ?',
							array($table0,$tables,$database,$database));
			while ($row = $this->fetch_row($result)) {
				$collect[$row[0]][$row[1]]=array();
				$select[$row[2]][$row[3]]=array($row[0],$row[1]);
			}
			$result = $this->query($db,'SELECT
								"TABLE_NAME","COLUMN_NAME",
								"REFERENCED_TABLE_NAME","REFERENCED_COLUMN_NAME"
							FROM
								"INFORMATION_SCHEMA"."KEY_COLUMN_USAGE"
							WHERE
								"TABLE_NAME" IN ? AND
								"REFERENCED_TABLE_NAME" = ? AND
								"TABLE_SCHEMA" = ? AND
								"REFERENCED_TABLE_SCHEMA" = ?',
							array($tables,$table0,$database,$database));
			while ($row = $this->fetch_row($result)) {
				$collect[$row[2]][$row[3]]=array();
				$select[$row[0]][$row[1]]=array($row[2],$row[3]);
			}
			$result = $this->query($db,'SELECT
								k1."TABLE_NAME", k1."COLUMN_NAME",
								k1."REFERENCED_TABLE_NAME", k1."REFERENCED_COLUMN_NAME",
								k2."TABLE_NAME", k2."COLUMN_NAME",
								k2."REFERENCED_TABLE_NAME", k2."REFERENCED_COLUMN_NAME"
							FROM
								"INFORMATION_SCHEMA"."KEY_COLUMN_USAGE" k1, "INFORMATION_SCHEMA"."KEY_COLUMN_USAGE" k2
							WHERE
								k1."TABLE_SCHEMA" = ? AND
								k2."TABLE_SCHEMA" = ? AND
								k1."REFERENCED_TABLE_SCHEMA" = ? AND
								k2."REFERENCED_TABLE_SCHEMA" = ? AND
								k1."TABLE_NAME" = k2."TABLE_NAME" AND
								k1."REFERENCED_TABLE_NAME" = ? AND
								k2."REFERENCED_TABLE_NAME" IN ?',
							array($database,$database,$database,$database,$table0,$tables));
			while ($row = $this->fetch_row($result)) {
				$collect[$row[2]][$row[3]]=array();
				$select[$row[0]][$row[1]]=array($row[2],$row[3]);
				$collect[$row[4]][$row[5]]=array();
				$select[$row[6]][$row[7]]=array($row[4],$row[5]);
			}
		}
		return array($collect,$select);
	}

	protected function getParameters($config) {
		extract($config);
		$action    = $this->mapMethodToAction($method, $request);
		$table     = $this->parseRequestParameter($request, 0, 'a-zA-Z0-9\-_*,', '*');
		$key       = $this->parseRequestParameter($request, 1, 'a-zA-Z0-9\-,', false); // auto-increment or uuid
		$callback  = $this->parseGetParameter($get, 'callback', 'a-zA-Z0-9\-_', false);
		$page      = $this->parseGetParameter($get, 'page', '0-9,', false);
		$filter    = $this->parseGetParameter($get, 'filter', false, false);
		$match     = $this->parseGetParameter($get, 'match', 'a-z', 'exact');
		$order     = $this->parseGetParameter($get, 'order', 'a-zA-Z0-9\-_*,', false);
		$transform = $this->parseGetParameter($get, 'transform', '1', false);

		$table  = $this->processTableParameter($table,$database,$db);
		$key    = $this->processKeyParameter($key,$table,$database,$db);
		$filter = $this->processFilterParameter($filter,$match,$db);
		$page   = $this->processPageParameter($page);
		$order  = $this->processOrderParameter($order,$table,$database,$db);

		$table  = $this->applyWhitelistAndBlacklist($table,$action,$whitelist,$blacklist);
		if (empty($table)) $this->exitWith404('entity');

		$object = $this->retrieveObject($key,$table,$db);
		$input  = json_decode(file_get_contents($post));

		list($collect,$select) = $this->findRelations($table,$database,$db);

		return compact('action','table','key','callback','page','filter','match','order','transform','db','object','input','collect','select');
	}

	protected function listCommand($parameters) {
		extract($parameters);
		$this->startOutput($callback);
		echo '{';
		$tables = $table;
		$table = array_shift($tables);
		// first table
		$count = false;
		echo '"'.$table.'":{';
		if (is_array($order) && is_array($page)) {
			$params = array();
			$sql = 'SELECT COUNT(*) FROM "!"';
			$params[] = $table;
			if (is_array($filter)) {
				$sql .= ' WHERE "!" ! ?';
				$params[] = $filter[0];
				$params[] = $filter[2];
				$params[] = $filter[1];
			}
			if ($result = $this->query($db,$sql,$params)) {
				while ($pages = $this->fetch_row($result)) {
					$count = $pages[0];
				}
			}
		}
		$params = array();
		$sql = 'SELECT * FROM "!"';
		$params[] = $table;
		if (is_array($filter)) {
			$sql .= ' WHERE "!" ! ?';
			$params[] = $filter[0];
			$params[] = $filter[2];
			$params[] = $filter[1];
		}
		if (is_array($order)) {
			$sql .= ' ORDER BY "!" !';
			$params[] = $order[0];
			$params[] = $order[1];
		}
		if (is_array($order) && is_array($page)) {
			$sql .= ' LIMIT ! OFFSET !';
			$params[] = $page[1];
			$params[] = $page[0];
		}
		if ($result = $this->query($db,$sql,$params)) {
			echo '"columns":';
			$fields = array();
			foreach ($this->fetch_fields($result) as $field) $fields[] = $field->name;
			echo json_encode($fields);
			$fields = array_flip($fields);
			echo ',"records":[';
			$first_row = true;
			while ($row = $this->fetch_row($result)) {
				if ($first_row) $first_row = false;
				else echo ',';
				if (isset($collect[$table])) {
					foreach (array_keys($collect[$table]) as $field) {
						$collect[$table][$field][] = $row[$fields[$field]];
					}
				}
				echo json_encode($row);
			}
			$this->close($result);
			echo ']';
		}
		if ($count) echo ',"results":'.$count;
		echo '}';
		// prepare for other tables
		foreach (array_keys($collect) as $t) {
			if ($t!=$table && !in_array($t,$tables)) {
				array_unshift($tables,$t);
			}
		}
		// other tables
		foreach ($tables as $t=>$table) {
			echo ',';
			echo '"'.$table.'":{';
			$params = array();
			$sql = 'SELECT * FROM "!"';
			$params[] = $table;
			if (isset($select[$table])) {
				$first_row = true;
				echo '"relations":{';
				foreach ($select[$table] as $field => $path) {
					$values = $collect[$path[0]][$path[1]];
					$sql .= $first_row?' WHERE ':' OR ';
					$sql .= '"!" IN ?';
					$params[] = $field;
					$params[] = $values;
					if ($first_row) $first_row = false;
					else echo ',';
					echo '"'.$field.'":"'.implode('.',$path).'"';
				}
				echo '},';
			}
			if ($result = $this->query($db,$sql,$params)) {
				echo '"columns":';
				$fields = array();
				foreach ($this->fetch_fields($result) as $field) $fields[] = $field->name;
				echo json_encode($fields);
				$fields = array_flip($fields);
				echo ',"records":[';
				$first_row = true;
				while ($row = $this->fetch_row($result)) {
					if ($first_row) $first_row = false;
					else echo ',';
					if (isset($collect[$table])) {
						foreach (array_keys($collect[$table]) as $field) {
							$collect[$table][$field][]=$row[$fields[$field]];
						}
					}
					echo json_encode($row);
				}
				$this->close($result);
				echo ']';
			}
			echo '}';
		}
		echo '}';
		$this->endOutput($callback);
	}

	protected function readCommand($parameters) {
		extract($parameters);
		if (!$object) $this->exitWith404('object');
		$this->startOutput($callback);
		echo json_encode($object);
		$this->endOutput($callback);
	}

	protected function createCommand($parameters) {
		extract($parameters);
		if (!$input) $this->exitWith404('input');
		$this->startOutput($callback);
		echo json_encode($this->createObject($input,$table,$db));
		$this->endOutput($callback);
	}

	protected function updateCommand($parameters) {
		extract($parameters);
		if (!$input) $this->exitWith404('subject');
		$this->startOutput($callback);
		echo json_encode($this->updateObject($key,$input,$table,$db));
		$this->endOutput($callback);
	}

	protected function deleteCommand($parameters) {
		extract($parameters);
		$this->startOutput($callback);
		echo json_encode($this->deleteObject($key,$table,$db));
		$this->endOutput($callback);
	}

	protected function listCommandTransform($parameters) {
		if ($parameters['transform']) {
			ob_start();
		}
		$this->listCommand($parameters);
		if ($parameters['transform']) {
			$content = ob_get_contents();
			ob_end_clean();
			$data = json_decode($content,true);
			echo json_encode(self::mysql_crud_api_transform($data));
		}
	}

	public function __construct($config) {
		extract($config);

		$hostname = isset($hostname)?$hostname:null;
		$username = isset($username)?$username:'root';
		$password = isset($password)?$password:null;
		$database = isset($database)?$database:'';
		$port = isset($port)?$port:null;
		$socket = isset($socket)?$socket:null;
		$charset = isset($charset)?$charset:'utf8';

		$whitelist = isset($whitelist)?$whitelist:false;
		$blacklist = isset($blacklist)?$blacklist:false;

		$db = isset($db)?$db:null;
		$method = isset($method)?$method:$_SERVER['REQUEST_METHOD'];
		$request = isset($request)?$request:isset($_SERVER['PATH_INFO'])?$_SERVER['PATH_INFO']:'';
		$get = isset($get)?$get:$_GET;
		$post = isset($post)?$post:'php://input';

		$request = explode('/', trim($request,'/'));

		if (!$db) {
			$db = $this->connectDatabase($hostname,$username,$password,$database,$port,$socket,$charset);
		}

		$this->config = compact('method', 'request', 'get', 'post', 'database', 'whitelist', 'blacklist', 'db');
	}

	public static function mysql_crud_api_transform(&$tables) {
		$get_objects = function (&$tables,$table_name,$where_index=false,$match_value=false) use (&$get_objects) {
			$objects = array();
			foreach ($tables[$table_name]['records'] as $record) {
				if ($where_index===false || $record[$where_index]==$match_value) {
					$object = array();
					foreach ($tables[$table_name]['columns'] as $index=>$column) {
						$object[$column] = $record[$index];
						foreach ($tables as $relation=>$reltable) {
							if (isset($reltable['relations'])) {
								foreach ($reltable['relations'] as $key=>$target) {
									if ($target == "$table_name.$column") {
										$column_indices = array_flip($reltable['columns']);
										$object[$relation] = $get_objects($tables,$relation,$column_indices[$key],$record[$index]);
									}
								}
							}
						}
					}
					$objects[] = $object;
				}
			}
			return $objects;
		};
		$tree = array();
		foreach ($tables as $name=>$table) {
			if (!isset($table['relations'])) {
				$tree[$name] = $get_objects($tables,$name);
				if (isset($table['results'])) {
					$tree['_results'] = $table['results'];
				}
			}
		}
		return $tree;
	}

	public function executeCommand() {
		$parameters = $this->getParameters($this->config);
		switch($parameters['action']){
			case 'list': $this->listCommandTransform($parameters); break;
			case 'read': $this->readCommand($parameters); break;
			case 'create': $this->readCommand($parameters); break;
			case 'update': $this->readCommand($parameters); break;
			case 'delete': $this->readCommand($parameters); break;
		}
	}

}

// only execute this when running in stand-alone mode
if(count(get_required_files())<2) {
	$api = new MySQL_CRUD_API(array(
		'username'=>'xxx',
		'password'=>'xxx',
		'database'=>'xxx'
	));
	$api->executeCommand();
}