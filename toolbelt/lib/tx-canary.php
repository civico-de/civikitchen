<?php

/**
 * Transaction canary — a PHPUnit 9 listener that proves a transactional test
 * really was rolled back.
 *
 * The failure it exists for is silent by construction. A test that implements
 * Civi\Test\TransactionalInterface is wrapped in a transaction that
 * CiviTestListener rolls back afterwards, so fixtures never reach the next
 * test. But MySQL COMMITs implicitly on DDL — a CREATE TABLE, an ALTER, a
 * `civicrm_api3('CustomField', 'create')`, a schema rebuild in setUp() — and
 * from that statement on the "transaction" is gone. The rollback then rolls
 * back nothing, every later test inherits the leaked rows, and the suite stays
 * green until an unrelated test fails on a machine where the order differs.
 *
 * The check: inside the transaction, write a token row over the SITE
 * connection. After CiviTestListener has rolled back, look for that token over
 * a SECOND, independent connection. A row that is visible there was committed,
 * which can only have happened if the transaction was lost. One INSERT plus
 * one SELECT per transactional test; the second connection is opened once for
 * the whole process, never per test.
 *
 * The canary table is deliberately NOT named civicrm_*: Civi\Test's headless
 * schema rebuild drops core-prefixed tables, and a table that vanishes
 * mid-suite would read as a canary failure. If it is dropped anyway (a full
 * DROP DATABASE style reset), the INSERT is retried once after re-creating it.
 *
 * Wired in by `ckphpunit`, which injects this listener into a copy of the
 * repo's phpunit config — nothing in the extension repo references it.
 */

namespace Civi\CkTest;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;

/**
 * @deprecated Goes with PHPUnit 9's listener API, which CiviCRM's test harness runs on.
 */
final class TransactionCanaryListener implements TestListener {

  use TestListenerDefaultImplementation;

  private const TABLE = 'ck_tx_canary';

  private ?\PDO $probe = NULL;

  private bool $disabled = FALSE;

  private ?string $token = NULL;

  /**
   * Listener order matters and is set by ckphpunit: CiviTestListener opens the
   * transaction in its startTest and rolls back in its endTest, and this
   * listener is appended after it, so startTest here runs INSIDE the
   * transaction and endTest here runs AFTER the rollback.
   */
  public function startTest(Test $test): void {
    $this->token = NULL;
    if ($this->disabled || !$test instanceof \Civi\Test\TransactionalInterface) {
      return;
    }
    if (!class_exists('CRM_Core_DAO', FALSE)) {
      return;
    }
    try {
      $this->probe();
    }
    catch (\Throwable $e) {
      $this->disabled = TRUE;
      $this->fail($test, 'transaction canary could not open its own database connection — ' . $e->getMessage());
      return;
    }

    $token = bin2hex(random_bytes(12));
    try {
      $this->insert($token, get_class($test));
    }
    catch (\Throwable $e) {
      // Most likely the canary table was dropped by a headless schema rebuild.
      try {
        $this->createTable();
        $this->insert($token, get_class($test));
      }
      catch (\Throwable $retry) {
        $this->fail($test, 'transaction canary could not write its marker: ' . $retry->getMessage());
        return;
      }
    }
    $this->token = $token;
  }

  public function endTest(Test $test, float $time): void {
    $token = $this->token;
    $this->token = NULL;
    if ($token === NULL || $this->probe === NULL) {
      return;
    }

    $stmt = $this->probe->prepare('SELECT test_name FROM ' . self::TABLE . ' WHERE token = ?');
    $stmt->execute([$token]);
    $leaked = $stmt->fetchColumn();
    if ($leaked === FALSE) {
      return;
    }

    // Committed rows survive the rollback, so clean up before reporting —
    // otherwise the table grows for the rest of the run.
    $this->probe->prepare('DELETE FROM ' . self::TABLE . ' WHERE token = ?')->execute([$token]);
    $this->fail($test, sprintf(
      "%s lost its transaction — everything it wrote was COMMITTED and is now visible to every later test.\n"
      . "MySQL commits implicitly on DDL: CREATE/ALTER/DROP TABLE, a truncate, or an API call that changes\n"
      . "schema (CustomGroup/CustomField create, an entity install, a Civi\\Test schema rebuild) inside the\n"
      . "test body or setUp(). Move that schema work into setUpHeadless() — the CiviEnvBuilder there runs\n"
      . "BEFORE the transaction opens — or drop Civi\\Test\\TransactionalInterface and clean up explicitly.",
      get_class($test),
    ));
  }

  private function insert(string $token, string $testName): void {
    // Over the SITE connection on purpose: this write must live inside the
    // transaction under test.
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO ' . self::TABLE . ' (token, test_name) VALUES (%1, %2)',
      [1 => [$token, 'String'], 2 => [substr($testName, 0, 255), 'String']],
    );
  }

  private function createTable(): void {
    $this->probe->exec(
      'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
      . 'token VARCHAR(32) NOT NULL PRIMARY KEY, test_name VARCHAR(255) NOT NULL'
      . ') ENGINE=InnoDB',
    );
    $this->probe->exec('DELETE FROM ' . self::TABLE);
  }

  private function probe(): void {
    if ($this->probe !== NULL) {
      return;
    }
    // The site connection is the authority on which database the test run
    // actually landed in (civicrm_test, if the bootstrap guard did its job);
    // CIVICRM_DSN only supplies credentials and host.
    $database = \CRM_Core_DAO::singleValueQuery('SELECT DATABASE()');
    $dsn = defined('CIVICRM_DSN') ? (string) CIVICRM_DSN : '';
    $parts = parse_url($dsn);
    if (!is_array($parts) || !isset($parts['host']) || !is_string($database) || $database === '') {
      throw new \RuntimeException('cannot derive connection parameters from CIVICRM_DSN');
    }
    $pdo = new \PDO(
      sprintf('mysql:host=%s;port=%d;dbname=%s', $parts['host'], (int) ($parts['port'] ?? 3306), $database),
      urldecode((string) ($parts['user'] ?? '')),
      urldecode((string) ($parts['pass'] ?? '')),
      [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => FALSE],
    );
    $this->probe = $pdo;
    $this->createTable();
  }

  /**
   * Reported as a test failure rather than thrown: startTest/endTest run
   * outside the test's own try/catch, and an exception there aborts the whole
   * run with no attribution to the offending test.
   */
  private function fail(Test $test, string $message): void {
    $result = $test instanceof TestCase ? $test->getTestResultObject() : NULL;
    if ($result === NULL) {
      fwrite(STDERR, "\nTRANSACTION CANARY: {$message}\n");
      return;
    }
    $result->addFailure($test, new AssertionFailedError('TRANSACTION CANARY: ' . $message), 0.0);
  }

}
