<?php

namespace MADSCORE\Render;

/** Reference stars and square-ended figure note bodies. */
final class FigurePainter {
  public static function star(\FFI $cairo, \FFI\CData $context, float $x, float $y,
    ?int $number, float $radius, string $color = '#000000'): void {
    $cairo->cairo_save($context);
    self::source($cairo, $context, $color);
    $cairo->cairo_new_path($context);
    for ($point = 0; $point < 10; $point++) {
      $angle = -M_PI / 2 + $point * M_PI / 5;
      $distance = $point % 2 === 0 ? $radius : $radius * 0.40;
      $px = $x + cos($angle) * $distance;
      $py = $y + sin($angle) * $distance;
      $point === 0 ? $cairo->cairo_move_to($context, $px, $py)
        : $cairo->cairo_line_to($context, $px, $py);
    }
    $cairo->cairo_close_path($context);
    $cairo->cairo_fill($context);
    if ($number !== null) {
      $cairo->cairo_select_font_face($context, 'Sans', 0, 1);
      $cairo->cairo_set_font_size($context, max(12, $radius * 1.3));
      $cairo->cairo_move_to($context, $x + $radius + 2, $y + $radius * 0.42);
      $cairo->cairo_show_text($context, (string)$number);
    }
    $cairo->cairo_restore($context);
  }

  /** Fit the full reference inside a note, or place it above a cramped PDF note. */
  public static function referenceMark(\FFI $cairo, \FFI\CData $context, float $left,
    float $right, float $y, float $step, array $reference, string $color,
    bool $blackKey, bool $outside = false, bool $print = false,
    bool $lift = false): void {
    if ($print) {
      $font = PdfLayout::markFontSize();
      $radius = $font / 2;
      $digits = strlen((string)$reference['number']);
      $width = 2 * $radius + 2 + $font * 0.62 * $digits;
      $center = ($left + $right - $width) / 2 + $radius;
      $ink = $blackKey && !$lift ? '#ffffff' : $color;
      self::star($cairo, $context, $center, $y, null, $radius, $ink);
      $cairo->cairo_save($context);
      self::source($cairo, $context, $ink);
      $cairo->cairo_select_font_face($context, 'Sans', 0, 1);
      $cairo->cairo_set_font_size($context, $font);
      $cairo->cairo_move_to($context, $center + $radius + 2, $y + $font * 0.35);
      $cairo->cairo_show_text($context, (string)$reference['number']);
      $cairo->cairo_restore($context);
      return;
    }
    if ($outside) {
      self::star($cairo, $context, $left + $step * 0.15, $y - $step * 0.85,
        $reference['number'], max(6, $step * 0.58), $color);
      return;
    }
    $width = max(1, $right - $left);
    $number = $reference['number'];
    $digits = strlen((string)$number);
    $radius = min($step * 0.62, $width * 0.22);
    $fontSize = min($step * 0.85, $width / (1.5 + 0.65 * $digits));
    $gap = max(0.5, $step * 0.06);
    $textWidth = $fontSize * 0.62 * $digits;
    $total = 2 * $radius + $gap + $textWidth;
    if ($total > $width) {
      $scale = $width / $total;
      $radius *= $scale;
      $fontSize *= $scale;
      $gap *= $scale;
      $textWidth *= $scale;
      $total = $width;
    }
    $ink = $blackKey ? '#ffffff' : $color;
    $center = $left + ($width - $total) / 2 + $radius;
    self::star($cairo, $context, $center, $y, null, $radius, $ink);
    $cairo->cairo_save($context);
    self::source($cairo, $context, $ink);
    $cairo->cairo_select_font_face($context, 'Sans', 0, 1);
    $cairo->cairo_set_font_size($context, $fontSize);
    $cairo->cairo_move_to($context, $center + $radius + $gap, $y + $fontSize * 0.35);
    $cairo->cairo_show_text($context, (string)$number);
    $cairo->cairo_restore($context);
  }

  /** A figure stays rectangular at one pitch and slopes with vertical ends otherwise. */
  public static function specialNote(\FFI $cairo, \FFI\CData $context, float $x1, float $y1,
    float $x2, float $y2, float $step, ?int $middle = null,
    string $color = '#000000', bool $blackKey = false, bool $print = false,
    bool $lift = false): void {
    $cairo->cairo_save($context);
    if ($blackKey) {
      self::source($cairo, $context, $color);
    } else {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
    }
    $cairo->cairo_new_path($context);
    $cairo->cairo_move_to($context, $x1, $y1 - $step);
    $cairo->cairo_line_to($context, $x2, $y2 - $step);
    $cairo->cairo_line_to($context, $x2, $y2 + $step);
    $cairo->cairo_line_to($context, $x1, $y1 + $step);
    $cairo->cairo_close_path($context);
    $cairo->cairo_fill_preserve($context);
    self::source($cairo, $context, $color);
    $cairo->cairo_set_line_width($context, max(1, $step / 5));
    $cairo->cairo_stroke($context);
    if ($middle !== null) {
      if ($blackKey && !$lift) {
        $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      }
      $label = sprintf('%+d', $middle);
      $cairo->cairo_select_font_face($context, 'Sans', 0, 1);
      $font = $print ? PdfLayout::markFontSize() : max(12, $step);
      $cairo->cairo_set_font_size($context, $font);
      $centerY = $lift ? min($y1, $y2) - $step - $font * 0.63
        : ($y1 + $y2) / 2;
      $cairo->cairo_move_to($context, ($x1 + $x2) / 2 - strlen($label) * $font * 0.3,
        $centerY + $font * 0.33);
      $cairo->cairo_show_text($context, $label);
    }
    $cairo->cairo_restore($context);
  }

  private static function source(\FFI $cairo, \FFI\CData $context, string $color): void {
    $rgb = sscanf($color, '#%02x%02x%02x');
    $cairo->cairo_set_source_rgb($context, $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
  }
}
