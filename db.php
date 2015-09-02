<?php


/**
 * Main Database Class for Extending
 * 
 * @since	1.0
 * @todo	Decide about GROUP BY and HAVING.
 * @todo	Decide about what to do if you want to a field with a function, comparison, equation or something that isn't just a field in the schema.
 * @todo	What about "as" like SELECT name as username FROM users?
 */
 
/*

Thinking out loud, a function could be expressed as this:

array(
	'function' => 'DATEDIFF',
	'parameters' => array(
		array(
			'function' => 'NOW'
		),
		'date'
	),
	'as' => 'difference'
)

A comparison could be:

array(
	'comparison' => array(
		'key' => 'date',
		'compare' => '>',
		'value' => array(
			'function' => 'NOW'
		)
	),
	'as' => 'difference'
)


array(
	'function' => 'DATEDIFF',
	'parameters' => array(
		array(
			'function' => 'NOW'
		),
		'date'
	)
)

You could send raw data as:

array(
	'raw' => 'DATEDIFF(NOW(), `date`)',
	'as' => 'difference'
)

Doing "date as published" could be:

array('date', 'published')

Or

array('key' => 'date', 'as' => 'published')

An equation could be:

array(
	'raw' => '1 + 1',
	'as' => 'maths'
)


So, normalizeField would be recursive if there is an array for function 
parameters.  It would check for the presence of "raw" or "function".

The comparison is a little iffy to me right now.  Needs more thought.


*/
 
class Database {
	/** @var resource Main mySQL Resource */
	protected $mysql = false;
	
	/** @var array The schema for the current table */
	protected $schema = false;
	
	/** @var string The currently queried table */
	protected $table = false;
	
	/** @var string The primary key of the table */
	protected $pk = array();
	
	/** @var string The last query created */
	protected $query = '';
	
	/** @var array The bindings for the current query. */
	protected $bind = array();
	
	/** @var string mySQL fields list to query for */
	protected $fields = false;
	
	/** @var string mySQL data for insert / update / replace */
	protected $data = false;
	
	/** @var string mySQL ORDER BY string */
	protected $sort = false;
	
	/** @var string mySQL WHERE string */
	protected $where = false;
	
	/** @var string mySQL LIMIT string */
	protected $limit = '';

	/** @var string A regular expression to remove bad table characters. */	
	private $table_preg_filter = '/[^a-z0-9_]/';

	/** @var string A regular expression to remove bad field characters. */	
	private $field_preg_filter = '/[^a-z0-9_\.\*]/';
	
	/**
	 * Construct the Class
	 * 
	 * @since	1.0
	 */
	function __construct(
		$table = false, 
		$arguments = array(), 
		$settings = array()
	) {
		
		if(!$this->mysql) {		
			$dsn = false;
			$username = false;
			$password = false;
			
			if($settings) {
				if(isset($settings['dsn'])) {
					$dsn = $settings['dsn'];
				} else if(
					isset($settings['host']) && 
					isset($settings['database'])
				) {
					$dsn = 'mysql:host=' . 
							$settings['host'] . 
							';dbname=' . 
							$settings['database'];
				}
				if(isset($settings['username'])) {
					$username = $settings['username'];
				}
				if(isset($settings['password'])) {
					$password = $settings['password'];
				}
			} else {
				if(defined('DB_DSN')) {
					$dsn = DB_DSN;
				}
				if(defined('DB_USERNAME')) {
					$username = DB_USERNAME;
				}
				if(defined('DB_PASSWORD')) {
					$password = DB_PASSWORD;
				}
			}
			
			if(!$dsn) {
				throw new Exception(
					'Database connection failed. Missing DSN.'
				);
				return;
			}
			
			if(!$username) {
				throw new Exception(
					'Database connection failed. Missing username.'
				);
				return;
			}
			
			if(!$password) {
				throw new Exception(
					'Database connection failed. Missing password.'
				);
				return;
			}
			
			// Connect to the database.
			try {
				$this->mysql = new PDO(
					$dsn, 
					$username, 
					$password,
					array(
					    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
					)
				);
				
			// Quit if there is no connection.
			} catch(PDOException $Exception) {
				throw new Exception('Database connection failed.');
				return;
			}
		}
		
		$this->initialize($table, $arguments);
	}
	
