<?php

/**
 * Taint SINKS: Guzzle's URI argument (SSRF); $options is not a sink.
 *
 * Signatures per guzzlehttp/guzzle 7.x.
 */

namespace GuzzleHttp;

interface ClientInterface {

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function request(string $method, $uri, array $options = []);

  public function send(\Psr\Http\Message\RequestInterface $request, array $options = []);

  public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []);

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function requestAsync(string $method, $uri, array $options = []);

}

/**
 * The __call verb methods (get/post/put/…), declared because Psalm cannot
 * annotate __call.
 */
class Client implements ClientInterface {

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function request(string $method, $uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function requestAsync(string $method, $uri, array $options = []) {}

  public function send(\Psr\Http\Message\RequestInterface $request, array $options = []) {}

  public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function get($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function head($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function post($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function put($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function patch($uri, array $options = []) {}

  /**
   * @psalm-taint-sink ssrf $uri
   */
  public function delete($uri, array $options = []) {}

}
