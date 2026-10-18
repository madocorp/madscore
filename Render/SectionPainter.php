<?php

namespace MADSCORE\Render;

/** Draws section edges between the extreme note positions of the selected rows. */
final class SectionPainter {

  public static function edge(\FFI $cairo, \FFI\CData $context, array $rows, float $x, bool $right = false): void {
    // G-sharp is centered one pitch step above its row; its circle reaches one more step.
    $top = min(array_map(static fn (array $row): float => $row['top'] - $row['height'] / 6, $rows));
    // The lower overflow G-sharp is centered at step eleven; its circle reaches the row bottom.
    $bottom = max(array_map(static fn (array $row): float => $row['top'] + $row['height'], $rows));
    $width = BarPainter::staffLineWidth(reset($rows)['height']);
    $x += $right ? -$width / 2 : $width / 2;
    $cairo->cairo_set_source_rgb($context, 0.094, 0.125, 0.11);
    $cairo->cairo_set_line_width($context, $width);
    $cairo->cairo_move_to($context, $x, $top);
    $cairo->cairo_line_to($context, $x, $bottom);
    $cairo->cairo_stroke($context);
  }
}
