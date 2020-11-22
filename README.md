# PHP-Helpers

Standalone PHP7 helpers for sql parametrization and safe condition composition, also redis and sqlserver sane interfaces.

*All code requires utils.php containing generic utility functions, other files can be used independently.*

## Sql block definitions and parser (sql.php)

### ISqlBlock

Generic interface for everything producing a parametrized statement (has SQL and Params).

### SqlBlock

Simple text and array block implementation.

### SqlOrder

Order by block, parse can read objects in format:
```
["Field1", "Field2 asc", "Field3 desc"]
```

`asc` is implied if missing.

### SqlWhere

Where block, parse can read objects in format:
```
whereBin: {
    field: string,
    op: '=' | '>' | '<' | '<>' | '>=' | '<=' | 'like' | 'is',
    val: string|number|bool
};
whereLogic: {
    op: 'AND' | 'OR',
    blocks: [whereOp]
};
whereOp: whereBin | whereLogic;
```

Block with `op == ''` is considered TRUE.

### Param

This object represents a named parameter, in case of blocks rapresents a named bind,
in case of templates it is a parametrized block (so parameter of a sql query, not to be used for unsanitized user input).

Use `toBlock` to convert to sql parameter when used in templates.

### SqlTemplate

Sql factory with parameters.

Query construction is deferred to allow block parameter binding, so this is not an instance of ISqlBlock,
but it returns one when calling `generate()`.

Use `mergeBlockParams` and `mergeParams` to bind parameters.

There are useful static functions for select, insert, delete, and update.

## SqlSrv (sqlsrv.php)

`\SqlSrv\Connection` mimics `mysqli` object oriented connection and statement.

*Requires mssql plugin*

## RedisScript (redis.php)

Class to wrap long REDIS scripts (LUA) to avoid resending to server (tryes to use sha and sends script if it fails).

*Requires redis plugin*
