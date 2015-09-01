<?php


/**
 * Main Database Class for Extending
 * 
 * @since	1.0
 */
class Database {
	/** @var resource Main mySQL Resource */
	protected $mysql = false;
	
	/** @var array The schema for the current table */
	protected $schema = false;
	
	/** @var string The currently queried table */
	protected $table = false;
	
	/** @var string The primary key of the table */
	protected $pk = false;
	
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

	/** @var string A regular expression to remove bad table characters. */	
	private $table_preg_filter = '/[^a-z0-9_]/';

	/** @var string A regular expression to remove bad field characters. */	
	private $field_preg_filter = '/[^a-z0-9_\.\*]/';
	
	/**
	 * Construct the Class
	 * 
	 * @since	1.0
	 */
	function __construct($table = false, $arguments = array(), $settings = array()) {
		
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
			throw new Exception('Database connection failed. Missing DSN.');
			return;
		}
		
		if(!$username) {
			throw new Exception('Database connection failed. Missing username.');
			return;
		}
		
		if(!$password) {
			throw new Exception('Database connection failed. Missing password.');
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
		
		if($table) {
			$this->schema($table);
			$this->table = $table;
		}
		
		if($arguments) {
			$this->parseArguments($arguments);
		}
	}
	
	/**
	 * Parse the Arguments into a Usable form
	 * 
	 * @param	array $arguments The array to parse out.
	 * @returns bool|array Array if successful, false if error.
	 * @since	1.0
	 */
	protected function parseArguments($arguments) {
		$fields = '*';
		$data = '';
		$where = '';
		$sort = '';
		
		if(@$arguments['data']) {
			$data = $this->parseData($arguments['data']);
		}
		if(@$arguments['fields']) {
			$fields = $this->parseFields($arguments['fields']);
		}
		if(@$arguments['where']) {
			$where = $this->parseWhere($arguments['where']);
		}
		if(@$arguments['sort']) {
			$sort = $this->parseSort($arguments['sort']);
		}
		
		$this->data = $data;
		$this->fields = $fields;
		$this->where = $where;
		$this->sort = $sort;
	}
	
	protected function parseData($data) {
		if(!$data || !is_array($data)) {
			return  '';
		}
		
		$field_list = '';
		$field_count = 0;
		
		foreach($data as $field => $value) {
			$field = $this->normalizeField($field);
			if($field) {
				if($field_list) {
					$field_list .= ', ';
				}
				
				$field_count = $field_count + 1;
				$field_binding = ':data_' . $field . '_' . $field_count;
				$field_list .= '`' . $field . '` = ' . $field_binding;
				
				$key_schema = $this->findFieldInSchema($field);
				$this->registerBinding(
					$field_binding,
					$value,
					$key_schema['type']
				);
			}
		}
		
		return $field_list;
	}
	
	protected function parseFields($fields) {
		if(!$fields) {
			return  '*';
		}
		
		if(!is_array($fields)) {
			$fields = array($fields);
		}
		
		$field_list = '';
		
		foreach($fields as $field) {
			if($field !== '*') {
				$field = $this->normalizeField($field);
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
	
	protected function parseSort($sorts) {
		
		if(!$sorts) {
			return '';
		}
		
		$sort_string = '';
		
		foreach($sorts as &$sort) {
			
			if($sort['key'] === '%PK') {
				$sort['key'] = $this->pk;
			}
			
			$key = $this->normalizeField($sort['key']);
			
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
	
	protected function parseWhere($wheres = false) {
		
		if(!$wheres) {
			return '';
		}
		
		$where_string = '1 = 1';
		$where_counts = array();
		
		foreach($wheres as &$where) {
			if($where['key'] === '%PK') {
				$where['key'] = $this->pk;
			}
			
			$key = $this->normalizeField($where['key']);
			
			if($key) {
				$where_string_cond = '';
				$key_schema = $this->findFieldInSchema($key);
				
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
						
						$where_counts[$key]++;
						$key_count = $where_counts[$key];
						$where_key = ':where_' . $key . '_' . $key_count;
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
					
					$where_counts[$key]++;
					$key_count = $where_counts[$key];
					$where_key = ':where_' . $key . '_' . $key_count;
					
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
	
	protected function normalizeField($key = false, $verify = true) {
		$key = strtolower(trim($key));
		$key = preg_replace($this->field_preg_filter, '', $key);
		
		if($verify) {
			$verified = $this->findFieldInSchema($key);
			
			if(!$verified) {
				return false;
			}
		}
		
		return $key;
	}
	
	protected function findFieldInSchema($field = false) {
		
		if(!$field) {
			return false;
		}
		
		foreach($this->schema as $schema_key => $schema_value) {
			if($schema_key === $field) {
				return $schema_value;
			}
		}
		
		return false;
	}
	
	public function select() {
		$query = 'SELECT ';
		$query .= $this->fields;
		$query .= ' FROM `' . $this->table . '`';
		
		if($this->where) {
			$query .= ' WHERE ' . $this->where;
		}
		if($this->sort) {
			$query .= ' ORDER BY ' . $this->sort;
		}
		
		return $this->query($query);
	}
	
	public function insert() {
		$query = 'INSERT INTO ';
		$query .= '`' . $this->table . '` SET ';
		$query .= $this->data;
		
		return (
			$this->query($query) !== false ? 
			$this->mysql->lastInsertId() : 
			false
		);
	}
	
	public function insert() {
		$query = 'UPDATE ';
		$query .= '`' . $this->table . '` SET ';
		$query .= $this->data;
		
		if($this->where) {
			$query .= ' WHERE ' . $this->data;
		}
		
		return $this->select();
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
		
		if($query = $this->mysql->prepare($sql)) {
			
			if($vars) {
				$query->execute($vars);
			} else {
				foreach($this->bind as $params) {
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
				
				$query->execute();
			}
			
			if($return_query) {
				return $query;
			}
			
			if($query->errorCode() === '00000') {
				
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
				$this->pk = $data['Field'];
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
		$this->schema = $schema;
		
		return $schema;
	}
}

