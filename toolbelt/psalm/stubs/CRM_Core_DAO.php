<?php

/**
 * Taint SINKS: the query entry points ($query only; $params is the safe path),
 * plus escapeString(), an escape for SQL only.
 *
 * Signatures verified against CiviCRM 6.18.0 (CRM/Core/DAO.php).
 */
class CRM_Core_DAO {

  /**
   * @psalm-taint-sink sql $query
   */
  public static function &executeQuery(
    $query,
    $params = [],
    $abort = TRUE,
    $daoName = NULL,
    $freeDAO = FALSE,
    $i18nRewrite = TRUE,
    $trapException = FALSE,
    $options = []
  ) {}

  /**
   * @psalm-taint-sink sql $query
   */
  public static function executeUnbufferedQuery(
    $query,
    $params = [],
    $abort = TRUE,
    $daoName = NULL,
    $freeDAO = FALSE,
    $i18nRewrite = TRUE,
    $trapException = FALSE
  ) {}

  /**
   * @psalm-taint-sink sql $query
   */
  public static function &singleValueQuery($query, $params = [], $abort = TRUE, $i18nRewrite = TRUE) {}

  /**
   * Executes nothing, but flags the injection at the line that assembles it.
   *
   * @psalm-taint-sink sql $query
   */
  public static function composeQuery($query, $params = [], $abort = TRUE) {}

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($string) -> return
   */
  public static function escapeString($string) {}

  /**
   * @psalm-taint-escape sql
   * @psalm-flow ($strings) -> return
   */
  public static function escapeStrings($strings, $default = NULL) {}

}
