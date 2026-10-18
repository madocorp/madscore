<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Score;

use SPTK\Core\{RasterImage, Style};

/** Rasterizes a PDF page using the PDF painter itself. */
final class PagePreview {
  public static function render(Score $score, int $page, int $width, int $height): RasterImage {
    $width = max(1, $width);
    $height = max(1, $height);
    $pages = PagePainter::pages($score);
    $page = max(0, min(count($pages) - 1, $page));
    $cairo = Cairo::library();
    $surface = $cairo->cairo_image_surface_create(0, $width, $height);
    Cairo::check($surface);
    $context = $cairo->cairo_create($surface);
    try {
      $scale = min($width / PagePainter::A4_WIDTH_POINTS, $height / PagePainter::A4_HEIGHT_POINTS);
      $pageWidth = PagePainter::A4_WIDTH_POINTS * $scale;
      $pageHeight = PagePainter::A4_HEIGHT_POINTS * $scale;
      $background = (new Style())->background;
      $cairo->cairo_set_source_rgb($context, $background->r / 255, $background->g / 255, $background->b / 255);
      $cairo->cairo_paint($context);
      $cairo->cairo_save($context);
      $cairo->cairo_translate($context, ($width - $pageWidth) / 2, ($height - $pageHeight) / 2);
      $cairo->cairo_scale($context, $pageWidth / PagePainter::WIDTH, $pageHeight / PagePainter::HEIGHT);
      $cairo->cairo_rectangle($context, 0, 0, PagePainter::WIDTH, PagePainter::HEIGHT);
      $cairo->cairo_clip($context);
      PagePainter::drawPage($score, $cairo, $context, $pages[$page], pageNumber: $page + 1, pageCount: count($pages));
      $cairo->cairo_restore($context);
      if ($cairo->cairo_status($context) !== 0) {
        throw new \RuntimeException('Cairo could not draw the PDF preview.');
      }
      return Cairo::raster($surface, $width, $height);
    } finally {
      $cairo->cairo_destroy($context);
      $cairo->cairo_surface_destroy($surface);
    }
  }
}
