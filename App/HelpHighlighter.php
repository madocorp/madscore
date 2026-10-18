<?php

namespace MADSCORE\App;

use SPTK\Core\SyntaxHighlighter;
use SPTK\Rendering\TextMetrics;

/** Color shortcut keys at the start of each help line. */
final class HelpHighlighter implements SyntaxHighlighter {

  public function highlight(array $lines): array {
    return array_map(static function (string $line): array {
      if (!preg_match('/^\S+/u', $line, $match)) {
        return [];
      }
      return [['start' => 0, 'length' => TextMetrics::length($match[0]), 'style' => 'key']];
    }, $lines);
  }

  public function styleColors(): array {
    return ['key' => ['fg' => '#80cbc4']];
  }

}
