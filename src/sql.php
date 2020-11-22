<?php
declare(strict_types=1);

require_once __DIR__ . '\utils.php';

interface ISqlBlock {
	function getSql(): string;
	function getParams(): array;
}

class SqlBlock implements ISqlBlock {
	public string $sql;
	public array $params;
	function getSql(): string {
		return $this->sql;
	}
	function getParams(): array {
		return $this->params;
	}
	function __construct($sql, $params = []) {
		$this->sql = $sql;
		$this->params = $params;
	}
	static function cat($joiner, ...$blocks): SqlBlock {
		$blocks = array_map(function($block) {
			if(is_string($block)) {
				return new SqlBlock($block);
			}
			return $block;
		}, $blocks);
		return new SqlBlock(
			array_reduce($blocks, function($res, $block) use ($joiner) {
				return $res . ($res !== '' ? $joiner : '') . $block->getSql();
			}, ''),
			array_reduce($blocks, function($res, $block) {
				return array_merge($res, $block->getParams());
			}, [])
		);
	}
}

class SqlOrder extends SqlBlock {
	const ASC = 'asc';
	const DESC = 'desc';

	static array $modifiers = [ SqlOrder::ASC, SqlOrder::DESC ]; // Should be const

	public array $fields = [];

	function __construct(...$fields) {
		$this->params = []; // No params
		$this->fields = $fields;
	}
	function getSql(): string {
		return count($this->fields) > 0 ? 'order by '.implode(', ', $this->fields) : '';
	}

	static function parse($o, array $fieldMap) {
		$fields = [];
		foreach($o as $field) {
			$fieldz = explode(' ', $field, 2);
			if(array_key_exists(1, $fieldz) && !in_array($fieldz[1], SqlOrder::$modifiers)) {
				throw new SafeException('Invalid order modifier: '.$fieldz[1]);
			}
			if(!array_key_exists($fieldz[0], $fieldMap)) {
				throw new SafeException('Invalid field: '.$fieldz[0]);
			}
			$fieldz[0] = $fieldMap[$fieldz[0]];
			$fields[] = implode(' ', $fieldz);
		}
		return new SqlOrder(...$fields);
	}

	static function merge(SqlOrder ...$orders) {
		return new SqlOrder(...array_merge(...array_map(function($order) { return $order->fields; }, $orders)));
	}

}

class SqlWhere extends SqlBlock {
	public string $partialSql;
	public array $params;

	function __construct(string $sql, array $params = []) {
		$this->partialSql = $sql;
		$this->params = $params;
	}
	function getSql(): string {
		return $this->partialSql === '' ? '' : 'where '.$this->partialSql;
	}

	static array $binConds = ['=', '>', '<', '<>', '>=', '<=', 'like', 'is']; // Should be const
	static array $logicOps = ['and', 'or']; // Should be const

	static function logicOp(string $op, SqlWhere ...$blocks): SqlWhere {
		if(!in_array($op, SqlWhere::$logicOps, true))
			throw new SafeException('Invalid logic operation: '.$op);
		$fBlocks = array_filter($blocks, function($block) { return $block->partialSql !== ''; }); // In 'or' an empty block should be true???
		return new SqlWhere(
			implode(' '.$op.' ',
				array_map(function($block) { return '('.$block->partialSql.')'; },
					$fBlocks
				) // Enforce priority
			),
			array_merge(
				...array_map(function($block) { return $block->getParams(); }, $fBlocks)
			)
		);
	}
	static function binCond(string $field, string $op, $val): SqlWhere { // TODO: Allow $field to be an ISqlBlock?
		if(!in_array($op, SqlWhere::$binConds, true))
			throw new SafeException('Invalid binary condition: '.$op);
		if($op === 'is') {
			return new SqlWhere('('.$field.') is '.($val ? 'not null' : 'null'));
		} else {
			return new SqlWhere('('.$field.') '.$op.' ?', [ $val ]); // Add () to resolve complex fields
		}
	}

	static function parse($o, array $fieldMap) {
		// Stack machine, polish notation
		$eval_stack = [];
		
		$f_stack = [];
		$arg_stack = [];
		
		$eval_stack[] = $o;

		// Eval
		while(count($eval_stack) > 0) {
			$curr = array_pop($eval_stack);
			// Check directly op?
			$op = $curr->op ?? '';
			if($op === '') {
				$arg_stack[] = new SqlWhere('');
			} elseif(in_array($op, SqlWhere::$binConds, true)) {
				if(!array_key_exists($curr->field, $fieldMap)) {
					throw new SafeException('Invalid field: '.$curr->field);
				}
				$field = $fieldMap[$curr->field];
				$arg_stack[] = SqlWhere::binCond($field, $curr->op, $curr->val);
			} elseif(in_array($op, SqlWhere::$logicOps, true)) {
				$f_stack[] = $curr->op;
				array_push($eval_stack, ...$curr->blocks);
				$arg_stack[] = ''; // Separator (call marker)
			} else {
				throw new SafeException('Invalid query operation: '.$op);
			}
		}

		// Compose
		while(count($f_stack) > 0) {
			$curr = array_pop($f_stack);
			$args = [];
			while(($arg = array_pop($arg_stack)) !== '') {
				$args[] = $arg;
			}
			$arg_stack[] = SqlWhere::logicOp($curr, ...$args);
		}

		// Stack should have 1 element
		if(count($arg_stack) != 1) {
			throw new SafeException('Invalid where stack');
		}

		return $arg_stack[0];
	}
}

