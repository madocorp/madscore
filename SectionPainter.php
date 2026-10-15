<?php

namespace MADSCORE;

/** Draws section edges between the extreme note positions of the selected rows. */
final class SectionPainter {

  public static function edge(\FFI $cairo, \FFI\CData $context, array $rows, float $x): void {
    // G-sharp is centered one pitch step above its row; its circle reaches one more step.
    $top = min(array_map(static fn (array $row): float => $row['top'] - $row['height'] / 6, $rows));
    // A is centered ten steps below its row top; its circle reaches the eleventh step.
    $bottom = max(array_map(static fn (array $row): float => $row['top'] + 11 * $row['height'] / 12, $rows));
    $cairo->cairo_set_source_rgb($context, 0.094, 0.125, 0.11);
    $cairo->cairo_set_line_width($context, 2);
    $cairo->cairo_move_to($context, $x, $top);
    $cairo->cairo_line_to($context, $x, $bottom);
    $cairo->cairo_stroke($context);
  }
}