	/**
	 * Reset all class variables.
	 * 
	 * @since	1.0
	 */
	protected function reset() {
		$this->schema = false;
		$this->table = false;
		$this->pk = array();
		$this->query = '';
		$this->bind = array();
		$this->fields = false;
		$this->data = false;
		$this->sort = false;
		$this->where = false;
		$this->limit = '';
	}
	
	/**
	 * Initialize class variables.
	 * 
	 * @param string $table The table being queried.
	 * @param array $arguments The query data.
	 * @since	1.0
	 */
	public function initialize($table = false, $arguments = false) {
		$this->reset();
		
		if($table) {
			$table = trim($table);
			$this->schema($table);
			$this->table = $table;
		}
		
		if($table && $arguments) {
			$this->parseArguments($arguments, $table);
		}
	}
	
	/**
	 * Parse the Arguments into a Usable form
	 * 
	 * @param	array $arguments The array to parse out.
	 * @returns bool|array Array if successful, false if error.
	 * @since	1.0
	 */
	protected function parseArguments($arguments = array(), $table = false) {
		$fields = '*';
		$data = '';
		$where = '';
		$sort = '';
		$limit = '';
		
		if(@$arguments['data']) {
			$data = $this->parseData($arguments['data'], $table);
		}
		if(@$arguments['fields']) {
			$fields = $this->parseFields($arguments['fields'], $table);
		}
		if(@$arguments['where']) {
			$where = $this->parseWhere($arguments['where'], $table);
		}
		if(@$arguments['sort']) {
			$sort = $this->parseSort($arguments['sort'], $table);
		}
		if(@$arguments['limit']) {
			$limit = $this->parseLimit($arguments['limit']);
		}
		
		$this->data = $data;
		$this->fields = $fields;
		$this->where = $where;
		$this->sort = $sort;
		$this->limit = $limit;
	}
	
	/**
	 * Parse the Limit Array into SQL
	 * 
	 * The $limit can be:
	 * * 1
	 * * 1,1
	 * * array(1) where key 0 is the limit
	 * * array(1, 1) where key 0 is the offiset and key 1 is the limit
	 * * array('limit' => 1)
	 * * array('limit' => 1, 'offset' => 1)
	 * * array('limit' => 1, 'page' => 1)
	 * 
	 * If you use the associative array with 'page', the offset is calculated
	 * for you based on the 'limit'.
	 * 
	 * @param array $limit The limit array
	 * @returns string
	 * @since	1.0
	 */
	protected function parseLimit($limit) {
		if(isset($limit['limit'])) {	
			if(is_int($limit['limit'])) {
				$limitint = $limit['limit'];
			} else {
				$limitint = (int) trim($limit['limit']);
			}
			
			if(isset($limit['offset'])) {
				if(is_int($limit['offset'])) {
					$offset = $limit['offset'];
				} else {
					$offset = (int) trim($limit['offset']);
				}
			} else if(isset($limit['page'])) {
				if(is_int($limit['page'])) {
					$page = $limit['page'];
				} else {
					$page = (int) trim($limit['page']);
				}
				
				if($page < 1) {
					$page = 1;
				}
				
				$offset = ($page-1) * $limitint;
			} else {
				$offset = 0;
			}
			
			return $limitint . ' OFFSET ' . $offset;
		}
		
		if(!is_array($limit)) {
			if(is_string($limit)) {
				$limit = explode(',', $limit);
				foreach($limit as &$limit_part) {
					$limit_part = trim($limit_part);
				}
			} else if(is_int($limit)) {
				$limit = array($limit);
			}
		}
		
		foreach($limit as &$limit_part) {
			$limit_part = (int) $limit_part;
		}
		
		if(count($limit) === 1) {
			$offset = 0;
			$limitint = $limit[0];
		} else {
			$offset = $limit[0];
			$limitint = $limit[1];
		}
		
		return $limitint . ' OFFSET ' . $offset;
	}
	
