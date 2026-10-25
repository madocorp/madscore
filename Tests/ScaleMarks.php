<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Render\BarPainter;
use MADSCORE\Render\PagePreview;
use MADSCORE\Score\HarmonicContext;
use MADSCORE\Score\Score;

function expectScaleMark(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new Score();
$score->place(0, 0, 0, 4, 5, 2); // C-sharp
$plain = BarPainter::render($score, 0, 4, 120, 96)->pixels;
$majorC = ['scale' => 'Major', 'homeNote' => 'C'];
$majorCSharp = ['scale' => 'Major', 'homeNote' => 'C#'];
expectScaleMark(!HarmonicContext::containsNote($majorC, $score->notes[0])
  && HarmonicContext::containsNote($majorCSharp, $score->notes[0]),
  'Scale membership accounts for the home note.');
$score->setSectionMusic(0, $majorCSharp);
expectScaleMark(BarPainter::render($score, 0, 4, 120, 96)->pixels === $plain,
  'A note in the selected scale has no extra mark.');
$score->setSectionMusic(0, $majorC);
$marked = BarPainter::render($score, 0, 4, 120, 96)->pixels;
expectScaleMark($marked !== $plain, 'An out-of-scale note receives a mark.');
$aboveRight = false;
$changedElsewhere = false;
for ($y = 0; $y < 96; $y++) {
  for ($x = 0; $x < 120; $x++) {
    $offset = ($y * 120 + $x) * 4;
    if (substr($marked, $offset, 4) === substr($plain, $offset, 4)) {
      continue;
    }
    if ($x >= 14 && $x <= 28 && $y >= 25 && $y < 48) {
      $aboveRight = true;
    } else {
      $changedElsewhere = true;
    }
  }
}
expectScaleMark($aboveRight && !$changedElsewhere,
  'The exclamation mark stays above the note’s right corner without changing its head.');
$score->setSectionMusic(0, null);
expectScaleMark(BarPainter::render($score, 0, 4, 120, 96)->pixels === $plain,
  'Removing the selected key removes the mark.');

$score->setSectionMusic(0, $majorC);
$pageMarked = PagePreview::render($score, 0, 842, 595)->pixels;
$score->setSectionMusic(0, null);
expectScaleMark(PagePreview::render($score, 0, 842, 595)->pixels !== $pageMarked,
  'The printed score includes out-of-scale marks.');

$changes = new Score();
for ($bar = 0; $bar < 3; $bar++) {
  $changes->place(0, $bar, 0, 4, 5, 2);
}
$plainBars = [];
for ($bar = 0; $bar < 3; $bar++) {
  $plainBars[$bar] = BarPainter::render($changes, $bar, 4, 120, 96)->pixels;
}
$changes->setSectionMusic(0, $majorCSharp);
$changes->setSectionMusic(1, $majorC);
$changes->setSectionMusic(2, null);
expectScaleMark(!$changes->bars[1]['sectionLeader']
  && BarPainter::render($changes, 0, 4, 120, 96)->pixels === $plainBars[0]
  && BarPainter::render($changes, 1, 4, 120, 96)->pixels !== $plainBars[1]
  && BarPainter::render($changes, 2, 4, 120, 96)->pixels === $plainBars[2],
  'Marks follow key changes on ordinary bars and disappear after a clear change.');

$spanning = new Score();
$spanning->ensureBar(1);
$spanning->setSectionLeader(1, true);
$spanning->place(0, 0, 7, 4, 5, 2);
$spanning->setSectionMusic(0, $majorCSharp);
$spanning->setSectionMusic(1, $majorC);
$unmarkedStart = BarPainter::render($spanning, 0, 4, 120, 96)->pixels;
$unmarkedEnd = BarPainter::render($spanning, 1, 4, 120, 96)->pixels;
$spanning->setSectionMusic(0, $majorC);
expectScaleMark(BarPainter::render($spanning, 0, 4, 120, 96)->pixels === $unmarkedStart
  && BarPainter::render($spanning, 1, 4, 120, 96)->pixels !== $unmarkedEnd,
  'A sustained note gets one mark at its end, using the key at its onset.');

$drums = new Score();
$drums->setMelodyPattern(0, 'kick');
$drums->place(0, 0, 0, 4, 5, 2);
$drumPlain = BarPainter::render($drums, 0, 4, 120, 96)->pixels;
$drums->setSectionMusic(0, $majorC);
expectScaleMark(BarPainter::render($drums, 0, 4, 120, 96)->pixels === $drumPlain,
  'Pitchless drum hits do not receive scale marks.');

echo "Scale mark checks passed\n";
