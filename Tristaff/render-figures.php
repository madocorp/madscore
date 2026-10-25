#!/usr/bin/env php
<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Render\BarPainter;
use MADSCORE\Render\Cairo;
use MADSCORE\Render\DynamicPainter;
use MADSCORE\Render\FigurePainter;
use MADSCORE\Render\FigureAppendix;
use MADSCORE\Render\FigureCatalog;
use MADSCORE\Render\Prescription;
use MADSCORE\Render\RepeatPainter;
use MADSCORE\Render\SectionPainter;
use MADSCORE\Render\SlurPainter;
use MADSCORE\Render\TempoPainter;
use MADSCORE\Score\Score;
use MADSCORE\Score\Project;

/** Rebuild the tutorial's notation images with madscore's own painters. */
final class TutorialFigures {

  private const COLOR = '#087c75';
  private static int $slurHeight = 160;
  private static int $slurBand = 4;

  /** Generate every figure used by the HTML guide. */
  public static function run(): void {
    if (!is_dir(__DIR__ . '/graphics') && !mkdir(__DIR__ . '/graphics', 0755, true)) {
      throw new RuntimeException('Could not create the tutorial graphics directory.');
    }
    self::render('pitch', 760, 330, self::drawPitch(...));
    self::render('rhythm', 920, 285, self::drawRhythm(...));
    self::render('rhythm-instruments', 600, 210, self::drawRhythmInstruments(...));
    self::render('section-repeat', 920, 235, self::drawSectionRepeat(...));
    self::render('octaves', 920, 550, self::drawOctaves(...));
    self::render('staccato', 260, 205, self::drawStaccato(...));
    self::render('slur', 260, 205, self::drawSlur(...));
    self::render('fermata', 260, 205, self::drawFermata(...));
    self::render('trill', 260, 205, self::drawTrill(...));
    self::render('double-trill', 260, 205, self::drawDoubleTrill(...));
    self::render('trill-down', 260, 205, self::drawTrillDown(...));
    self::render('double-trill-down', 260, 205, self::drawDoubleTrillDown(...));
    self::render('ornament', 920, 420, self::drawOrnament(...));
    self::render('tuplet', 920, 420, self::drawTuplet(...));
    self::render('appendix-formats', 920, 760, self::drawAppendixFormats(...));
    self::render('glissando', 260, 205, self::drawGlissando(...));
    self::render('crescendo', 420, 220, self::drawCrescendo(...));
    self::render('decrescendo', 420, 220, self::drawDecrescendo(...));
    self::render('dynamic-levels', 920, 220, self::drawDynamicLevels(...));
    self::render('dynamic-scope', 920, 410, self::drawDynamicScope(...));
    self::render('fingering', 920, 240, self::drawFingering(...));
    self::render('phrase', 920, 340, self::drawPhrase(...));
  }

  /** Create a white PNG and pass its Cairo context to a named drawing method. */
  private static function render(string $name, int $width, int $height, callable $draw): void {
    $cairo = Cairo::library();
    $surface = $cairo->cairo_image_surface_create(0, $width, $height);
    Cairo::check($surface);
    $context = $cairo->cairo_create($surface);
    try {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      $cairo->cairo_paint($context);
      $draw($cairo, $context);
      if ($cairo->cairo_status($context) !== 0
        || $cairo->cairo_surface_write_to_png($surface, __DIR__ . '/graphics/' . $name . '.png') !== 0) {
        throw new RuntimeException('Could not render tutorial figure: ' . $name);
      }
    } finally {
      $cairo->cairo_destroy($context);
      $cairo->cairo_surface_destroy($surface);
    }
  }

  /** Give a one-melody example the guide's teal ink. */
  private static function score(): Score {
    $score = new Score();
    $score->setMelodyColor(0, self::COLOR);
    return $score;
  }