	/**
	 * Parse the Data Array into SQL for INSERT / UPDATE
	 * 
	 * @param array $data The data array containing key/value for insert.
	 * @param string $table The table being queried against.
	 * @returns string
	 * @since	1.0
	 */
	protected function parseData($data, $table = false) {
		if(!$data || !is_array($data)) {
			return  '';
		}
		
		$field_list = '';
		
		if(!$table) {
			$table = $this->table;
		}
		
		foreach($data as $field => $value) {
			$field = $this->normalizeField($field, $table);
			if($field) {
				if($field_list) {
					$field_list .= ', ';
				}
				
				$field_binding = ':data_' . $field . '_' . count($this->bind);
				$field_list .= '`' . $field . '` = ' . $field_binding;
				
				$key_schema = $this->findFieldInSchema($field, $table);
				$this->registerBinding(
					$field_binding,
					$value,
					$key_schema['type']
				);
			}
		}
		
		return $field_list;
	}
	
	/**
	 * Parse the Fields
	 * 
	 * @param array $fields The fields array containing query data.
	 * @param string $table The table being queried against.
	 * @returns string
	 * @since	1.0
	 */
	protected function parseFields($fields, $table = false) {
		if(!$fields) {
			return  '*';
		}
		
		if(!is_array($fields)) {
			$fields = array($fields);
		}
		
		$field_list = '';
		
		if(!$table) {
			$table = $this->table;
		}
		
		foreach($fields as $field) {
			if($field !== '*') {
				$field = $this->normalizeField($field, $table);
				if($field) {
					if($field_list) {
						$field_list .= ', ';
					}
					$field_list .= '`' . $field . '`';
				}
			} else {
				$field_list .= '*';
			}
		}
		
		return $field_list;
	}
	
	/**
	 * Parse the ORDER BY
	 * 
	 * @param array $sorts The fields array containing sort data.
	 * @param string $table The table being queried against.
	 * @returns string
	 * @since	1.0
	 */
	protected function parseSort($sorts, $table = false) {
		
		if(!$sorts) {
			return '';
		}
		
		$sort_string = '';
		
		if(!$table) {
			$table = $this->table;
		}
		
		foreach($sorts as &$sort) {
			
			if($sort['key'] === '%PK') {
				$sort['key'] = $this->pk[$table];
			}
			
			$key = $this->normalizeField($sort['key'], $table);
			
			switch(strtolower($sort['order'])) {
				default:
				case 'asc':
					$order = 'ASC';
				break;
				case 'desc':
					$order = 'DESC';
				break;
			}
			
			if($key) {
				if($sort_string) {
					$sort_string .= ', ';
				}
				
				$sort_string .= "`$key` $order";
			}
		}
		
		return $sort_string;
	}
	
	/**
	 * Parse the WHERE into SQL
	 * 
	 * @param array $wheres The WHERE conditionals
	 * @param string $table The table being queried against.
	 * @returns string
	 * @since	1.0
	 */
	protected function parseWhere($wheres = false, $table = false) {
		
		if(!$wheres) {
			return '';
		}
		
		if(!$table) {
			$table = $this->table;
		}
		
		$where_string = '1 = 1';
		
		foreach($wheres as &$where) {
			if($where['key'] === '%PK') {
				$where['key'] = $this->pk[$table];
			}
			
			$key = $this->normalizeField($where['key'], $table);
			
			if($key) {
				$where_string_cond = '';
				$key_schema = $this->findFieldInSchema($key, $table);
				
				if(!isset($where_counts[$key])) {
					$where_counts[$key] = 0;
				}
				
				// Seems sloppy to have two giant chunks of code here to do
				// array vs string.
				// **REFACTOR**
				if(is_array($where['value'])) {
					$value = $where['value'];
					$where_keys = array();
					
					foreach($value as &$single_value) {
						$single_value = $this->castValueAs(
							$single_value, 
							$key_schema['type']
						);
						
						$where_key = ':where_' . $key . '_' . 
							count($this->bind);
						$where_keys[] = $where_key;
						
						$this->registerBinding(
							$where_key, 
							$single_value,
							$key_schema['type']
						);
					}
					
					$where_string_cond = $this->parseComparison(
						array(
							'key' => $key,
							'compare' => $where['compare'],
							'value' => $where_keys
						)
					);
					
				} else {
					$value = $this->castValueAs(
						$where['value'], 
						$key_schema['type']
					);
					
					$where_key = ':where_' . $key . '_' . count($this->bind);
					
					$this->registerBinding(
						$where_key, 
						$value,
						$key_schema['type']
					);
					
					$where_string_cond = $this->parseComparison(
						array(
							'key' => $key,
							'compare' => $where['compare'],
							'value' => $where_key
						)
					);
				}
				
				if($where_string_cond) {
					$where_string .= ' AND ' . $where_string_cond;
				}
			}
		}
		
		return $where_string;
	}
	
