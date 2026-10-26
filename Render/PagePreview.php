<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Score;
use MADSCORE\Score\Project;

use SPTK\Core\{RasterImage, Style};

/** Rasterizes a PDF page using the PDF painter itself. */
final class PagePreview {
  public static function render(Score $score, int $page, int $width, int $height, ?Project $project = null): RasterImage {
    $width = max(1, $width);
    $height = max(1, $height);
    $cairo = Cairo::library();
    $surface = PdfLayout::withProject($project,
      static fn (): \FFI\CData => self::surface($score, $page, $width, $height, $project));
    try {
      return Cairo::raster($surface, $width, $height);
    } finally {
      $cairo->cairo_surface_destroy($surface);
    }
  }

  /** Give SPTK's image viewer a detailed page without a second pixel conversion. */
  public static function writePng(Score $score, int $page, string $path, int $width, int $height, ?Project $project = null): void {
    $cairo = Cairo::library();
    $surface = PdfLayout::withProject($project,
      static fn (): \FFI\CData => self::surface($score, $page,
        max(1, $width), max(1, $height), $project));
    try {
      if ($cairo->cairo_surface_write_to_png($surface, $path) !== 0) {
        throw new \RuntimeException('Could not write the PDF preview image.');
      }
    } finally {
      $cairo->cairo_surface_destroy($surface);
    }
  }

  private static function surface(Score $score, int $page, int $width, int $height, ?Project $project): \FFI\CData {
    $width = max(1, $width);
    $height = max(1, $height);
    $score = $score->visibleCopy()->renderCopy();
    $figures = new FigureCatalog($score, $project);
    $states = Prescription::states($score, figures: $figures);
    $pages = PagePainter::pages($score, $states);
    [$inlineTiles, $appendixPages] = PagePainter::appendixPlacement($pages, $states, $figures);
    $pageCount = count($pages) + count($appendixPages);
    $page = max(0, min($pageCount - 1, $page));
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
      if ($page < count($pages)) {
        PagePainter::drawPage($score, $cairo, $context, $pages[$page], $states,
          pageNumber: $page + 1, pageCount: count($pages), figures: $figures,
          inlineTiles: $page === 0 ? $inlineTiles : []);
      } else {
        FigureAppendix::drawPage($cairo, $context, $appendixPages[$page - count($pages)]);
        PagePainter::drawHeader($score, $cairo, $context, $page + 1,
          count($pages), $page - count($pages) + 1);
      }
      $cairo->cairo_restore($context);
      if ($cairo->cairo_status($context) !== 0) {
        throw new \RuntimeException('Cairo could not draw the PDF preview.');
      }
      return $surface;
    } catch (\Throwable $error) {
      $cairo->cairo_surface_destroy($surface);
      throw $error;
    } finally {
      $cairo->cairo_destroy($context);
    }
  }
}