  /** Draw a real bar, with optional slurs, inside the larger teaching image. */
  private static function bar(\FFI $cairo, \FFI\CData $context, Score $score, int $octave,
    int $x, int $y, int $width, int $height, bool $slots = false, bool $slur = false): void {
    $cairo->cairo_save($context);
    try {
      $cairo->cairo_translate($context, $x, $y);
      BarPainter::draw($cairo, $context, $score, 0, $octave, $width, $height,
        endBar: true, showSlots: $slots, paintBackground: false);
      if ($slur) {
        self::$slurHeight = $height;
        self::$slurBand = $octave;
        SlurPainter::draw($cairo, $context, $score,
          [['bar' => 0, 'left' => 0, 'right' => $width]], self::slurRows(...));
      }
    } finally {
      $cairo->cairo_restore($context);
    }
  }

  /** Give SlurPainter the same band geometry used by BarPainter. */
  private static function slurRows(int $bar): array {
    return [self::$slurBand => ['band' => self::$slurBand, 'top' => 0, 'height' => self::$slurHeight]];
  }

  /** Put each rendered note directly above its matching piano key. */
  private static function drawPitch(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $score->setBeats(0, 24);
    $slots = [2, 5, 8, 14, 17, 20, 23, 26, 32, 35, 38, 41];
    foreach ($slots as $pitch => $slot) {
      $score->place(0, 0, $slot, 4, $pitch + 1, 2);
    }
    self::bar($cairo, $context, $score, 4, 20, 55, 720, 240);
  }

  /** Show a half beat, full beats, and a two-beat note with an inner tick. */
  private static function drawRhythm(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $score->place(0, 0, 0, 4, 1, 2);
    $score->place(0, 0, 2, 4, 3, 1);
    $score->place(0, 0, 3, 4, 4, 4);
    $score->place(0, 0, 7, 4, 6, 1);
    self::bar($cairo, $context, $score, 4, 30, 25, 860, 240, slots: true);
  }

  /** Show hourglass-shaped hits with the same lengths as the pitched rhythm example. */
  private static function drawRhythmInstruments(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $score->setMelodyPattern(0, 'kick');
    $score->place(0, 0, 0, 4, 4, 2);
    $score->place(0, 0, 2, 4, 4, 1);
    $score->place(0, 0, 3, 4, 4, 4);
    $score->place(0, 0, 7, 4, 4, 1);
    self::bar($cairo, $context, $score, 4, 20, 20, 560, 165, slots: true);
  }

  /** Show a two-bar section with the renderer's section edges and repeat dot. */
  private static function drawSectionRepeat(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $score->ensureBar(1);
    $score->setSectionRepeats(0, 1);
    $score->place(0, 0, 0, 4, 4, 2);
    $score->place(0, 0, 4, 4, 6, 2);
    $score->place(0, 1, 0, 4, 7, 2);
    $score->place(0, 1, 4, 4, 4, 2);
    $row = ['top' => 35, 'height' => 160];
    foreach ([0, 1] as $bar) {
      $cairo->cairo_save($context);
      $cairo->cairo_translate($context, 70 + $bar * 390, $row['top']);
      BarPainter::draw($cairo, $context, $score, $bar, 4, 390, $row['height'],
        endBar: false, paintBackground: false, startBorder: $bar !== 0);
      $cairo->cairo_restore($context);
    }
    SectionPainter::edge($cairo, $context, [4 => $row], 70);
    SectionPainter::edge($cairo, $context, [4 => $row], 850, right: true);
    RepeatPainter::draw($cairo, $context, 842, 24, $row['height'], $score->sectionRepeats(1));
  }

  /** Show one melody moving through bands with real octave markers. */
  private static function drawOctaves(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    foreach ([2, 3, 4] as $index => $octave) {
      $score->place(0, 0, $index * 2, $octave, 4, 2);
    }
    foreach ([4, 3, 2] as $row => $octave) {
      $top = 15 + $row * 175;
      self::bar($cairo, $context, $score, $octave, 110, $top, 760, 160);
    }
    Prescription::drawMarkers($cairo, $context, [
      4 => ['top' => 15, 'height' => 160],
      3 => ['top' => 190, 'height' => 160],
      2 => ['top' => 365, 'height' => 160],
    ], 65);
  }

