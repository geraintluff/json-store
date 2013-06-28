<?php

	// Very naive patch
	function json_diff_inner($a, $b) {
		$patch = array();
		if (is_object($a)) {
			if (!is_object($b)) {
				$patch[] = (object)array(
					"op" => "replace",
					"path" => "",
					"value" => $b,
					"oldValue" => $a
				);
				return $patch;
			}
			$totalKeys = 0;
			$addedKeys = 0;
			$deletedKeys = 0;
			$changedKeys = 0;
			$subChanges = array();
			foreach ($b as $key => $value) {
				$totalKeys++;
				$path = "/".str_replace("/", "~1", str_replace("~", "~0", $key));
				if (!property_exists($a, $key)) {
					$addedKeys++;
					$subChanges[] = (object)array(
						"op" => "add",
						"path" => $path,
						"value" => $value
					);
				} else {
					$subPatch = json_diff_inner($a->$key, $value);
					if (count($subPatch)) {
						$changedKeys++;
					}
					foreach ($subPatch as $change) {
						$change->path = $path.$change->path;
						$subChanges[] = $change;
					}
				}
			}
			foreach ($a as $key => $value) {
				$totalKeys++;
				$path = "/".str_replace("/", "~1", str_replace("~", "~0", $key));
				if (!property_exists($b, $key)) {
					$deletedKeys++;
					$subChanges[] = (object)array(
						"op" => "remove",
						"path" => $path,
						"oldValue" => $value
					);
				}
			}
			if ($addedKeys + $deletedKeys + $changedKeys > $totalKeys*0.5) {
				$patch[] = (object)array(
					"op" => "replace",
					"path" => "",
					"value" => $b,
					"oldValue" => $a
				);
			} else {
				$patch = array_merge($patch, $subChanges);
			}
		} elseif (is_array($a)) {
			if (!is_array($b)) {
				$patch[] = (object)array(
					"op" => "replace",
					"path" => "",
					"value" => $b,
					"oldValue" => $a
				);
				return $patch;
			}
			$arrayChanges = array();
			$indexA = 0;
			$copyA = $a;
			while (TRUE) {
				$matchA = $matchB = NULL;
				$distanceA = $distanceB = 0;
				while ((isset($distanceA) || isset($distanceB)) && !isset($matchA)) {
					if (isset($distanceA)) {
						if ($indexA + $distanceA < count($copyA)) {
							$matchIndex = $indexA;
							while ($matchIndex <= $indexA + $distanceA) {
								if ($b[$matchIndex] == $copyA[$indexA + $distanceA]) {
									$matchA = $indexA + $distanceA;
									$matchB = $matchIndex;
									break;
								}
								$matchIndex++;
							}
							$distanceA++;
						} else {
							$distanceA = NULL;
						}
					}
					if (isset($distanceB)) {
						if ($indexA + $distanceB < count($b)) {
							$matchIndex = $indexA;
							while ($matchIndex <= $indexA + $distanceB) {
								if ($copyA[$matchIndex] == $b[$indexA + $distanceB]) {
									$matchA = $matchIndex;
									$matchB = $indexA + $distanceB;
									break;
								}
								$matchIndex++;
							}
							$distanceB++;
						} else {
							$distanceB = NULL;
						}
					}
				}
				if (!isset($matchA)) {
					$matchA = count($copyA);
					$matchB = count($b);
				}
				for ($index = $indexA; $index < $matchA && $index < $matchB; $index++) {
					$arrayChanges[] = (object)array(
						"op" => "replace",
						"path" => "/$index",
						"value" => $b[$index],
						"oldValue" => $a[$index]
					);
				}
				for ($index = $matchA; $index < $matchB; $index++) {
					$arrayChanges[] = (object)array(
						"op" => "add",
						"path" => "/$index",
						"value" => $b[$index]
					);
				}
				for ($index = $matchB; $index < $matchA; $index++) {
					$arrayChanges[] = (object)array(
						"op" => "remove",
						"path" => "/$index",
						"oldValue" => $copyA[$index]
					);
				}
				if ($matchB > 0) {
					$copyA = array_merge(array_fill(0, $matchB, NULL), array_slice($copyA, $matchA));
				} else {
					$copyA = array_slice($copyA, $matchA);
				}
				$indexA = $matchB + 1;
				if ($matchA >= count($copyA) && $matchB >= count($b)) {
					break;
				}
			}
			if (count($arrayChanges) > (count($a) + count($b))/4) {
				$patch[] = (object)array(
					"op" => "replace",
					"path" => "",
					"value" => $b,
					"oldValue" => $a
				);
			} else {
				foreach ($arrayChanges as $change) {
					if ($change->op == "replace") {
						$subPatch = json_diff_inner($change->oldValue, $change->value);
						foreach ($subPatch as $subChange) {
							$subChange->path = $change->path.$subChange->path;
							$patch[] = $subChange;
						}
					} else {
						$patch[] = $change;
					}
				}
			}
		} else {
			if ($a != $b) {
				$patch[] = (object)array(
					"op" => "replace",
					"path" => "",
					"value" => $b,
					"oldValue" => $a
				);
			}
		}
		return $patch;
	}
	
	function json_diff($a, $b) {
		$patch = json_diff_inner($a, $b);
		// TODO: cleanup, matching moves from replace/add/removes
		return $patch;
	}

	/*
	$testA = (object)array(
		"arr" => array("Yo", "Hello"),
		"test" => "here",
		"same" => "same",
		"same2" => "same"
	);
	$testB = (object)array(
		"arr" => array("Yo", "Hello", "Hi"),
		"test2" => "foo",
		"same" => "same",
		"same2" => "same"
	);
	header("Content-Type: application/json");
	die(json_encode(json_diff($testA, $testB)));
	*/
?>