<?php
declare(strict_types=1);

function fields_prefix(string $prefix, array $fields): array {
	return array_combine($fields, array_map(function($f) use ($prefix) { return $prefix.$f; }, $fields));
}

function array_map_kv(callable $cb, array $arr) {
	return array_map($cb, array_keys($arr), array_values($arr));
}

function array_only_keys_exists(array $keys, array $obj) {
	// All keys of $obj are in $keys
	return array_all_keys_exists(array_keys($obj), array_fill_keys($keys, true));
}

function array_all_keys_exists(array $keys, array $obj) {
	foreach($keys as $key) {
		if(!array_key_exists($key, $obj)) {
			return false;
		}
	}
	return true;
}

function array_any_keys_exists(array $keys, array $obj) {
	foreach($keys as $key) {
		if(array_key_exists($key, $obj)) {
			return true;
		}
	}
	return false;
}

function zip_constant(array $arr, $const) {
	$first = true;
	foreach($arr as $el) {
		if($first) {
			$first = false;
		} else {
			yield $const;
		}
		yield $el;
	}
}

function dateTimeToISOZulu(DateTimeInterface $datetime) {
	// https://www.reddit.com/r/lolphp/comments/3nz2hd/datetimeformatdatetimeiso8601_doesnt_format_date/
	// ATOM is correct ISO8601 with timezone using `:`, but I want always Zulu!
	//return $datetime->setTimezone(new \DateTimeZone('UTC'))->format(DateTime::ATOM);
	// https://stackoverflow.com/a/57701653/4569083
	$tmp = clone $datetime; // setTimezone alters object inside (also if no &?)
	return $tmp
		->setTimezone(new \DateTimeZone('UTC'))
		->format('Y-m-d\TH:i:s\Z');
}

// Poors man implementation to return Date correctly (cannot override jsonSerialize on Date apparently...)
// Base from LGPL: https://github.com/alexmuz/php-json/blob/master/json_encode.php
// @author Alexander Muzychenko
function my_json_encode($value) {
	if(is_int($value)) {
		return (string)$value;
	} elseif(is_string($value)) {
		$value = str_replace(
			array('\\', '/', '"', "\r", "\n", "\b", "\f", "\t"),
			array('\\\\', '\/', '\"', '\r', '\n', '\b', '\f', '\t'),
			$value
		);
		$convmap = array(0x80, 0xFFFF, 0, 0xFFFF);
		$result = "";
		for($i = mb_strlen($value) - 1; $i >= 0; $i--) {
			$mb_char = mb_substr($value, $i, 1);
			if(mb_ereg("&#(\\d+);", mb_encode_numericentity($mb_char, $convmap, "UTF-8"), $match)) {
				$result = sprintf("\\u%04x", $match[1]) . $result;
			} else {
				$result = $mb_char . $result;
			}
		}
		return '"' . $result . '"';
	} elseif(is_float($value)) {
		return str_replace(",", ".", $value);
	} elseif(is_null($value)) {
		return 'null';
	} elseif(is_bool($value)) {
		return $value ? 'true' : 'false';
	} elseif(is_array($value)) {
		$with_keys = false;
		$n = count($value);
		for($i = 0, reset($value); $i < $n; $i++, next($value)) {
			if (key($value) !== $i) {
				$with_keys = true;
				break;
			}
		}
	} elseif($value instanceof DateTime) { // This is changed
		return my_json_encode(dateTimeToISOZulu($value));
	} elseif(is_object($value)) {
		$with_keys = true;
	} else {
		return '';
	}
	$result = array();
	if($with_keys) {
		foreach($value as $key => $v) {
			$result[] = my_json_encode((string)$key) . ':' . my_json_encode($v); // Recursive :(
		}
		return '{' . implode(',', $result) . '}';
	} else {
		foreach($value as $key => $v) {
			$result[] = my_json_encode($v);
		}
		return '[' . implode(',', $result) . ']';
	}
}
