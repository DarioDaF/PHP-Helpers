<?php
declare(strict_types=1);

namespace SqlSrv {
	require_once __DIR__ . '\utils.php';

	class SqlSrvException extends \Exception {
		function __construct(string $msgHeader, int $code = 0, Exception $previous = null) {
			$errors = errors(SQLSRV_ERR_ERRORS);
			foreach ($errors as $error) {
				$msgHeader .= '| ' . utf8_encode($error['message']);
			}
			parent::__construct($msgHeader, $code, $previous);
		}
	}
	class ConnectionException extends SqlSrvException {}
	class StatementException extends SqlSrvException {}
	class FetchException extends SqlSrvException {}

	function errors(int $errorsOrWarnings = SQLSRV_ERR_ALL) {
		return sqlsrv_errors($errorsOrWarnings);
	}

	class Connection {
		//private resource $res; // Cannot type hint... resource is a "virtual" type, an actual resource does not match any hint
		private $conn;
	
		function __construct(string $serverName, array $connectionInfo = []) {
			if(($this->conn = sqlsrv_connect($serverName, $connectionInfo)) === false) {
				throw new ConnectionException('Unable to establish connection: ');
			}
		}
		function query(string $sql, array $params = [], array $options = []): Statement {
			return new Statement(sqlsrv_query($this->conn, $sql, $params, $options));
		}
		function prepare(string $sql, array $params = [], array $options = []): Statement {
			return new Statement(sqlsrv_prepare($this->conn, $sql, $params, $options));
		}
		/*
			Transaction usage:
				$conn->begin_transaction();
				try {
					// Do stuff
					$conn->commit();
				} catch(Exception $e) {
					$conn->rollback();
					throw $e;
				}
		*/
		/*
		function transaction() {
			$this->begin_transaction();
			try {
				yield; // Cannot catch errors outside!!! :(
				$this->commit();
			} catch(Exception $e) {
				$this->rollback();
				throw $e;
			}
		}
		*/
		function begin_transaction() {
			if(!sqlsrv_begin_transaction($this->conn)) {
				throw new StatementException('Unable to begin transaction');
			}
		}
		function rollback() {
			if(!sqlsrv_rollback($this->conn)) {
				throw new StatementException('Unable to rollback');
			}
		}
		function commit() {
			if(!sqlsrv_commit($this->conn)) {
				throw new StatementException('Unable to commit');
			}
		}
		function close() { // Should be private to avoid use after close? (__destruct not always called when you think...)
			if($this->conn !== null) {
				sqlsrv_close($this->conn); // NULL is a valid parameter, but typing complains in PHP7
				$this->conn = null;
			}
		}
		function __destruct() {
			$this->close();
		}
	}

	abstract class FetchType {
		const FT_ARRAY = SQLSRV_FETCH_NUMERIC;
		const FT_OBJECT = SQLSRV_FETCH_ASSOC;
		const FT_BOTH = SQLSRV_FETCH_BOTH;
		const FT_NONE = -1;
	}

	class Statement {
		private $stmt;

		function __construct($stmt) {
			if(($this->stmt = $stmt) === false) {
				throw new StatementException('Unable to perform query: ');
			}
		}
		function execute(): bool {
			$res = sqlsrv_execute($this->stmt);
			if($res === false) {
				throw new StatementException('Unable to perform query: ');
			}
			return $res;
		}
		function fetch(int $fetchType = FetchType::FT_BOTH, int $row = SQLSRV_SCROLL_NEXT, int $offset = 0) {
			if($fetchType === FetchType::FT_NONE) {
				$res = sqlsrv_fetch($this->stmt, $row, $offset);
			} else {
				$res = sqlsrv_fetch_array($this->stmt, $fetchType, $row, $offset);
			}
			if($res === false) {
				throw new FetchException('Error while fetching data: ');
			}
			return $res;
		}
		function has_rows(): bool {
			return sqlsrv_has_rows($this->stmt);
		}
		function next() {
			if(($res = sqlsrv_next_result($this->stmt)) === false) {
				throw new FetchException('Error while fetching data: ');
			}
			return (bool)$res;
		}
		function num_rows(): int {
			// To be used ONLY IF static or keyset cursor is used!!!
			return sqlsrv_num_rows($this->stmt);
		}
		function rows_affected(): int {
			return sqlsrv_rows_affected($this->stmt);
		}
		function close() {
			if($this->stmt !== null) {
					sqlsrv_free_stmt($this->stmt);
					$this->stmt = null;
			}
		}
		function __destruct() {
			$this->close();
		}
		function echo_as_json() {
			$first = true;
			echo '[';
			while(($res = $this->fetch(FetchType::FT_OBJECT)) !== null) {
				if(!$first) {
					echo ',';
				} else {
					$first = false;
				}
				echo my_json_encode($res);
			}
			echo ']';
		}
		function read_all(int $fetchType = FetchType::FT_OBJECT) {
			$result = [];
			while(($row = $this->fetch($fetchType)) !== null) {
				$result[] = $row;
			}
			return $result;
		}
		function read_one(int $fetchType = FetchType::FT_OBJECT) {
			$line = $this->fetch($fetchType);
			if($this->fetch(FetchType::FT_NONE) !== null) { // Does has_rows work?
				throw new StatementException('Unexpected multiple rows');
			}
			return $line;
		}
	}
}
