<?php

namespace MADSCORE\Render;

/** Draws one horizontal prescription-sized dot for each section repeat. */
final class RepeatPainter {

  public static function width(int $count, float $rowHeight): float {
    if ($count === 0) {
      return 0;
    }
    $radius = Prescription::dotRadius($rowHeight);
    return $count * 2 * $radius + ($count - 1) * max(2, $radius / 5);
  }

  public static function draw(\FFI $cairo, \FFI\CData $context, float $right, float $y, float $rowHeight, int $count): void {
    $radius = Prescription::dotRadius($rowHeight);
    $gap = max(2, $radius / 5);
    $cairo->cairo_set_source_rgb($context, 0, 0, 0);
    for ($index = 0; $index < $count; $index++) {
      $x = $right - $radius - $index * (2 * $radius + $gap);
      $cairo->cairo_arc($context, $x, $y, $radius, 0, 2 * M_PI);
      $cairo->cairo_fill($context);
    }
  }

}
