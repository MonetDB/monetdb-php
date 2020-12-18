<?php
use PHPUnit\Framework\TestCase;
use MonetDB\Connection;

require_once(__DIR__. '../../src/include.php');

final class int64Test extends TestCase {
    /**
     * @var Connection
     */
    public $conn;

    public function setUp(): void
    {
        $this->conn = new Connection("127.0.0.1", 50000, "monetdb", "monetdb", "myDatabase");
    }

    public function testBigIntTable(): void 
    {
        $this->conn->Query("drop table if exists php_int64_dec18");
        $res = $this->conn->Query("CREATE TABLE php_int64_dec18 (i BIGINT, d0 DECIMAL(18,0), d9 DECIMAL(18,9));");

        $this->assertCount(0, $res);
    }

    public function testInsertBigInt(): void 
    {
        $res = $this->conn->Query("INSERT INTO php_int64_dec18 VALUES (1234567890987654321, 123456789987654321, 123456789.987654321);");

        $this->assertCount(0, $res);
    }

    public function testSelectBigInt(): void 
    {
        $res = $this->conn->QueryFirst("SELECT * FROM php_int64_dec18;");

        $this->assertEquals($res["i"], "1234567890987654321");
        $this->assertEquals($res["d0"], "123456789987654321");
        $this->assertEquals($res["d9"], "123456789.987654321");
    }
}
