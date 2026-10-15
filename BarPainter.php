<?php

namespace MADSCORE;

use SPTK\Core\RasterImage;

/** Draws one octave of one bar with continuous vector pitch geometry. */
final class BarPainter {

  /** Return the saved display color for a melody. */
  public static function color(Score $score, int $melody): string {
    return $score->melodies[$melody]['color'];
  }

  /** Return octave tiles touched by this note's circular pitch position. */
  public static function visibleOctaves(array $note): array {
    $pitch = Pitch::position($note);
    $visible = [];
    for ($octave = 8; $octave >= 0; $octave--) {
      $center = ($octave - $note['octave']) * 12 + 11 - $pitch;
      if (($center + 1 > 0 && $center - 1 < 12) || ($octave === 8 && $center === -1)) {
        $visible[] = $octave;
      }
    }
    return $visible;
  }

  /** Rasterize a tile at its actual screen size. */
  public static function render(Score $score, int $bar, int $octave, int $width, int $height, ?array $cursor = null, ?int $melody = null, bool $endBar = false): RasterImage {
    $width = max(1, $width);
    $height = max(1, $height);
    $cairo = Cairo::library();
    $surface = $cairo->cairo_image_surface_create(0, $width, $height);
    Cairo::check($surface);
    $context = $cairo->cairo_create($surface);
    try {
      self::draw($cairo, $context, $score, $bar, $octave, $width, $height, $cursor, $melody, $endBar, true);
      if ($cairo->cairo_status($context) !== 0) {
        throw new \RuntimeException('Cairo could not draw a bar tile.');
      }
      return Cairo::raster($surface, $width, $height);
    } finally {
      $cairo->cairo_destroy($context);
      $cairo->cairo_surface_destroy($surface);
    }
  }

  /** Draw staff lines, notes, and an optional cursor into a Cairo context. */
  public static function draw(\FFI $cairo, \FFI\CData $context, Score $score, int $bar, int $octave, int $width, int $height, ?array $cursor = null, ?int $melody = null, bool $endBar = false, bool $showSlots = false, bool $paintBackground = true): void {
    $step = $height / 12.0;
    $firstLine = $step;
    $thirdLine = 9 * $step;
    $slots = 2 * $score->beats($bar);
    $unit = $width / $slots;
    if ($paintBackground) {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      $cairo->cairo_paint($context);
    }
    if ($showSlots) {
      $cairo->cairo_set_source_rgb($context, 0.86, 0.89, 0.87);
      $cairo->cairo_set_line_width($context, 1);
      for ($slot = 1; $slot < $slots; $slot++) {
        $x = $slot * $unit;
        self::line($cairo, $context, $x, $firstLine, $x, $thirdLine);
      }
    }
    $cairo->cairo_set_source_rgb($context, 0.094, 0.125, 0.11);
    $staffWidth = max(1, $step / 8);
    $cairo->cairo_set_line_width($context, $staffWidth);
    foreach ([1, 5, 9] as $position) {
      self::line($cairo, $context, 0, $position * $step, $width, $position * $step);
    }
    self::line($cairo, $context, $staffWidth / 2, $firstLine, $staffWidth / 2, $thirdLine);
    if ($endBar) {
      self::line($cairo, $context, $width - $staffWidth / 2, $firstLine, $width - $staffWidth / 2, $thirdLine);
    }
    $barStart = $score->start($bar);
    $barEnd = $barStart + $slots;
    foreach ($score->notes as $note) {
      $start = $score->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      if ($end <= $barStart || $start >= $barEnd) {
        continue;
      }
      $pitch = Pitch::position($note);
      $y = ($octave - $note['octave']) * $height + (11 - $pitch) * $step;
      if ($y + $step < 0 || $y - $step >= $height) {
        continue;
      }
      $offset = $note['length'] === 1 ? 0.5 : 1;
      $x1 = ($start - $barStart + $offset) * $unit;
      $x2 = ($end - $barStart - $offset) * $unit;
      $blackKey = Pitch::isBlack($pitch);
      if ($note['length'] === 1) {
        self::halfNote($cairo, $context, $note, $blackKey, $x1, $y, 2 * $step, self::color($score, $note['melody']));
        continue;
      }
      self::note($cairo, $context, $note, $blackKey, $x1, $x2, $y, 2 * $step, self::color($score, $note['melody']));
      self::ticks($cairo, $context, $note, $blackKey, $start, $end, $barStart, $barEnd, $unit, $y, 2 * $step, self::color($score, $note['melody']));
    }
    if ($cursor !== null && $melody !== null) {
      self::cursor($cairo, $context, $cursor, self::color($score, $melody), $barStart, $barEnd, $octave, $unit, $width, $height);
    }
  }

