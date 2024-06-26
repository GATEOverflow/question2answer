<?php
/*
	Question2Answer by Gideon Greenspan and contributors
	http://www.question2answer.org/

	File: qa-include/util/sort.php
	Description: A useful general-purpose 'sort by' function


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	More about this license: http://www.question2answer.org/license.php
*/

if (!defined('QA_VERSION')) { // don't allow this page to be requested directly from browser
	header('Location: ../../');
	exit;
}

/**
 * Sort the $array of inner arrays by sub-element $by1 of each inner array, and optionally then by sub-element $by2
 * @param array $array
 * @param string $by1
 * @param string|null $by2
 */
function qa_sort_by(&$array, $by1, $by2 = null)
{
	global $qa_sort_by_1, $qa_sort_by_2;

	$qa_sort_by_1 = $by1;
	$qa_sort_by_2 = $by2;

	uasort($array, 'qa_sort_by_fn');
}


/**
 * Function used in uasort to implement qa_sort_by()
 * @param array $a
 * @param array $b
 * @return int
 */
function qa_sort_by_fn($a, $b)
{
	global $qa_sort_by_1, $qa_sort_by_2;

	// if the first keys are equal we can sort by the second keys only
	$sortkey = $qa_sort_by_1;
	if ($a[$sortkey] == $b[$sortkey]) {
		if (!isset($qa_sort_by_2))
			return 0;

		$sortkey = $qa_sort_by_2;
	}

	$av = $a[$sortkey];
	$bv = $b[$sortkey];

	if (is_numeric($av) && is_numeric($bv)) // straight subtraction won't work for floating bits
		return $av == $bv ? 0 : ($av < $bv ? -1 : 1);
	else
		return strcasecmp((string)$av, (string)$bv); // doesn't do UTF-8 right but it will do for now
}

/**
 * General comparison function for two values, textual or numeric
 * @deprecated
 * @param int|float|string $a
 * @param int|float|string $b
 * @return int
 */
function qa_sort_cmp($a, $b)
{
	if (is_numeric($a) && is_numeric($b)) // straight subtraction won't work for floating bits
		return $a == $b ? 0 : ($a < $b ? -1 : 1);
	else
		return strcasecmp((string)$a, (string)$b); // doesn't do UTF-8 right but it will do for now
}


/**
 * Inserts $addelements into $array, preserving their keys, before $beforekey in that array.
 * If $beforekey cannot be found, the elements are appended at the end of the array.
 * @param array $array
 * @param string $beforekey
 * @param array $addelements
 */
function qa_array_insert(&$array, $beforekey, $addelements)
{
	$newarray = array();
	$beforefound = false;

	foreach ($array as $key => $element) {
		if ($key == $beforekey) {
			$beforefound = true;

			foreach ($addelements as $addkey => $addelement)
				$newarray[$addkey] = $addelement;
		}

		$newarray[$key] = $element;
	}

	if (!$beforefound) {
		foreach ($addelements as $addkey => $addelement)
			$newarray[$addkey] = $addelement;
	}

	$array = $newarray;
}


// Special values for the $beforekey parameter for qa_array_reorder() - use floats since these cannot be real keys

define('QA_ARRAY_WITH_FIRST', null); // collect the elements together in the position of the first one found
define('QA_ARRAY_WITH_LAST', 0.6); // collect the elements together in the position of the last one found
define('QA_ARRAY_AT_START', 0.1); // place all the elements at the start of the array
define('QA_ARRAY_AT_END', 0.9); // place all the elements at the end of the array

/**
 * Moves all of the elements in $array whose keys are in the parameter $keys. They can be moved to before a specific
 * element by passing the key of that element in $beforekey (if $beforekey is not found, the elements are moved to the
 * end of the array). Any of the QA_ARRAY_* values defined above can also be passed in the $beforekey parameter.
 * If $reorderrelative is true, the relative ordering between the elements will also be set by the order in $keys.
 * @param array $array
 * @param array $keys
 * @param mixed|null $beforekey
 * @param bool $reorderrelative
 */
function qa_array_reorder(&$array, $keys, $beforekey = null, $reorderrelative = true)
{

	// Make a map for checking each key in $array against $keys and which gives their ordering

	$keyorder = array();
	$keyindex = 0;
	foreach ($keys as $key)
		$keyorder[$key] = ++$keyindex;

	// Create the new key ordering in $newkeys

	$newkeys = array();
	$insertkeys = array();
	$offset = null;

	if ($beforekey == QA_ARRAY_AT_START)
		$offset = 0;

	foreach ($array as $key => $value) {
		if ($beforekey == $key)
			$offset = count($newkeys);

		if (isset($keyorder[$key])) {
			if ($reorderrelative)
				$insertkeys[$keyorder[$key]] = $key; // in order of $keys parameter
			else
				$insertkeys[] = $key; // in order of original array

			if ($beforekey == QA_ARRAY_WITH_LAST || ($beforekey === QA_ARRAY_WITH_FIRST && !isset($offset)))
				$offset = count($newkeys);

		} else
			$newkeys[] = $key;
	}

	if (!isset($offset)) // also good for QA_ARRAY_AT_END
		$offset = count($newkeys);

	if ($reorderrelative)
		ksort($insertkeys, SORT_NUMERIC); // sort them based on position in $keys parameter

	array_splice($newkeys, $offset, 0, $insertkeys);

	// Rebuild the array based on the new key ordering

	$newarray = array();

	foreach ($newkeys as $key)
		$newarray[$key] = $array[$key];

	$array = $newarray;
}
