# JSON Store

A lightweight library to store JSON using PHP/MySQL.

## Using the library

You'll need to include `include/json-store.php`.

You then just subclass `JsonStore`.  The rules are:

*    The constructor takes an associative array representing the database row
*	The subclass must implement the abstract method `mysqlConfig()`, which returns something like the following:
```
array(
	"table" => {{table name}},
	"columns" => array(...),
	"keyColumn" => "integer/id"
);
```

The "keyColumn" is optional - if it is present (and begins with "integer"), it is updated using the auto-increment value from the table.

You can also return the property `"keyColumns"`, which provides a list of columns that uniquely identify the item (used when updating).

## Table structure

The basic principle is that the values are stored as JSON text in the database.

However, certain columns are made available as separate columns, so they can be indexed.  The column names all begin with the type they store: boolean/integer/number/string for scalar types, or "json" to store raw JSON.

The remaining part of the column name is a JSON Pointer to the part of the document being referenced.  So for instance, if you wanted to index an integer property called "id", then you would use a column called "integer/id".

### Example

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