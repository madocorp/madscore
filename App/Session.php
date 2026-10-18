<?php

namespace MADSCORE\App;

use SPTK\Core\AppData;

/** Persists the editor's working state independently of named score files. */
final class Session {

  private const FILE = 'session.json';

  public static function load(?string $path = null): ?array {
    $path ??= AppData::file(self::FILE);
    if (!is_file($path)) {
      return null;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
      throw new \RuntimeException('Could not read the previous editor session.');
    }
    $state = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state)) {
      throw new \DomainException('The previous editor session is invalid.');
    }
    return $state;
  }

  public static function save(array $state, ?string $path = null): void {
    $path ??= AppData::file(self::FILE);
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
    try {
      if (file_put_contents($temp, $encoded) !== strlen($encoded) || !rename($temp, $path)) {
        throw new \RuntimeException('Could not store the editor session.');
      }
    } finally {
      if (is_file($temp)) {
        unlink($temp);
      }
    }
  }

  /** Preserve an unreadable session before a fresh one replaces it. */
  public static function backupInvalid(?string $path = null): void {
    $path ??= AppData::file(self::FILE);
    $backup = $path . '.invalid-' . bin2hex(random_bytes(6));
    if (!copy($path, $backup)) {
      throw new \RuntimeException('Could not preserve the previous editor session.');
    }
  }
}
