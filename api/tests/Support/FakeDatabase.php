<?php

namespace MagratheaExplorer\Tests\Support;

use Magrathea\DB\Database;
use Magrathea\DB\DatabaseSimulate;

/**
 * A stand-in for Database::Instance() that answers from canned handlers and records every
 * statement, for code whose logic depends on what the database returns (query counts, a row
 * that changed under a lock). DatabaseSimulate alone always returns nothing.
 *
 *   $db = FakeDatabase::Install();
 *   $db->On('/FROM `files`/', fn(string $sql, ?array $values) => [[...row...]]);
 *   ... FakeDatabase::Uninstall() in tearDown().
 *
 * A handler gets the SQL (and PrepareAndExecute()'s values) and returns rows for QueryAll(),
 * a row for QueryRow(), a scalar for QueryOne(), or the affected-row count for Query() and
 * PrepareAndExecute() (an int; anything else = 1). The first handler whose pattern matches
 * wins; a statement nothing matches returns nothing (and affects 0 rows). A handler can throw.
 */
class FakeDatabase extends DatabaseSimulate {

	/** @var array{kind: string, sql: string, values: ?array}[] */
	public array $statements = [];
	/** @var array{0: string, 1: callable}[] */
	private array $handlers = [];
	private int $affected = 0;
	private int $nextInsertId = 1000;

	public static function Install(): self {
		unset(self::$instance[static::class]);
		$fake = static::Instance();
		self::$instance[Database::class] = $fake;
		return $fake;
	}

	public static function Uninstall(): void {
		unset(self::$instance[Database::class], self::$instance[static::class]);
	}

	public function On(string $pattern, callable $handler): self {
		$this->handlers[] = [$pattern, $handler];
		return $this;
	}

	/** Statements that read or write (transaction markers aren't recorded), optionally matching $pattern. */
	public function Statements(?string $pattern = null): array {
		return array_values(array_filter($this->statements, fn($s) => $pattern === null || preg_match($pattern, $s["sql"]) === 1));
	}

	private function Answer(string $kind, string $sql, ?array $values = null): mixed {
		$this->statements[] = ["kind" => $kind, "sql" => $sql, "values" => $values];
		foreach($this->handlers as [$pattern, $handler]) {
			if(preg_match($pattern, $sql) === 1) return $handler($sql, $values);
		}
		return null;
	}

	public function OpenConnectionPlease() { return $this; }
	public function Disconnect(): void {}
	public function LastAffectedRows(): int { return $this->affected; }

	public function Query($sql) {
		$result = $this->Answer("query", $sql);
		$this->affected = is_int($result) ? $result : 0;
		return null;
	}

	public function QueryAll($sql) {
		$rows = $this->Answer("all", $sql);
		return is_array($rows) ? $rows : [];
	}

	public function QueryRow($sql) {
		$row = $this->Answer("row", $sql);
		return is_array($row) ? $row : [];
	}

	public function QueryOne($sql) {
		return $this->Answer("one", $sql);
	}

	public function PrepareAndExecute($query, $arrTypes, $arrValues) {
		$result = $this->Answer("prepare", $query, $arrValues);
		$this->affected = is_int($result) ? $result : 1;
		return str_starts_with(ltrim($query), "INSERT") ? $this->nextInsertId++ : true;
	}

}
