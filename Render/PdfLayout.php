<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Project;

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

  /** Match the 32-unit page-number text used by the PDF header. */
  public static function markFontSize(): float {
    return 32;
  }

  public static function configure(int $noteSize, int $beatSize): void {
    self::$noteSize = max(50, min(140, $noteSize));
    self::$beatSize = max(self::$noteSize, min(200, $beatSize));
  }

  public static function change(int $noteDelta, int $beatDelta): void {
    $noteSize = max(50, min(140, self::$noteSize + $noteDelta));
    $beatSize = max(50, min(200, self::$beatSize + $beatDelta));
    if ($noteDelta > 0) {
      $beatSize = max($beatSize, $noteSize);
    } else if ($beatDelta < 0) {
      $noteSize = min($noteSize, $beatSize);
    }
    self::configure($noteSize, $beatSize);
  }

  /** Render another project with its own sizes without changing the open document. */
  public static function withProject(?Project $project, callable $render): mixed {
    if ($project === null) {
      return $render();
    }
    $previous = [self::$noteSize, self::$beatSize];
    $layout = $project->pdfLayout();
    self::configure($layout['noteSize'], $layout['beatSize']);
    try {
      return $render();
    } finally {
      self::configure(...$previous);
    }
  }
}