class Undefined {
	private function __construct() {}
	public static Undefined $_;
	public static function prepare() {
		if(!isset(Undefined::$_)) {
			Undefined::$_ = new Undefined();
		}
	}
}
Undefined::prepare();

class Param {
	public $key; // string | integer
	public $value;
	function __construct($key) { // God func_get_args is needed :( to allow Undefined (cannot use fields in default...)
		$this->key = $key;
		$this->value = Undefined::$_;
	}
	function def($value): Param {
		$this->value = $value;
		return $this; // Chainable to allow set inline
	}
	function toBlock(string $sql = '?'): SqlBlock {
		return new SqlBlock($sql, [$this]);
	}
	static function mapBlocks(array $fields): array {
		return array_map(function($paramName) { return (new Param($paramName))->toBlock(); }, array_flip($fields));
	}
}

class SqlTemplate {
	private array $blocks;
	private array $blockParams = [];
	private array $params = [];
	function __construct(...$blocks) {
		$this_blockParams = &$this->blockParams;
		$this_params = &$this->params;
		// Possible values:
		// - string -> No-param SqlBlock
		// - Param -> Block parameter
		// - SqlBlock -> SqlBlock with potential parameters
		$this->blocks = array_map(function($block) use (&$this_blockParams, &$this_params) {
			if(is_string($block)) {
				return $block;
			}
			if($block instanceof Param) {
				$this_blockParams[$block->key] = Undefined::$_; //$block->value; // use value if null, do not enforce/copy beforehand
				return $block;
			}
			if($block instanceof SqlBlock) {
				foreach($block->params as $param) {
					if($param instanceof Param) {
						$this_params[$param->key] = Undefined::$_; //$param->value; // use value if null, do not enforce/copy beforehand
					}
				}
				return $block;
			}
			throw new Exception('Unknown block in template');
		}, $blocks);
	}
	function mergeBlockParams(array $blocks) {
		$this->blockParams = array_replace($this->blockParams, $blocks);
	}
	function mergeParams(array $params) {
		$this->params = array_replace($this->params, $params);
	}
	function generate(): SqlBlock { // Do not implement ISqlBlock directly, because time of resolution is important for defaults!
		$this_blockParams = &$this->blockParams;
		$this_params = &$this->params;
		// Do not modify the template!
		$res = SqlBlock::cat('', ...array_map(function($block) use ($this_blockParams) {
			if(is_string($block)) {
				return $block;
			}
			if($block instanceof Param) {
				$actParam = $this_blockParams[$block->key];
				$block = $actParam instanceof Undefined ? $block->value : $actParam; // Fallback
			}
			if($block instanceof Undefined) {
				throw new Exception('Missing a block parameter');
			}
			// Now we have normalized SqlBlocks with potential parameters
			return $block;
		}, $this->blocks));
		// Pass trough normal params
		$res->params = array_map(function($param) use ($this_params) {
			if($param instanceof Param) {
				$actParam = $this_params[$param->key];
				$param = $actParam instanceof Undefined ? $param->value : $actParam; // Fallback
			}
			if($param instanceof Undefined) {
				throw new Exception('Missing a parameter');
			}
			return $param;
		}, $res->params);
		return $res;
	}
	// TODO: Verify that __clone is ok with arrays...
	// Ususally $output = 'inserted.id'
	static function insert($table, array $fields, string $output = ''): SqlTemplate { // $id and $fields must contain Params or SqlBlocks! or sql injection...
		return new SqlTemplate(...[ // PHP cannot unwrap arguments and have positionals after, so use array
			'insert into ', $table, '('.implode(', ', array_keys($fields)).')
',		($output === '' ? '' : 'output '.$output), '
',		'values (', ...zip_constant(array_values($fields), ', '), ')'
		]);
	}
	static function select($table, array $fields, $join, $where, $orderBy, $offset = '', $count = '', $distinct = false): SqlTemplate { // $id and $fields must contain Params or SqlBlocks! or sql injection...
		if($count !== '' && $offset === '') {
			$offset = 0;
		}
		if(is_integer($offset)) {
			$offset = strval($offset);
		}
		if(is_integer($count)) {
			$count = strval($count);
		}

		$fetch = []; // To have offset or count there MUST be at least a default order!
		if($offset !== '') {
			$fetch = array_merge($fetch, [
				'offset ', $offset, ' rows'
			]);
			if($count !== '') {
				$fetch = array_merge($fetch, [
					'
',				'fetch next ', $count, ' rows only'
				]);
			}
		}

		return new SqlTemplate( // Should keep order? does map_kv do this?
			'select ', $distinct ? 'distinct ' : '', implode(', ', array_map_kv(function($key, $val) { return $val.(is_int($key) ? '' : ' as '.$key); }, $fields)), '
',		'from ', $table, '
',		$join, '
',		$where, '
',		$orderBy, '
',		...$fetch
		);
	}
	static function delete($table, $where, $limit = true): SqlTemplate { // $id and $fields must contain Params or SqlBlocks! or sql injection...
		return new SqlTemplate(
			'delete '.($limit ? 'top (1) ' : '').'from ', $table, '
',		$where
		);
	}
	static function update($table, array $fields, $where): SqlTemplate { // $id and $fields must contain Params or SqlBlocks! or sql injection...
		$first = true;
		return new SqlTemplate(...[
			'update ', $table, '
',		'set ', ...array_merge( // No brackets
				...array_map_kv(function($key, $value) use (&$first) {
					if($first) {
						$first = false;
						return [$key.' = ', $value];
					} else {
						return [', '.$key.' = ', $value];
					}
				}, $fields)
			), '
',		$where
		]);
	}
}
