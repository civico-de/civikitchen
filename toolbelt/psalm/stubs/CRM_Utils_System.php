<?php

/**
 * Taint SINKS: the CRM_Utils_System calls that end up in a response header.
 * download()'s $buffer is HTML taint, which psalm-taint.xml.dist turns off.
 *
 * Signatures verified against CiviCRM 6.18.0 (CRM/Utils/System.php).
 */
class CRM_Utils_System {

  /**
   * @psalm-taint-sink header $url
   */
  public static function redirect($url = NULL, $context = []) {}

  /**
   * @psalm-taint-sink header $name
   * @psalm-taint-sink header $value
   */
  public static function setHttpHeader($name, $value) {}

  /**
   * @psalm-taint-sink header $name
   * @psalm-taint-sink header $mimeType
   * @psalm-taint-sink header $ext
   */
  public static function download(
    $name,
    $mimeType,
    &$buffer,
    $ext = NULL,
    $output = TRUE,
    $disposition = 'attachment'
  ) {}

}