	protected function registerBinding($key, $value, $type) {
		switch($type) {
			default:
			case 'string':
				$value = (string) $value;
			break;
			case 'int':
				$value = (int) $value;
			break;
			case 'bool':
				$value = (bool) $value;
			break;
			case 'float':
				$value = (float) $value;
			break;
			case 'date':
				$value = @date('Y-m-d', @strtotime($value));
			break;
			case 'datetime':
				$value = @date('Y-m-d H:i:s', @strtotime($value));
			break;
		}
		
		$this->bind[] = array(
			'key' => $key,
			'value' => $value,
			'type' => $type
		);
	}
	
	protected function parseComparison($conditional = false) {
		if(!$conditional) {
			return '';
		}
		
		if(!$conditional['key']) {
			return '';
		}
		
		if(!$conditional['value']) {
			return '';
		}
		
		$where_str = '';
		$where_str = '`' . $conditional['key'] . '`';
		
		switch(strtolower($conditional['compare'])) {
			default:
			case '=':
				$where_str .= ' = ';
			break;
			case '!=':
			case '<>':
				$where_str .= ' != ';
			break;
			case '~':
			case 'like':
				$where_str .= ' LIKE ';
			break;
			case '!~':
			case '!like':
			case 'not like':
				$where_str .= ' NOT LIKE ';
			break;
			case '>':
				$where_str .= ' > ';
				$compare_type = 'number';
			break;
			case '>=':
				$where_str .= ' >= ';
				$compare_type = 'number';
			break;
			case '<':
				$where_str .= ' < ';
				$compare_type = 'number';
			break;
			case '<=':
				$where_str .= ' <= ';
			break;
			case 'between':
				$where_str .= ' BETWEEN ';
			break;
			case 'not between':
			case '!between':
				$where_str .= ' NOT BETWEEN ';
			break;
			case 'in':
				$where_str .= ' IN';
			break;
			case 'not in':
			case '!in':
				$where_str .= ' NOT IN';
			break;
			case 'coalesce':
				$where_str .= ' COALESCE';
			break;
			case 'isnull':
				$where_str .= ' ISNULL';
			break;
			case 'is null':
				$where_str .= ' IS NULL ';
			break;
			case 'is not null':
			case 'not null':
			case '!null':
				$where_str .= ' IS NOT NULL ';
			break;
			case 'is':
				$where_str .= ' IS ';
			break;
			case 'is not':
				$where_str .= ' IS NOT ';
			break;
		}
		
		// Handle the variable syntaxt of specific types of comparisons.
		switch(strtolower($conditional['compare'])) {
			case 'between':
			case 'not between':
			case '!between':
				$where_str .= $conditional['value'][0];
				$where_str .= ' AND ';
				$where_str .= $conditional['value'][1];
			break;
			case 'in':
			case 'not in':
			case '!in':
			case 'coalesce':
			case 'isnull':
				
				$where_in_str = '';
				$where_str .= '(';
				foreach($conditional['value'] as $where_value) {
					if($where_in_str) {
						$where_in_str .= ', ';
					}
					$where_in_str .= $where_value;
				}
				$where_str .= $where_in_str . ')';
			break;
			case 'is':
			case 'is not':
				if(strtolower($where['value']) === 'unknown') {
					$where_str .= 'UNKNOWN';
				} else if($where['value']) {
					$where_str .= 'TRUE';
				} else {
					$where_str .= 'FALSE';
				}
			break;
			case 'is null':
			case 'is not null':
			case 'not null':
			case '!null':
			break;
			default:
				$where_str .= $conditional['value'];
			break;
		}
		
		return $where_str;
	}
	
