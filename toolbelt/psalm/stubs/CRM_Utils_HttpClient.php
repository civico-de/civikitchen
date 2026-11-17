<?php

/**
 * Taint SINKS: outbound HTTP — request-controlled URLs are SSRF. Only the URI
 * is a sink; a request body sent to a fixed host is not SSRF.
 *
 * Signatures verified against CiviCRM 6.18.0 (CRM/Utils/HttpClient.php).
 */

class CRM_Utils_HttpClient {

  /**
   * @psalm-taint-sink ssrf $remoteFile
   * @psalm-taint-sink file $localFile
   */
  public function fetch($remoteFile, $localFile) {}

  /**
   * @psalm-taint-sink ssrf $remoteFile
   */
  public function get($remoteFile) {}

  /**
   * @psalm-taint-sink ssrf $remoteFile
   */
  public function post($remoteFile, $params) {}

}
