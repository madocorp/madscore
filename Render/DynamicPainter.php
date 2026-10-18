<?php

namespace MADSCORE\Render;

use MADSCORE\Play\Drum;
use MADSCORE\Score\Dynamics;
use MADSCORE\Score\Score;

/** Draws colored volume triangles and levels on the dynamics row. */
final class DynamicPainter {

  public static function labels(Score $score, array $positions, float $rowHeight): array {
    if ($positions === []) {
      return [];
    }
    $outgoing = [];
    $inWedge = [];
    foreach (Dynamics::groups($score) as $group) {
      $outgoing[$group['first']] = true;
      foreach ($group['indices'] as $index) {
        $inWedge[$index] = true;
      }
    }
    $fontSize = max(12, $rowHeight / 7.5);
    $labels = [];
    foreach ($score->notes as $index => $note) {
      $mark = $note['dynamicLevel'] ?? '';
      if ($mark === '') {
        continue;
      }
      $left = self::leftEdge($score, $positions, $note, Prescription::dotRadius($rowHeight));
      $right = self::rightEdge($score, $positions, $note, Prescription::dotRadius($rowHeight));
      if ($left === null || $right === null) {
        continue;
      }
      $barPosition = null;
      foreach ($positions as $position) {
        if ($position['bar'] === $note['bar']) {
          $barPosition = $position;
          break;
        }
      }
      if ($barPosition === null) {
        continue;
      }
      $width = strlen($mark) * $fontSize * 0.63;
      if (!isset($inWedge[$index])) {
        $x = self::timeX($score, $positions, $score->start($note['bar']) + $note['slot']);
      } else {
        $x = isset($outgoing[$index]) ? $left - $width - 5 : $right + 5;
        $x = max($barPosition['left'], min($barPosition['right'] - $width, $x));
      }
      $labels[] = ['x' => $x, 'text' => $mark,
        'color' => BarPainter::color($score, $note['melody'])];
    }
    return $labels;
  }

  public static function drawLabels(\FFI $cairo, \FFI\CData $context, array $labels, float $baseline, float $rowHeight): void {
    $cairo->cairo_select_font_face($context, 'Sans', 0, 0);
    $cairo->cairo_set_font_size($context, max(12, $rowHeight / 7.5));
    foreach ($labels as $label) {
      $rgb = sscanf($label['color'], '#%02x%02x%02x');
      $cairo->cairo_set_source_rgb($context, $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
      $cairo->cairo_move_to($context, $label['x'], $baseline);
      $cairo->cairo_show_text($context, $label['text']);
    }
  }

  public static function segments(Score $score, array $positions, float $rowHeight): array {
    if ($positions === []) {
      return [];
    }
    $firstBar = $positions[0]['bar'];
    $lastBar = $positions[count($positions) - 1]['bar'];
    $lineStart = $score->start($firstBar);
    $lineEnd = $score->start($lastBar) + 2 * $score->beats($lastBar);
    $radius = Prescription::dotRadius($rowHeight);
    $segments = [];
    foreach (Dynamics::groups($score) as $group) {
      if ($group['end'] <= $lineStart || $group['start'] >= $lineEnd) {
        continue;
      }
      $first = $score->notes[$group['first']];
      $last = $score->notes[$group['last']];
      $left = $group['start'] < $lineStart ? $positions[0]['left']
        : self::leftEdge($score, $positions, $first, $radius);
      $right = $group['end'] > $lineEnd ? $positions[count($positions) - 1]['right']
        : self::rightEdge($score, $positions, $last, $radius);
      if ($left === null || $right === null || $right <= $left + 2) {
        continue;
      }
      $duration = $group['end'] - $group['start'];
      $from = max($lineStart, $group['start']);
      $to = min($lineEnd, $group['end']);
      $rise = $group['direction'] === 'crescendo';
      $segments[] = [
        'left' => $left, 'right' => $right,
        'leftHeight' => $rise ? ($from - $group['start']) / $duration : ($group['end'] - $from) / $duration,
        'rightHeight' => $rise ? ($to - $group['start']) / $duration : ($group['end'] - $to) / $duration,
        'color' => BarPainter::color($score, $group['melody']),
      ];
    }
    return $segments;
  }

  public static function draw(\FFI $cairo, \FFI\CData $context, array $segments, float $bottom, float $rowHeight): void {
    $height = 2 * Prescription::dotRadius($rowHeight);
    $cairo->cairo_set_line_width($context, max(1, $rowHeight / 130));
    foreach ($segments as $segment) {
      $rgb = sscanf($segment['color'], '#%02x%02x%02x');
      $cairo->cairo_set_source_rgb($context, $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
      $left = $segment['left'];
      $right = $segment['right'];
      $cairo->cairo_new_path($context);
      $cairo->cairo_move_to($context, $left, $bottom);
      $cairo->cairo_line_to($context, $right, $bottom);
      $cairo->cairo_line_to($context, $right, $bottom - $height * $segment['rightHeight']);
      $cairo->cairo_line_to($context, $left, $bottom - $height * $segment['leftHeight']);
      $cairo->cairo_close_path($context);
      $cairo->cairo_stroke($context);
    }
  }

  private static function leftEdge(Score $score, array $positions, array $note, float $radius): ?float {
    $time = $score->start($note['bar']) + $note['slot'] + ($note['length'] === 1 ? 0.5 : 1);
    $x = self::timeX($score, $positions, $time);
    if ($x === null) {
      return null;
    }
    if ($note['length'] === 1) {
      return $x;
    }
    return $x - $radius * (self::pitchless($score, $note) && $note['length'] === 2 ? 4 / 3 : 1);
  }

  private static function rightEdge(Score $score, array $positions, array $note, float $radius): ?float {
    $time = $score->start($note['bar']) + $note['slot'] + $note['length']
      - ($note['length'] === 1 ? 0.5 : 1);
    $x = self::timeX($score, $positions, $time);
    if ($x === null) {
      return null;
    }
    return $x + $radius * (self::pitchless($score, $note) && $note['length'] === 2 ? 4 / 3 : 1);
  }

  private static function pitchless(Score $score, array $note): bool {
    return in_array($score->melodies[$note['melody']]['pattern'], Drum::NAMES, true);
  }

  private static function timeX(Score $score, array $positions, float $time): ?float {
    foreach ($positions as $position) {
      $bar = $position['bar'];
      $start = $score->start($bar);
      $slots = 2 * $score->beats($bar);
      if ($time >= $start && $time < $start + $slots) {
        return $position['left'] + ($time - $start) * ($position['right'] - $position['left']) / $slots;
      }
    }
    $last = $positions[count($positions) - 1];
    return $time == $score->start($last['bar']) + 2 * $score->beats($last['bar']) ? $last['right'] : null;
  }

}
