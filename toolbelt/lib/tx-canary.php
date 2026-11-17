<?php

/**
 * PHPUnit 9 listener, injected by `ckphpunit`: a token row written inside a
 * transactional test must be invisible to a second connection afterwards.
 * The canary table is not named civicrm_*, which the headless rebuild drops.
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
   * Appended after CiviTestListener by ckphpunit, so startTest runs inside the
   * transaction and endTest after the rollback.
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
    // The site connection names the database; CIVICRM_DSN supplies credentials and host.
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
   * Reported as a test failure, not thrown: an exception in startTest/endTest
   * aborts the whole run unattributed.
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