  /** Show the real centered staccato dot. */
  private static function drawStaccato(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $note = $score->place(0, 0, 2, 4, 4, 2);
    $score->setStaccato($note, true);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160);
  }

  /** Show a linked pair with SlurPainter's actual curve. */
  private static function drawSlur(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $first = $score->place(0, 0, 0, 4, 4, 2);
    $score->place(0, 0, 2, 4, 6, 2);
    $score->setSlurToNext($first, true);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160, slur: true);
  }

  /** Show the pointed end produced by a fermata. */
  private static function drawFermata(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $note = $score->place(0, 0, 0, 4, 4, 4);
    $score->setFermata($note, true);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160);
  }

  /** Show the renderer's one-semitone trill zigzag. */
  private static function drawTrill(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $note = $score->place(0, 0, 2, 4, 4, 2);
    $score->setTrill($note, 1);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160);
  }

  /** Show the renderer's two-semitone trill as two stacked zigzags. */
  private static function drawDoubleTrill(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $note = $score->place(0, 0, 2, 4, 4, 2);
    $score->setTrill($note, 2);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160);
  }

  /** Show the two downward alternatives with the same score renderer. */
  private static function drawTrillDown(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $note = $score->place(0, 0, 2, 4, 4, 2);
    $score->setTrill($note, -1);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160);
  }

  private static function drawDoubleTrillDown(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $note = $score->place(0, 0, 2, 4, 4, 2);
    $score->setTrill($note, -2);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160);
  }

  /** Compare one ornament reference with the notes represented by motif A. */
  private static function drawOrnament(\FFI $cairo, \FFI\CData $context): void {
    $written = self::score();
    $written->setBeats(0, 3);
    $note = $written->place(0, 0, 0, 3, 4, 6);
    $written->setFigure($note, ['type' => 'ornament', 'motif' => 'A']);
    self::bar($cairo, $context, $written, 3, 90, 25, 740, 160);
    FigurePainter::referenceMark($cairo, $context, 120, 800, 25 + 7 * 160 / 12,
      160 / 12, ['number' => 1], self::COLOR, false);

    $expanded = self::score();
    $expanded->setBeats(0, 3);
    $expanded->place(0, 0, 0, 3, 4, 2);
    $expanded->place(0, 0, 2, 3, 6, 2);
    $expanded->place(0, 0, 4, 3, 4, 2);
    self::bar($cairo, $context, $expanded, 3, 90, 225, 740, 160);
  }

  /** Show a simple tuplet whose final pitch changes its written outline. */
  private static function drawTuplet(\FFI $cairo, \FFI\CData $context): void {
    $written = self::score();
    $written->setBeats(0, 3);
    $note = $written->place(0, 0, 0, 3, 4, 6);
    $written->setFigure($note, ['type' => 'tuplet', 'middle' => 2, 'last' => 4]);
    self::bar($cairo, $context, $written, 3, 90, 25, 740, 160);

    $expanded = self::score();
    $expanded->setBeats(0, 3);
    $expanded->place(0, 0, 0, 3, 4, 2);
    $expanded->place(0, 0, 2, 3, 6, 2);
    $expanded->place(0, 0, 4, 3, 8, 2);
    self::bar($cairo, $context, $expanded, 3, 90, 225, 740, 160);
  }

  /** Reuse the printed appendix painter for its relative and absolute panels. */
  private static function drawAppendixFormats(\FFI $cairo, \FFI\CData $context): void {
    $project = new Project();
    $id = $project->create();
    $motif = new Score();
    $motif->setBeats(0, 3);
    $motif->place(0, 0, 0, 3, 4, 2);
    $motif->place(0, 0, 2, 3, 6, 2);
    $motif->place(0, 0, 4, 3, 4, 2);
    $project->setScore($id, $motif);

    $score = new Score();
    $ornament = $score->place(0, 0, 0, 3, 4, 2);
    $tuplet = $score->place(0, 0, 2, 3, 4, 2);
    $score->setFigure($ornament, ['type' => 'ornament', 'motif' => $id]);
    $score->setFigure($tuplet, ['type' => 'tuplet', 'motif' => $id]);
    $project->setScore('main', $score);
    $tile = FigureAppendix::pages(new FigureCatalog($score, $project))[0][0];
    $scale = min(2.0, 880 / $tile['spec']['width'], 720 / $tile['spec']['height']);
    $tile['x'] = (920 / $scale - $tile['spec']['width']) / 2;
    $tile['y'] = (760 / $scale - $tile['spec']['height']) / 2;
    $cairo->cairo_save($context);
    $cairo->cairo_scale($context, $scale, $scale);
    FigureAppendix::drawTiles($cairo, $context, [$tile]);
    $cairo->cairo_restore($context);
  }

  private static function drawCrescendo(\FFI $cairo, \FFI\CData $context): void {
    self::drawWedge($cairo, $context, 'crescendo');
  }

  private static function drawDecrescendo(\FFI $cairo, \FFI\CData $context): void {
    self::drawWedge($cairo, $context, 'decrescendo');
  }

  /** Draw the actual melody wedge above two notes. */
  private static function drawWedge(\FFI $cairo, \FFI\CData $context, string $direction): void {
    $score = self::score();
    $first = $score->place(0, 0, 0, 4, 4, 2);
    $score->place(0, 0, 6, 4, 6, 2);
    $score->setDynamicToNext($first, $direction);
    $positions = [['bar' => 0, 'left' => 20, 'right' => 400]];
    DynamicPainter::draw($cairo, $context, DynamicPainter::segments($score, $positions, 130), 52, 130);
    self::bar($cairo, $context, $score, 4, 20, 70, 380, 130);
  }

  /** Show each supported written volume level on its own note. */
  private static function drawDynamicLevels(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $score->setBeats(0, 8);
    foreach (['ppp', 'pp', 'p', 'mp', 'mf', 'f', 'ff', 'fff'] as $index => $level) {
      $note = $score->place(0, 0, $index * 2, 4, 4, 2);
      $score->setDynamicLevel($note, $level);
    }
    $positions = [['bar' => 0, 'left' => 60, 'right' => 860]];
    DynamicPainter::drawLabels($cairo, $context, DynamicPainter::labels($score, $positions, 130), 49, 130);
    self::bar($cairo, $context, $score, 4, 60, 70, 800, 130);
  }

  /** Compare a black shared level with an outlined level for one melody. */
  private static function drawDynamicScope(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $score->addMelody();
    $score->setMelodyColor(1, '#b9653b');
    $score->place(0, 0, 0, 4, 4, 2);
    $teal = $score->place(0, 0, 4, 4, 4, 2);
    $score->place(1, 0, 0, 5, 4, 2);
    $score->place(1, 0, 4, 5, 4, 2);
    $score->setScoreDynamicLevel(0, 'mf');
    $score->setDynamicLevel($teal, 'p');
    $positions = [['bar' => 0, 'left' => 80, 'right' => 840]];
    DynamicPainter::drawLabels($cairo, $context, DynamicPainter::labels($score, $positions, 130), 50, 130);
    self::bar($cairo, $context, $score, 5, 80, 85, 760, 130);
    self::bar($cairo, $context, $score, 4, 80, 245, 760, 130);
  }

  /** Place the printed finger number at the lower left or right of its note. */
  private static function drawFingering(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $left = $score->place(0, 0, 0, 4, 4, 2);
    $right = $score->place(0, 0, 4, 4, 6, 2);
    $score->setFinger($left, 'L2');
    $score->setFinger($right, 'R4');
    self::bar($cairo, $context, $score, 4, 80, 25, 760, 180);
  }

  /** Show the actual hollow diagonal for a glissando. */
  private static function drawGlissando(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $first = $score->place(0, 0, 0, 4, 4, 2);
    $score->place(0, 0, 2, 4, 8, 2);
    $score->setGlissandoToNext($first, true);
    self::bar($cairo, $context, $score, 4, 10, 25, 240, 160);
  }

  /** Finish with one rendered phrase, its octave marker, and its tempo mark. */
  private static function drawPhrase(\FFI $cairo, \FFI\CData $context): void {
    $score = self::score();
    $first = $score->place(0, 0, 0, 3, 4, 2);
    $score->place(0, 0, 2, 3, 6, 2);
    $score->place(0, 0, 4, 3, 8, 4);
    $score->setSlurToNext($first, true);
    TempoPainter::draw($cairo, $context, 28, 400, '120');
    self::bar($cairo, $context, $score, 3, 100, 80, 800, 230, slur: true);
    Prescription::drawMarkers($cairo, $context, [3 => ['top' => 80, 'height' => 230]], 65);
  }
}

TutorialFigures::run();
echo "Tutorial graphics rendered\n";
