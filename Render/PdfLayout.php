<?php

namespace MADSCORE\Render;

/** PDF-only print sizes, independent of the editor's score geometry. */
final class PdfLayout {
  private static int $noteSize = 100;
  private static int $beatSize = 100;

  public static function noteSize(): int {
    return self::$noteSize;
  }

  public static function beatSize(): int {
    return self::$beatSize;
  }

  public static function configure(int $noteSize, int $beatSize): void {
    self::$noteSize = max(50, min(140, $noteSize));
    self::$beatSize = max(self::$noteSize, min(140, $beatSize));
  }

  public static function change(int $noteDelta, int $beatDelta): void {
    $noteSize = max(50, min(140, self::$noteSize + $noteDelta));
    $beatSize = max(50, min(140, self::$beatSize + $beatDelta));
    if ($noteDelta > 0) {
      $beatSize = max($beatSize, $noteSize);
    } else if ($beatDelta < 0) {
      $noteSize = min($noteSize, $beatSize);
    }
    self::configure($noteSize, $beatSize);
  }
}
