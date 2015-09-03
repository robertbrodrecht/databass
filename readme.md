DataBass
========

Pronounced like the fish.

DataBass is a PDO wrapper that aims to emulate some of WordPress's WPDB and WP_Query patterns to make it easy to do simple database queries through arrays.

## Getting Started

There are two ways to start.  Method one is to define three constants:

```
define('DB_DSN', 'mysql:host=localhost;dbname=your_database');
define('DB_USERNAME', 'yourusername');
define('DB_PASSWORD', 'yourpassword');

$db = new Database($table_name, $arguments);
```

Method two is to pass a settings array:

```
$settings = array(
	'dsn' => 'mysql:host=localhost;dbname=your_database',
	'username' => 'yourusername',
	'password' => 'yourpassword'
);

$db = new Database($table_name, $arguments, $settings);
```

If you don't want to type the entire DSN, you can optionally do:

```
$settings = array(
	'host' => 'localhost',
	'database' => 'your_database',
	'username' => 'yourusername',
	'password' => 'yourpassword'
);

$db = new Database($table_name, $arguments, $settings);
```

## Instantiate with Arguments

When the class is instantiated with a table name and arguments, a representation of the schema is created for converting variables to specific types and passing to PDO's bindParam, then the arguments array is parsed to get ready to execute a query.

The table name is a string.  The arguments array can look something like this:

```
$arguments = array(
	'fields' => array(
		'id',
		'name',
		'date'
	),
	'data' => array(
		'name' = 'New Name',
		'date' => date('Y-m-d')
	),
	'where' => array(
		array(
			'key' => 'id',
			'compare' => '>',
			'value' => 5
		),
		array(
			'key' => 'id',
			'compare' => '<',
			'value' => 10
		)
	),
	'sort' => array(
		array(
			'key' => 'name',
			'order' => 'DESC'
		),
		array(
			'key' => 'id',
			'order' => 'ASC'
		),
	),
	'limit' => array(
		'limit' => 5,
		'offset' => 5
	)
);
```

Each of those keys in the arguments have some specifics that are discussed below.

### Database Fields and Advanced Fields

For example, the fields key in simplest form is a series of field names.  If a field is listed as a string, it is evaluated to make sure it is part of the table's schema.  However, it can get more complex by using advanced field types.  In most cases, advanced fields can be used anywhere a normal field could be used.  There are a few advanced fields.

The least confusing is if you need to use an "as" with your field.  In that case, you would do:

```
array('key' => 'id', 'as' => 'myid')  // Converts to: `id`
```

You can also send raw, unprocessed strings:

```
array('raw' => '`id` * `id`', 'as' => 'idsq') // Converts to: `id` * `id` as idsq
```

You can add comparisons as well:

```
array(
	'comparison' => array(
		'key' => 'id', 
		'compare' => '>', 
		'value' => '3'
	), 
	'as' => 'newerid'
) 
// converts to `id` > 3 as newerid
```

Finally, you can add functions:

```
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
// Converts to DATEDIFF(NOW(), `date`) as difference
```

As you may have suspected, after seeing the function example, (almost) anywhere you can add a field or value, you can also add an advanced field.  So, you can nest comparisons or functions within comparisons or functions.  The exception is in the 'data' key (see above).  The 'key' key must be a field name.

### Variations on Arguments

A few of the arguments have variations apart from the ability to take advanced fields.

Fields has a special case for getting all columns:

```
'fields' => array('*')
// Convers to SELECT * FROM...
```

Limit can use 'limit' and 'offset':

```
'limit' => array(
	'limit' => 5,
	'offset' => 5
)
// Convers to LIMIT 5 OFFSET 5
```

Or Limit can use 'limit' and 'page' to automatically calculate the offest:


```
'limit' => array(
	'limit' => 5,
	'page' => 1
)
// Convers to LIMIT 5 OFFSET 5
```

Limit does not require 'offset' or 'page':

```
'limit' => array(
	'limit' => 5
)
// Convers to LIMIT 5 OFFSET 0
```

And limit can also be a string such as:

```
'limit' => 5
// Convers to LIMIT 5 OFFSET 0
```

or

```
'limit' => '10,5'
// Convers to LIMIT 5 OFFSET 10
```

Sort does not require an 'order' as it will be set to 'ASC' by default.  

```
'sort' => array(
	array(
		'key' => 'name',
	)
)
// Converts to ORDER BY `name` ASC
```

Conditionals do not require a 'comparison' as '=' is used by default:

```
'where' => array(
	array(
		'key' => 'id',
		'value' => 5
	)
)
// Converts to WHERE `id` = 5
```

### How Arguments Are Used

Some arguments are ignored in certain instances.  So, if you are doing a certain type of query it might not be fruitful to include all the arguments.

SELECT uses: fields, sort, where, and limit.

INSERT uses: data.

UPDATE uses: data, and where.

DELETE uses: where.

## After Initialization

After you initialize the class, you can start executing queries.

```
$db->select(); // Executes a SELECT and returns all rows.
$db->insert(); // Executes an INSERT and returns the new row.
$db->update(); // Executes an UPDATE and returns the updated row (unless the update causes the WHERE to no longer match).
$db->delete(); // Executes a DELETE and returns the rows that were deleted.
```

## Multiple Queries

So, you wanted to run more than one query?  From here on, you call the individual methods with specific parameters.  While our <code>$arguments</code> above has everything in one array delineated by key, for a direct query, you break them out into individual variables.

Select takes:

```
$db->select(
	$table_name,
	$fields,
	$where,
	$sort,
	$limit
);
```

Insert takes:

```
$db->insert(
	$table_name,
	$data
);
```

Update takes:

```
$db->update(
	$table_name,
	$data,
	$where
);
```

Delete takes:

```
$db->delete(
	$table_name,
	$where
);
```

## Raw Queries

If you want to run a complex query with JOINs or you just want more explicit control over your query, you can run a normal string-query:

```
$db->query(
	'SELECT * FROM table WHERE id > :myid',
	array(
		':myid' => 123
	),
	$return_query
);
```

The first parameter is the SQL query.  The second parameter is a series of bindings that gets passed to PDOStatement::execute.  The final parameter determines whether to return an array of results or the PDOStatement object.  This gives you the flexibility to query large amounts of data or just quickly get a list of columns for a smaller query.

What you don't get is PDOStatement::bindParam with a data type.  If you use the simple query methods, that happens for you based on the table schema data.  PDOStatement::execute treats everything as a string.  If that's a problem, you can still use Database::mysql to create a PDOStatement, then do whatever you want with it.