<?php

/**
 * escape() is an SQL escape. validate() is not: it returns 'String'/'Text'/
 * 'Memo'/'Link' data unchanged, so it carries an explicit flow. Both keep the
 * flow for the other sinks.
 *
 * Signatures verified against CiviCRM 6.18.0 (CRM/Utils/Type.php).
 */
class CRM_Utils_Type {

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($data) -> return
   */
  public static function escape($data, $type, $abort = TRUE) {}

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($data) -> return
   */
  public static function escapeAll($data, $type, $abort = TRUE) {}

  /**
   * @psalm-flow ($data) -> return
   */
  public static function validate($data, $type, $abort = TRUE, $name = 'One of parameters ') {}

  /**
   * @psalm-flow ($data) -> return
   */
  public static function validateAll($data, $type) {}

}
