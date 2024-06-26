<?php

use Q2A\Database\DbQueryHelper;

class DbQueryHelperTest extends \PHPUnit\Framework\TestCase
{
	/** @var DbQueryHelper */
	private $helper;

	protected function setUp(): void
	{
		$this->helper = new DbQueryHelper();
	}

	public function test__expandParameters_success()
	{
		$result = $this->helper->expandParameters('SELECT * FROM table WHERE field=1', []);
		$expected = ['SELECT * FROM table WHERE field=1', []];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('SELECT * FROM table WHERE field=?', [1]);
		$expected = ['SELECT * FROM table WHERE field=?', [1]];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('SELECT * FROM table WHERE field=#', [1]);
		$expected = ['SELECT * FROM table WHERE field=?', [1]];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('SELECT * FROM table WHERE field IN (?)', [[1]]);
		$expected = ['SELECT * FROM table WHERE field IN (?)', [1]];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('SELECT * FROM table WHERE field IN (?)', [[1, 2]]);
		$expected = ['SELECT * FROM table WHERE field IN (?, ?)', [1, 2]];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('INSERT INTO table(field) VALUES ?', [[ [1] ]]);
		$expected = ['INSERT INTO table(field) VALUES (?)', [1]];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('INSERT INTO table(field) VALUES ?', [[ [1], [2] ]]);
		$expected = ['INSERT INTO table(field) VALUES (?), (?)', [1, 2]];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('INSERT INTO table(field1, field2) VALUES ?', [[ [1, 2] ]]);
		$expected = ['INSERT INTO table(field1, field2) VALUES (?, ?)', [1, 2]];
		$this->assertSame($expected, $result);

		$result = $this->helper->expandParameters('INSERT INTO table(field1, field2) VALUES ?', [[ [1, 2], [3, 4] ]]);
		$expected = ['INSERT INTO table(field1, field2) VALUES (?, ?), (?, ?)', [1, 2, 3, 4]];
		$this->assertSame($expected, $result);
	}

	public function test__expandParameters_incorrect_groups_error()
	{
		$this->expectException('Q2A\Database\Exceptions\SelectSpecException');
		$this->helper->expandParameters('INSERT INTO table(field1, field2) VALUES ?', [[ [1, 2], [3] ]]);
	}

	public function test__applyTableSub()
	{
		$prefix = QA_MYSQL_TABLE_PREFIX;

		$result = $this->helper->applyTableSub('SELECT * FROM ^options');
		$this->assertSame("SELECT * FROM {$prefix}options", $result);

		$result = $this->helper->applyTableSub('SELECT * FROM ^users WHERE userid=?');
		$this->assertSame("SELECT * FROM {$prefix}users WHERE userid=?", $result);
	}
}
