<?php

/**
 * Taint SOURCES: CRM_Utils_Request. Tainted regardless of $type, since Psalm
 * cannot decide per argument value, so a 'Positive' retrieval is over-reported.
 *
 * Signatures verified against CiviCRM 6.18.0 (CRM/Utils/Request.php).
 */
class CRM_Utils_Request {

  /**
   * @psalm-taint-source input
   */
  public static function retrieve($name, $type, $store = NULL, $abort = FALSE, $default = NULL, $method = 'REQUEST') {}

  /**
   * @psalm-taint-source input
   */
  public static function retrieveValue($name, $type, $defaultValue = NULL, $isRequired = FALSE, $method = 'REQUEST') {}

  /**
   * The whole request array, unfiltered.
   *
   * @psalm-taint-source input
   */
  public static function exportValues() {}

  /**
   * @psalm-taint-source input
   */
  public static function retrieveComponent($attributes) {}

}