  /** Draw H sides from A to the G-sharp in the octave below. */
  private static function cursor(\FFI $cairo, \FFI\CData $context, array $state, string $color, int $barStart, int $barEnd, int $octave, float $unit, int $width, int $height): void {
    $start = $state['start'];
    $end = $state['end'];
    if ($end < $barStart || $start > $barEnd) {
      return;
    }
    $left = max(0, ($start - $barStart) * $unit);
    $right = min($width, ($end - $barStart) * $unit);
    $offset = ($octave - $state['octave']) * $height;
    $pitchStep = $height / 12;
    $spanTop = $offset + ($state['pitch'] === 0 ? 11 * $pitchStep : -1 * $pitchStep);
    $top = max(0, $spanTop);
    $bottom = min($height, $spanTop + 11 * $pitchStep);
    if ($bottom <= $top) {
      return;
    }
    $pitchY = $offset + (11 - $state['pitch']) * $height / 12;
    self::source($cairo, $context, $color);
    $cairo->cairo_set_line_width($context, 2);
    if ($left < $right && $pitchY >= 0 && $pitchY <= $height) {
      self::line($cairo, $context, $left, $pitchY, $right, $pitchY);
    }
    if ($start >= $barStart && $start <= $barEnd) {
      $x = max(0.5, min($width - 0.5, ($start - $barStart) * $unit));
      self::line($cairo, $context, $x, $top, $x, $bottom);
    }
    if ($end >= $barStart && $end <= $barEnd) {
      $x = max(0.5, min($width - 0.5, ($end - $barStart) * $unit));
      self::line($cairo, $context, $x, $top, $x, $bottom);
    }
  }

  /** Draw one hollow or filled circular note and its duration body. */
  private static function note(\FFI $cairo, \FFI\CData $context, array $note, bool $blackKey, float $x1, float $x2, float $y, float $size, string $color): void {
    $outline = max(1, $size / 8);
    $radius = max(0.25, ($size - $outline) / 2);
    if ($x1 === $x2) {
      $cairo->cairo_arc($context, $x1, $y, $radius, 0, 2 * M_PI);
    } else {
      $cairo->cairo_move_to($context, $x1, $y - $radius);
      $cairo->cairo_line_to($context, $x2, $y - $radius);
      $cairo->cairo_arc($context, $x2, $y, $radius, -M_PI / 2, M_PI / 2);
      $cairo->cairo_line_to($context, $x1, $y + $radius);
      $cairo->cairo_arc($context, $x1, $y, $radius, M_PI / 2, 3 * M_PI / 2);
      $cairo->cairo_close_path($context);
    }
    if (!$blackKey) {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
    } else {
      self::source($cairo, $context, $color);
    }
    $cairo->cairo_fill_preserve($context);
    self::source($cairo, $context, $color);
    $cairo->cairo_set_line_width($context, $outline);
    $cairo->cairo_stroke($context);
  }

  /** Draw a one-position note as a right semicircle with a straight left edge. */
  private static function halfNote(\FFI $cairo, \FFI\CData $context, array $note, bool $blackKey, float $x, float $y, float $size, string $color): void {
    $outline = max(1, $size / 8);
    $radius = max(0.25, ($size - $outline) / 2);
    $cairo->cairo_move_to($context, $x, $y - $radius);
    $cairo->cairo_arc($context, $x, $y, $radius, -M_PI / 2, M_PI / 2);
    $cairo->cairo_close_path($context);
    if ($blackKey) {
      self::source($cairo, $context, $color);
    } else {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
    }
    $cairo->cairo_fill_preserve($context);
    self::source($cairo, $context, $color);
    $cairo->cairo_set_line_width($context, $outline);
    $cairo->cairo_stroke($context);
  }

  /** Draw internal beat marks inside a long note. */
  private static function ticks(\FFI $cairo, \FFI\CData $context, array $note, bool $blackKey, int $start, int $end, int $barStart, int $barEnd, float $unit, float $y, float $size, string $color): void {
    $cairo->cairo_set_line_width($context, max(1, $size / 8));
    if (!$blackKey) {
      self::source($cairo, $context, $color);
    } else {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
    }
    for ($tick = $start + 2; $tick < $end; $tick += 2) {
      if ($tick < $barStart || $tick > $barEnd) {
        continue;
      }
      $x = ($tick - $barStart) * $unit;
      self::line($cairo, $context, $x, $y - $size / 3, $x, $y + $size / 3);
    }
  }

  /** Set a note's RGB source color. */
  private static function source(\FFI $cairo, \FFI\CData $context, string $hex): void {
    $rgb = sscanf($hex, '#%02x%02x%02x');
    $cairo->cairo_set_source_rgb($context, $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
  }

  /** Stroke one independent straight segment. */
  private static function line(\FFI $cairo, \FFI\CData $context, float $x1, float $y1, float $x2, float $y2): void {
    $cairo->cairo_move_to($context, $x1, $y1);
    $cairo->cairo_line_to($context, $x2, $y2);
    $cairo->cairo_stroke($context);
  }
}