	protected function castValueAs($value = '', $type = 'string') {
		if($value === 'NULL') {
			return '%null';
		}
		
		$type = strtolower($type);
		switch($type) {
			default:
			case 'string':
				$value = (string) $value;
			break;
			case 'int':
				$value = (int) $value;
			break;
			case 'float':
				$value = (float) $value;
			break;
			case 'bool':
				$value = (bool) $value;
			break;
			case 'date':
				$value = date('Y-m-d', strtotime($value));
			break;
			case 'datetime':
				$value = date('Y-m-d H:i:s', strtotime($value));
			break;
		}
		
		return $value;
	}
	
	protected function normalizeField($key = false, $table = false, $verify = true) {
		if(is_string($key)) {
			$key = strtolower(trim($key));
			$key = preg_replace($this->field_preg_filter, '', $key);
			
			if(!$table) {
				$table = $this->table;
			}
			
			if($verify) {
				$verified = $this->findFieldInSchema($key, $table);
				
				if(!$verified) {
					return false;
				}
			}
		} else if(is_array($key)) {
			
		}
		
		return $key;
	}
	
	protected function findFieldInSchema($field = false, $table = false) {
		
		if(!$field) {
			return false;
		}
		
		if(!$table) {
			$table = $this->table;
		}
		
		if(!isset($this->schema[$table])) {
			$this->schema($table);
		}
		
		foreach($this->schema[$table] as $schema_key => $schema_value) {
			if($schema_key === $field) {
				return $schema_value;
			}
		}
		
		return false;
	}
	
	public function select(
		$table = false, 
		$fields = false, 
		$where = false,
		$sort = false,
		$limit = false
	) {
		
		if(!$table) {
			$table = $this->table;
		} else {
			$table = trim($table);
			$this->schema($table);
		}
		
		if(!$fields) {
			$fields = $this->fields;
		} else {
			$data = $this->parseFields($fields, $table);
		}
		
		if(!$where) {
			$where = $this->where;
		} else {
			$where = $this->parseWhere($where, $table);
		}
		
		if(!$sort) {
			$sort = $this->sort;
		} else {
			$sort = $this->parseSort($sort, $table);
		}
		
		if(!$limit) {
			$limit = $this->limit;
		} else {
			$limit = $this->parseLimit($limit);
		}
		
		$query = 'SELECT ';
		$query .= $fields;
		$query .= ' FROM `' . $table . '`';
		
		if($where) {
			$query .= ' WHERE ' . $where;
		}
		
		if($sort) {
			$query .= ' ORDER BY ' . $sort;
		}
		
		if($limit) {
			$query .= ' LIMIT ' . $limit;
		}
		
		return $this->query($query);
	}
	
	public function insert($table = false, $data = false) {
		
		if(!$table) {
			$table = $this->table;
		} else {
			$table = trim($table);
			$this->schema($table);
		}
		
		if(!$data) {
			$data = $this->data;
		} else {
			$data = $this->parseData($data, $table);
		}
		
		$query = 'INSERT INTO ';
		$query .= '`' . $table. '` SET ';
		$query .= $data;
		
		if($this->query($query) !== false) {
			return $this->query(
				'SELECT * FROM `' . $table . '` WHERE ' . 
				$this->pk[$table] . ' = ' . $this->mysql->lastInsertId(),
				false
			);
		} else {
			return false;
		}
	}
	
	public function update($table = false, $data = false, $where = false) {
		
		if(!$table) {
			$table = $this->table;
		} else {
			$table = trim($table);
			$this->schema($table);
		}
		
		if(!$data) {
			$data = $this->data;
		} else {
			$data = $this->parseData($data, $table);
		}
		
		if(!$where) {
			$where_orig = false;
			$where = $this->where;
		} else {
			$where_orig = $where;
			$where = $this->parseWhere($where, $table);
		}
		
		$query = 'UPDATE ';
		$query .= '`' . $table. '` SET ';
		$query .= $data;
		
		if($where) {
			$query .= ' WHERE ' . $where;
		}
		
		$this->query($query);
		
		return $this->select($table, '*', $where_orig);
	}
	
