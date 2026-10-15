<?php

namespace MADSCORE;

/** Keeps one melody's time selection anchored while its caret moves. */
final class MelodySelection {

  private ?int $anchor = null;
  private ?int $caret = null;
  private ?int $melody = null;

  public function extend(int $from, int $to, int $melody): void {
    if ($this->anchor === null || $this->melody !== $melody) {
      $this->anchor = $from;
      $this->melody = $melody;
    }
    $this->caret = $to;
  }

  public function clear(): void {
    $this->anchor = $this->caret = $this->melody = null;
  }

  /** Return both direction-aware endpoints and the normalized time span. */
  public function state(): ?array {
    if ($this->anchor === null || $this->caret === null || $this->caret === $this->anchor) {
      return null;
    }
    return [
      'anchor' => $this->anchor,
      'caret' => $this->caret,
      'start' => min($this->anchor, $this->caret),
      'end' => max($this->anchor, $this->caret),
      'melody' => $this->melody,
    ];
  }
}
