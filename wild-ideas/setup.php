<?php

	$jsonStorePath = "../include/json-store.php";
	$classes = array(
		'Idea' => 'json/classes/idea.php'
	);

?>
<style>
	body {
		font-size: 0.9em;
		font-family: Verdana, sans-serif;
		margin: 0;
		padding: 0.7em;
	}
	
	fieldset {
		margin-bottom: 1em;
		border: 1px solid #888;
		border-radius: 5px;
		background-color: #F8F8F8;
	}

	legend {
		font-weight: bold;
		border: 1px solid #888;
		border-radius: 5px;
		background-color: #DDD;
		padding: 0em;
		padding-left: 0.5em;
		padding-right: 0.5em;
	}
	
	.error {
		padding: 0.2em;
		padding-left: 0.5em;
		padding-right: 0.5em;
		border: 1px solid #A88;
		border-radius: 5px;
		background-color: #F8F0F0;
		color: #800;
		font-family: Verdana;
	}
</style>

<?php

if (isset($_POST['setup'])) {
	echo '<pre>';

	foreach ($classes as $className => $filename) {
		$filename = str_replace("\\", "/", realpath(dirname($filename)))."/".basename($filename);
		if (!file_exists($filename)) {
			$filenameParts = explode("/", dirname($filename));
			$thisFileParts = explode("/", str_replace("\\", "/", __FILE__));
			while (count($filenameParts) && count($thisFileParts) && $filenameParts[0] == $thisFileParts[0]) {
				array_shift($filenameParts);
				array_shift($thisFileParts);
			}
			$thisFileParts[count($thisFileParts) - 1] = "";
			$includePath = str_repeat("../", count($filenameParts)) . implode("/", $thisFileParts) . $jsonStorePath;
		
			$result = isset($_POST['create-classes']) && file_put_contents($filename, '<?php
require_once dirname(__FILE__).\'/'.str_replace("'", '\\\'', $includePath) . '\';

class '.$className.' extends JsonStore {
}
JsonStore::addMysqlConfig('.json_encode($className).', array(
	"table" => '.json_encode(strtolower($className)).',
	"keyColumn" => "integer/id",
	"columns" => array(
		"json" => "json",
		"integer/id" => "id"
	)
));
?>');
			if (!$result) {
				echo '<div class="error">File not found: <code>'.htmlentities($filename).'</code></div>';
				continue;
			}
		}
		require_once($filename);

		$config = JsonStore::getMysqlConfig($className);
		
		if (isset($_POST['update-classes'])) {
			$genFilename = str_replace('.php', '.gen.php', $filename);
			$code = file_get_contents($filename);
			if (!strpos($code, ".gen.php")) {
				$code = str_replace("class {$className} ", "require_once dirname(__FILE__).'".str_replace("'", '\\\'', "/".basename($genFilename))."';\n\nclass {$className} ", $code);
			}
			if (isset($config['keyColumn'])) {
				$keyColumnParts = explode('/', $config['keyColumn']);
				array_shift($keyColumnParts);
				$keyColumnCode = '->'.implode('->', $keyColumnParts);
			}
			$code = str_replace("{$className} extends JsonStore", "{$className} extends {$className}_gen", $code);
			file_put_contents($genFilename, '<?php
class '.$className.'_gen extends JsonStore {
	static public function search($schema=NULL, $orderBy=NULL) {
		if (!$schema) {
			$schema = new StdClass;
		}
		if (!$orderBy) {
			$orderBy = array('.(isset($config['keyColumn']) ? json_encode($config['keyColumn']).' => \'ASC\'' : '').');
		}
		$results = JsonStore::schemaSearch(\''.$className.'\', $schema, $orderBy);
		foreach ($results as $idx => $result) {'
		.(isset($config['keyColumn']) ? '
			if ($cached = JsonStore::cached(\''.$className.'\', $result'.$keyColumnCode.')) {
				$results[$idx] = $cached;
				continue;
			}
			$results[$idx] = JsonStore.setCached(\''.$className.'\', $result'.$keyColumnCode.', new '.$className.'($result));'
			: '
			$results[$idx] = new '.$className.'($result);'
		).'
		}
		return $results;
	}'
	.(isset($config['keyColumn']) ? '

	static public function open($id) {
		$model = newStdClass;
		$model'.$keyColumnCode.' = $id;
		$results = self::search(JsonSchema::fromModel($model));
		return count($results) ? $results[0] : NULL;
	}'
	: ''
	).'

	public function put($obj) {'
	.(isset($config['keyColumn']) ? '
		$obj'.$keyColumnCode.' = $this'.$keyColumnCode.';'
	: '')
	.'
		foreach ($obj as $key => $value) {
			$this->$key = $value;
		}
		foreach ($this as $key => $value) {
			if (!isset($obj->$key)) {
				unset($this->$key);
			}
		}
		$this->save();
	}
	
	public function get() {
		return $this;
	}
}
?>') && file_put_contents($filename, $code);
		}
	}
	echo "\nDone.";
	echo '</pre>';
	die();
}
?>
<form action="" method="POST">
	<fieldset>
		<legend>Code generation/updates</legend>

		<label>Create new classes: <input type="checkbox" name="create-classes" checked></input></label>
		<br>
		<label>Update newclasses: <input type="checkbox" name="update-classes" checked></input></label>
	</fieldset>
	
	<!--
	<fieldset>
		<legend>MySQL structure</legend>
		
		<label>Create new tables: <input type="checkbox" name="create-tables" checked></input></label>
		<br>
		<label>Add columns to existing tables: <input type="checkbox" name="update-tables" checked></input></label>
		<br>
		<label>Delete columns from existing tables: <input type="checkbox" name="update-tables" checked></input></label>
	</fieldset>
	-->
	<input type="submit" name="setup" value="GO"></input>
</form>