	/**
	 * Execute a Query
	 * 
	 * @param	string $sql The query
	 * @param	array $vars Items to replace in the query
	 * @param	bool $return_query Whether to return the PDO::Query or an array.
	 * @returns bool|array Array if successful, false if error.
	 * @since	1.0
	 */
	public function query(
		$sql = '',
		$vars = array(),
		$return_query = false
	) {
		
		$sql = (string) $sql;
		
		if($sql === '') {
			return false;
		}
		
		var_dump($sql, $this->bind);
		
		if($query = $this->mysql->prepare($sql)) {
			
			if($vars === false || $vars) {
				if($vars) {
					$query->execute($vars);
				} else {
					$query->execute();
				}
			} else {
				foreach($this->bind as $params) {
					if(stristr($sql, $params['key']) !== false) {
						switch($params['type']) {
							default:
							case 'string':
								$paramconst = PDO::PARAM_STR;
							break;
							case 'bool':
								$paramconst = PDO::PARAM_BOOL;
							break;
							case 'int':
								$paramconst = PDO::PARAM_INT;
							break;
						}
						
						$query->bindParam($params['key'], $params['value'], $paramconst);
					}
				}
				
				$query->execute();
			}
			
			if($return_query) {
				return $query;
			}
			
			if($query->errorCode() === '00000') {
				
				// If ever you want to convert to specific variable types
				// this is where you would do it...
				$results = array();
				while($row = $query->fetch(PDO::FETCH_ASSOC)) {
					$results[] = $row;
				}
				
				return $results;
			} else {
				return false;
			}
		}
	}
	
	/**
	 * Populate Schema
	 * 
	 * @param	string $table The table to collect schema data on.
	 * @returns bool|array Array if successful, false if error.
	 * @since	1.0
	 */
	protected function schema($table = false) {
		if($table === false) {
			return false;
		}
		
		// Normalize the table name to prevent injection.
		$table = preg_replace($this->table_preg_filter, '', strtolower($table));
		
		// Execute a describe statement.
		$schema_data = $this->query('DESCRIBE ' . $table);
		
		// Initialize the array.
		$schema = array();
		
		// Loop the schema data.
		foreach($schema_data as $data) {
			
			// Explode the data on characters that will explain the parts.
			$data_type_parts = preg_split('/[() ]/', $data['Type']);
			
			// This is the primary data type.
			$data_type = $data_type_parts[0];
			
			// If this is set, it's the string length or format of the number.
			if(isset($data_type_parts[1])) {
				$data_length = $data_type_parts[1];
			} else {
				$data_length = false;
			}
			
			// If this is set, it's whether the number is signed.
			if(
				!isset($data_type_parts[3]) ||
				$data_type_parts[3] !== 'unsigned'
			) {
				$data_signed = true;
			} else {
				$data_signed = false;
			}
			
			// Whether null and PK.
			$data_allow_null = ($data['Null'] === 'YES');
			$data_is_pk = ($data['Key'] === 'PRI');
			
			if($data_is_pk) {
				$this->pk[$table] = $data['Field'];
			}
			
			// Default value.
			switch($data['Default']) {
				case 'NULL':
					$data_default = null;
				break;
				case 'CURRENT_TIMESTAMP':
					if($data['Extra'] === 'on update CURRENT_TIMESTAMP') {
						$data_default = null;
					} else {
						$data_default = date('Y-m-d H:i:s');
					}
				break;
				default:
					$data_default = $data['Default'];
				break;
			}
			
			// Normalize the mySQL type to a PHP type.
			switch($data_type) {
				case 'float':
				case 'decimal':
				case 'double':
					$data_type = 'float';
				break;
				case 'int':
				case 'smallint':
				case 'mediumint':
				case 'bigint':
					$data_type = 'int';
				break;
				case 'tinyint':
					$data_type = 'bool';
				break;
				case 'tinytext':
					$data_length = '255';
				case 'mediumtext':
				case 'longtext':
				case 'text':
				case 'char':
				case 'varchar':
					$data_type = 'string';				
				break;
				case 'date':
					$data_type = 'date';
				break;
				case 'datetime':
				case 'timestamp':
					$data_type = 'datetime';
				break;
			}
			
			// Set the final array for the field.
			$schema[$data['Field']] = array(
				'type' => $data_type,
				'length' => $data_length,
				'signed' => $data_signed,
				'nullable' => $data_allow_null,
				'pk' => $data_is_pk,
				'default' => $data_default
			);
		}
		
		// Set the schema.
		$this->schema[$table] = $schema;
		
		return $schema;
	}
}
