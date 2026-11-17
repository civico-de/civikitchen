<?php

/**
 * Taint SINKS: the CRM_Utils_File helpers that act on a filesystem path; the
 * ones that only inspect a string are not sinks. runSqlQuery() is an SQL sink.
 *
 * Signatures verified against CiviCRM 6.18.0 (CRM/Utils/File.php).
 */
class CRM_Utils_File {

  /**
   * @psalm-taint-sink file $path
   */
  public static function createDir($path, $abort = TRUE) {}

  /**
   * @psalm-taint-sink file $target
   */
  public static function cleanDir(string $target, bool $rmdir = TRUE, bool $verbose = TRUE) {}

  /**
   * @psalm-taint-sink file $source
   * @psalm-taint-sink file $destination
   */
  public static function copyDir($source, $destination) {}

  /**
   * @psalm-taint-sink file $filePath
   */
  public static function duplicate($filePath) {}

  /**
   * @psalm-taint-sink file $fileName
   */
  public static function sourceSQLFile($dsn, $fileName, $prefix = NULL, $dieOnErrors = TRUE) {}

  /**
   * @psalm-taint-sink sql $queryString
   */
  public static function runSqlQuery($dsn, $queryString, $prefix = NULL, $dieOnErrors = TRUE) {}

  /**
   * @psalm-taint-sink file $dir
   */
  public static function findFiles($dir, $pattern, $relative = FALSE, ?int $maxDepth = NULL) {}

  /**
   * @psalm-taint-sink file $path
   */
  public static function getFilesByExtension($path, $ext) {}

  /**
   * @psalm-taint-sink file $fromDir
   * @psalm-taint-sink file $toDir
   */
  public static function replaceDir($fromDir, $toDir, $verbose = FALSE) {}

  /**
   * @psalm-taint-sink file $dir
   * @psalm-taint-sink file $fileName
   */
  public static function createFakeFile($dir, $contents = 'delete me', $fileName = NULL) {}

  /**
   * @psalm-taint-sink file $sourceFile
   */
  public static function resizeImage($sourceFile, $targetWidth, $targetHeight, $suffix = "", $preserveAspect = TRUE) {}

  /**
   * ESCAPE: the upload-name sanitizer; munge() leaves no separator or traversal.
   *
   * @psalm-taint-escape file
   * @psalm-flow ($name) -> return
   */
  public static function makeFileName($name, bool $unicode = FALSE) {}

  /**
   * @psalm-taint-escape file
   * @psalm-flow ($input) -> return
   */
  public static function makeFilenameWithUnicode(string $input, string $replacementString = '_', int $cutoffLength = 63) {}

  /**
   * NOT an escape: it only strips the md5 from a stored file name. Stubbed so
   * an empty body does not launder taint.
   *
   * @psalm-flow ($name) -> return
   */
  public static function cleanFileName($name) {}

}
