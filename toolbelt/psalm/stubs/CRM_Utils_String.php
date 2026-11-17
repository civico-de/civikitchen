<?php

/**
 * ESCAPES: munge() (only `[a-zA-Z0-9]` survives, so every sink) and
 * purifyHTML() (HTML only). stripPathChars() keeps `/` and `..`, so it stays
 * a pass-through flow.
 *
 * Signatures verified against CiviCRM 6.18.0 (CRM/Utils/String.php).
 */
class CRM_Utils_String {

  /**
   * @psalm-taint-escape sql
   * @psalm-taint-escape shell
   * @psalm-taint-escape file
   * @psalm-taint-escape header
   * @psalm-taint-escape html
   * @psalm-flow ($name) -> return
   */
  public static function munge($name, $char = '_', $len = 63) {}

  /**
   * @psalm-taint-escape html
   * @psalm-flow ($string) -> return
   */
  public static function purifyHTML($string) {}

  /**
   * @psalm-flow ($string) -> return
   */
  public static function stripPathChars($string, $search = NULL, $replace = NULL) {}

}
