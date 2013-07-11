# JSON Store

Storing JSON data with PHP/MySQL, as simply as possible.

The idea is that the database tables can look completely normal - in fact, in many cases you can write configs for your existing table structure.

Once you have supplied a config, then loading/saving/creation/deletion are all handled automatically.  Searches can be performed using [JSON Schema](http://json-schema.org/) as a query language.

It is **not** intended to be a full-featured ORM.  It is simply a data-store, and intends to remain lightweight - however, an ORM could be built on top of it if needed.

### Example usage:

```php
class MyClass extends JsonStore {
	static public function open($id) {
		$schema = new JsonSchema;
		$schema->properties->id->enum = array((int)$id);
		$results = self::search($schema);
		if (count($results) == 1) {
			return $results[0];
		}
	}
	static public function search($schema) {
		$results = JsonStore::schemaSearch('MyClass', $schema, array("integer/id" => "ASC"));
		foreach ($results as $index => $item) {
			$results[$index] = new MyClass($item);
		}
		return $results;
	}
	static public function create($startingData) {
		$startingData = (object)$startingData;
		unset($startingData->id); // For safety
		if (!isset($startingData->source)) {
			$startingData->source = new StdClass;
		} else {
			$startingData->source = (object)$startingData->source;
		}
		return new MyClass($startingData); // constructor is protected
	}
}
JsonStore::addMysqlConfig('MyClass', array(
	"table" => "my_table_name",
	"keyColumn" => "integer/id",
	"columns" => array(
		"integer/id" => "id",
		"string/title" => "title",
		"string/source/url" => "source_url",
		"boolean/source/verified" => "source_verified"
	)
));

$myObj = MyClass::create(array(
	"title" => "Hello, world!",
	"source" => array(
		"url" => "http://example.com/",
		"verified" => FALSE
	)
));
isset($myObj->id); // FALSE
$myObj->title; // "Hello, world!";

$myObj->save(); // performs an INSERT
$myObj->id; // taken from the auto-increment in the database

$myObj->source->verified = TRUE;
$myObj->save(); // performs an UPDATE
```

## Using the library

You'll need to include `include/json-store.php`.

You then just subclass `JsonStore`.  The rules are:

*	The constructor takes either:
**	an associative array representing the database row (as returned by `$mysqli->fetch_assoc()` or `JsonStore::mysqlQuery()`)
**	a plain object
*	You then make a call to `JsonStore::addMysqlConfig`, looking something like this:

```php
JsonStore::addMysqlConfig('MyCustomClass', array(
	"table" => {{table name}},
	"keyColumn" => "integer/id",
	"columns" => array(...)
);
```

It's a good idea to have either `"keyColumn"` (single value) or `"keyColumns"` (array representing a composite key).

If `"keyColumn"` is present (and begins with "integer"), it is updated using the auto-increment value from the table.

## Table structure and column names

Under the hood, JsonStore assumes that column names follow a particular pattern: `{type}{pointer}`.

The `type` part of the column name denotes the JSON type to be stored there - one of "json" (raw JSON text), "integer", "number", "string" or "array".

The remaining part of the column name is a JSON Pointer representing where that data maps to in the JSON object.  For example, the column name `integer/id`

### Simple Example

Say we are storing this data:

```json
{
	"id": 1,
	"title": "Hello, World!",
	"someOtherProperty": [1, 2, 3]
}
```

And say our columns are:

*	`json`
*	`integer/id`
*	`string/title`

Then the table entry will look something like this:

```
----------------------------------------------
|   json    |  integer/id  |  string/title   |
----------------------------------------------
| '{ ... }' |      1       | 'Hello, World!' |
----------------------------------------------
```

### Aliasing column names

Because those column names are a bit messy, they can be aliased.  To do this, simply use the JsonStore representation (e.g. "string/owner/name") as the key, and the actual column name as the value:
```php
JsonStore::addMysqlConfig('MyCustomClass', array(
	"table" => 'MyTableName',
	"keyColumn" => "integer/id",
	"columns" => array(
		"integer/id" => "id",
		"string/owner/id" => "owner_id",
		"string/owner/name" => "owner_name"
	)
);
```

This aliasing can also be specified using `"alias"` - the two are largely equivalent:
```php
JsonStore::addMysqlConfig('MyCustomClass', array(
	"table" => 'MyTableName',
	"keyColumn" => "integer/id",
	"columns" => array("integer/id", ...),
	"alias" => array(
		"integer/id" => "id",
		...
	)
);
```

## Circular references

Don't use them.  In fact, don't even reference JsonStore objects from each other.

A better pattern is in fact to store an identifier in the object, and then define a method like this:

```php
class MyClass extends JsonStore {
	public function open($id) {
		$sql = "SELECT * FROM my_table WHERE `integer/id`=".(int)$id;
		$rows = self::mysqlQuery($sql);
		if (count($rows)) {
			return new MyClass($rows[0]);  // if you pass in an array, it inflates it into a full object
		}
	}

	public function parent() {
		return MyClass::open($this->parentId);
	}
}
```

## Arrays

If an entry in `"columns"` is an array (i.e. the column name begins `array/...`), then the corresponding value should be itself be a config.  The format is similar to above, with the following differences:

* `"keyColumn"`/`"keyColumns"` will be ignored
* If the optional parameter `"parentColumn"` is present, then this column (actual/aliased name, not the JsonStore internal one) is used to match against the array table.
* There are two implied columns: `"group"` and `"index"`.  These can be renamed just like any other column.
    * If `"parentColumn"` is specified, `"group"` must be of the same type.  Otherwise, `"group"` must be an auto-incrementing integer, as must the `"array/..."` column in the original table.
    * `"index"` must be an integer type

### Basic config example

Here is a basic example using an array from a separate table:
```php
JsonStore::addMysqlConfig('MyCustomClass', array(
	"table" => 'my_table',
	"keyColumn" => "integer/id",
	"columns" => array(
		"integer/id",
		"string/title",
		"array/integerList" => array(
			"table" => 'my_table_integer_list',
			"columns" => array(
				"integer"
			)
		)
	),
);
```

That example assumes the following columns for `my_table`:

* `integer/id` - auto-incrementing integer
* `string/title` - some string type
* `array/integerList` - an integer

and the following columns for `my_table_integer_list`:

* `group` - auto-incrementing integer - will match up with the values in `array/integerList`
* `index` - integer
* `integer` - integer (represents the actual value at that index for that array)

### Complex array example

Say we are storing this data:

```json
{
	"id": 5,
	"title": "Hello!",
	"myArray": [
		1,
		2,
		{"id": 3, "name": "three"}
	]
}
```

And say our config looks like this:

```php
JsonStore::addMysqlConfig('MyCustomClass', array(
	"table" => "my_table",
	"keyColumn" => "integer/id",
	"columns" => array(
		"integer/id" => "id",
		"string/title" => "title",
		"array/myArray" => array(
			"table" => "my_array_items_table",
			"parentKey" => "id",
			"columns" => array(
				"group" => "parent_id",
				"index" => "pos",
				"integer" => "int_value",
				"integer/id" => "obj_id",
				"string/name" => "obj_name"
			)
		)
	)
);
```

Then our main table (`my_table`) will look something like this:

```
--------------------------------
|      id      |     title     |
--------------------------------
|       5      |    "Hello!"   |
--------------------------------
```

And our array table (`my_array_items_table`) will look something like this:

```
-------------------------------------------------------------
|  parent_id  |  pos  |  int_value  |  obj_id  |  obj_name  |
-------------------------------------------------------------
|      5      |   0   |      1      |   NULL   |    NULL    |
-------------------------------------------------------------
|      5      |   1   |      2      |   NULL   |    NULL    |
-------------------------------------------------------------
|      5      |   2   |     NULL    |    3     |    three   |
-------------------------------------------------------------
```

Note that because we specified `"parentKey"` in the config, `my_table` doesn't need a separate column for the array ID.  However, this means that there will *always* be an array in `$data->myArray`, it will simply be empty if there are no entries in `my_array_items_table`.

## Searching with JSON Schema

JsonStore provides a helper class: JsonSchema.

This class doesn't really do anything - it just makes it easier to assemble JSON Schemas by creating properties as they are needed.  It also contains some shortcuts - for example, if you specify "properties", but haven't specified "type", then "type" will default to "object".

```php
$schema = new JsonSchema();
$schema->properties->id = enum(5);  // $schema->type has now defaulted to "object"
```

You will need to write your own static method for each of your classes, for example:

```php
class MyClass extends JsonStore {
	public static function openAll($schema=NULL, $orderBy=NULL) {
		if (!$schema) {
			$schema = new JsonSchema(); // empty query
		}
		if (!$orderBy) {
			$orderBy = array("integer/id" => "ASC");
		}
		$results = self::schemaSearch('MyClass', $schema, $orderBy);
		foreach ($results as $index => $item) {
			$results[$index] = new MyClass($item); // JsonStore::schemaSearch gives us an array of objects back
		}
		return $results;
	}
}
```

`JsonStore::schemaSearch()` will convert the schema into an SQL query representing the same constraints.

If there are any constraints that cannot be translated into the SQL query (because the database structure doesn't support them, or JsonStore doesn't recognise the keywords), then the results are filtered using the `jsv4-php` validation library before being returned.

## Caching loaded values

Cacheing is not done automatically, however some convenience functions are provided.

```php
class MyClass extends JsonStore {
	static public function open($id) {
		if ($cached = JsonStore::cached('MyClass', $id)) {
			return $cached;
		}
		$schema = new JsonSchema;
		$schema->properties->id->enum = array((int)$id);
		$results = self::search($schema);
		if (count($results) == 1) {
			return JsonStore::setCached('MyClass', $id, $results[0]);
		}
	}
	...
}
```

Similar things would obviously need to be done for search results as well.

## Precedence when loading data

When loading data, values from more specific columns (longer paths) always take precedence.

So for instance, given the following table:
```
-----------------------------------
|    json/key     | boolean/key/b |
-----------------------------------
| '{"a":1,"b":2}' |       1       |
-----------------------------------
```

The data we will load will look like:
```json
{
	"key": {
		"a": 1,
		"b": true
	}
}
```