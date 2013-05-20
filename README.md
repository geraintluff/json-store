# JSON Store

Storing JSON data with PHP/MySQL, as simply as possible.

The idea is that the database holds JSON data, placing no constraints on the shape of the data.  However, you can create additional columns for particular parts of the data, if they exist.

## Using the library

You'll need to include `include/json-store.php`.

You then just subclass `JsonStore`.  The rules are:

*	The constructor takes an associative array representing the database row (as returned by `$mysqli->fetch_assoc()` or `JsonStore::mysqlQuery()`)
*	You then make a call to `JsonStore::addMysqlConfig`, looking something like this:

```php
JsonStore::addMysqlConfig('MyCustomClass', array(
	"table" => {{table name}},
	"columns" => array(...),
	"keyColumn" => "integer/id"
);
```

You have to either at least one of`"keyColumn"` (single value) or `"keyColumns"` (array).

If `"keyColumn"` is present (and begins with "integer"), it is updated using the auto-increment value from the table.

The entries in columns can be numerically indexed, or they can be a map where the column name is the key.

If an entry in `"columns"` is an array (i.e. the column name begins `array/...`), then the corresponding value should be an array of exactly the same shape as above (although `"keyColumn"`/`"keyColumns"` are not needed).

### Circular references

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

## Table structure

The basic principle is that the values are stored as JSON text in the database.

However, certain columns are made available as separate columns, so they can be indexed.  The column names all begin with the type they store: boolean/integer/number/string for scalar types, "array" to reference a second table for array values, or "json" to store raw JSON.

The remaining part of the column name is a JSON Pointer to the part of the document being referenced.  So for instance, if you wanted to index an integer property called "id", then you would use a column called "integer/id".

Tables for arrays must, in addition, always contain two columns named `group` and `index`.  These must both be integers, and `group` must auto-increment.

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

### Loading data

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

### Array Example

Say we are storing this data:

```json
{
	"id": 5,
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
		"integer/id",
		"array/myArray" => array(
			"table" => "my_array_items_table",
			"columns" => array(
				"integer",
				"integer/id",
				"string/name"
			)
		)
	)
);
```

Then our main table (`my_table`) will look something like this:

```
----------------------------------
|  integer/id  |  array/myArray  |
----------------------------------
|       5      |       67        |
----------------------------------
```

And our array table (`my_array_items_table`) will look something like this:

```
----------------------------------------------------------------
|  group  |  index  |  integer  |  integer/id  |  string/name  |
----------------------------------------------------------------
|    67   |    0    |     1     |     NULL     |     NULL      |
----------------------------------------------------------------
|    67   |    1    |     2     |     NULL     |     NULL      |
----------------------------------------------------------------
|    67   |    2    |    NULL   |      3       |     three     |
----------------------------------------------------------------
```
