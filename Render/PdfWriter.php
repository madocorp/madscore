<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Score;

/** Writes score geometry directly to a vector PDF. */
final class PdfWriter {

  /** Atomically export every score page with Cairo's vector PDF surface. */
  public static function write(string $path, Score $score): void {
    $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $cairo = Cairo::library();
    $surface = $cairo->cairo_pdf_surface_create($temp, PagePainter::A4_WIDTH_POINTS, PagePainter::A4_HEIGHT_POINTS);
    try {
      Cairo::check($surface);
      $context = $cairo->cairo_create($surface);
      try {
        $cairo->cairo_scale($context, PagePainter::A4_WIDTH_POINTS / PagePainter::WIDTH,
          PagePainter::A4_HEIGHT_POINTS / PagePainter::HEIGHT);
        PagePainter::drawPages($score, $cairo, $context);
        if ($cairo->cairo_status($context) !== 0) {
          throw new \RuntimeException('Cairo could not draw the PDF.');
        }
      } finally {
        $cairo->cairo_destroy($context);
      }
      $cairo->cairo_surface_finish($surface);
      Cairo::check($surface);
      if (!rename($temp, $path)) {
        throw new \RuntimeException('Could not write PDF: ' . $path);
      }
    } finally {
      $cairo->cairo_surface_destroy($surface);
      if (is_file($temp)) {
        unlink($temp);
      }
    }
  }
}
