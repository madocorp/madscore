<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectNavigation(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$singleMelody = new MADSCORE\Score\Score();
$controllerScore = new \ReflectionProperty(MADSCORE\App\Controller::class, 'score');
$controllerMelody = new \ReflectionProperty(MADSCORE\App\Controller::class, 'melody');
$controllerScore->setValue(null, $singleMelody);
$controllerMelody->setValue(null, 0);
MADSCORE\App\Controller::moveMelody(1);
MADSCORE\App\Controller::moveMelody(-1);
expectNavigation($controllerMelody->getValue() === 0 && count($singleMelody->melodies) === 1,
  'Tab and Shift+Tab stay on the only melody without creating one.');
$singleMelody->addMelody();
$controllerMelody->setValue(null, 1);
MADSCORE\App\Controller::moveMelody(1);
expectNavigation($controllerMelody->getValue() === 1, 'Tab stops at the last existing melody.');
$controllerMelody->setValue(null, 0);
MADSCORE\App\Controller::moveMelody(-1);
expectNavigation($controllerMelody->getValue() === 0, 'Shift+Tab stops at the first melody.');

$score = new MADSCORE\Score\Score(12);
$score->put(0, 0, 0, 1, 'C', 2);
$score->put(0, 0, 3, 1, 'D', 2);
expectNavigation($score->moveCursor(0, 0, 0, 1) === [0, 2], 'Right reaches a one-half-beat gap.');
expectNavigation($score->moveCursor(0, 0, 3, -1) === [0, 2], 'Left reaches the same gap.');
expectNavigation($score->moveCursor(0, 0, 2, 1) === [0, 3], 'Right enters the next note at its onset.');
expectNavigation($score->freeLength(0, 0, 2, 4) === 1, 'The cursor width fits the gap.');
expectNavigation($score->freeLength(1, 0, 2, 4) === 4, 'Another melody does not limit the cursor.');

$merged = new MADSCORE\Score\Score(12);
$merged->put(0, 0, 0, 1, 'C', 2);
$next = $merged->put(0, 0, 6, 1, 'D', 2);
expectNavigation($merged->moveCursor(0, 0, 0, 1) === [0, 2], 'Right selects the start of a multi-position gap.');
expectNavigation($merged->moveCursor(0, 0, 2, 1) === [0, 6], 'One more Right skips the whole gap.');
expectNavigation($merged->moveCursor(0, 0, 6, -1) === [0, 2], 'Left from the next note selects the whole gap.');
expectNavigation($merged->moveCursor(0, 0, 2, -1) === [0, 0], 'Left from a gap reaches the preceding note.');
expectNavigation($merged->freeLength(0, 0, 2, 1) === 4, 'A bounded gap shows its full width regardless of the previous cursor width.');
$merged->resizeGapAndShift(0, 0, 2, 4, 5);
expectNavigation($merged->notes[$next]['slot'] === 7 && $merged->freeLength(0, 0, 2, 1) === 5, 'Resizing a selected gap moves the next note and updates the whole gap width.');
$merged->resizeGapAndShift(0, 0, 2, 5, 0);
expectNavigation($merged->notes[$next]['slot'] === 2, 'Deleting a selected gap removes all its empty time.');

$gap = new MADSCORE\Score\Score(12);
$gap->put(0, 0, 0, 1, 'C', 2);
$next = $gap->put(0, 0, 3, 1, 'D', 2);
$later = $gap->put(0, 0, 6, 1, 'E', 2);
$other = $gap->put(1, 0, 3, 1, 'F', 2);
$gap->resizeGapAndShift(0, 0, 2, 1, 2);
expectNavigation($gap->notes[$next]['slot'] === 4 && $gap->notes[$later]['slot'] === 7, 'Widening an empty cursor moves all later notes in its melody.');
expectNavigation($gap->notes[$other]['slot'] === 3, 'Widening an empty cursor leaves another melody in place.');
$gap->resizeGapAndShift(0, 0, 2, 2, 1);
expectNavigation($gap->notes[$next]['slot'] === 3 && $gap->notes[$later]['slot'] === 6, 'Shortening an empty cursor pulls later notes back by the width change.');

$wideGap = new MADSCORE\Score\Score(12);
$later = $wideGap->put(0, 1, 0, 1, 'C', 2);
$wideGap->resizeGapAndShift(0, 0, 4, 2, 3);
expectNavigation($wideGap->notes[$later]['bar'] === 1 && $wideGap->notes[$later]['slot'] === 1, 'Widening a cursor shifts a later note across a bar while preserving the remaining gap.');
$wideGap->resizeGapAndShift(0, 0, 4, 3, 0);
expectNavigation($wideGap->notes[$later]['bar'] === 0 && $wideGap->notes[$later]['slot'] === 6, 'Deleting an empty cursor span pulls a later note across a bar.');

$deletedGap = new MADSCORE\Score\Score(12);
$deletedGap->put(0, 0, 0, 1, 'C', 2);
$next = $deletedGap->put(0, 0, 5, 1, 'D', 2);
$later = $deletedGap->put(0, 1, 0, 1, 'E', 2);
$other = $deletedGap->put(1, 0, 5, 1, 'F', 2);
$deletedGap->resizeGapAndShift(0, 0, 2, 3, 0);
expectNavigation($deletedGap->notes[$next]['slot'] === 2 && $deletedGap->notes[$later]['slot'] === 5, 'Delete removes the entire selected gap and shifts every later note.');
expectNavigation($deletedGap->notes[$other]['slot'] === 5, 'Deleting empty time leaves another melody unchanged.');
expectNavigation($deletedGap->noteAt(0, 0, 2) === $next, 'Deleting the gap brings the next note to the cursor.');

$backspace = new MADSCORE\Score\Score(2);
$first = $backspace->put(0, 0, 0, 1, 'C', 2);
$backspace->put(0, 0, 4, 1, 'D', 2);
$backspace->put(0, 1, 1, 1, 'E', 2);
$backspace->put(1, 0, 4, 1, 'F', 2);
$backspace->deleteAndClose($first);
expectNavigation(count($backspace->notes) === 3 && $backspace->noteAt(0, 0, 0) === 0, 'Backspace removes the note and its following gap.');
expectNavigation($backspace->noteAt(0, 0, 5) === 1, 'Backspace pulls later notes left across bar boundaries.');
expectNavigation($backspace->noteAt(1, 0, 4) === 2, 'Backspace leaves other melodies in place.');

MADSCORE\Score\Score::fromData($gap->data());
MADSCORE\Score\Score::fromData($wideGap->data());
MADSCORE\Score\Score::fromData($deletedGap->data());
MADSCORE\Score\Score::fromData($merged->data());

$acrossBar = new MADSCORE\Score\Score(12);
$acrossBar->put(0, 0, 6, 1, 'C', 2);
$acrossBar->put(0, 1, 1, 1, 'D', 2);
expectNavigation($acrossBar->moveCursor(0, 0, 6, 1) === [1, 0], 'Right reaches a gap across a bar boundary.');
expectNavigation($acrossBar->moveCursor(0, 1, 1, -1) === [1, 0], 'Left reaches a gap across a bar boundary.');
expectNavigation($acrossBar->freeLength(0, 1, 0, 4) === 1, 'The cursor width fits a cross-bar gap.');

$splitGap = new MADSCORE\Score\Score(12);
$splitGap->put(0, 0, 2, 4, 'C', 2);
$following = $splitGap->put(0, 1, 3, 4, 'D', 2);
expectNavigation($splitGap->moveCursor(0, 0, 2, 1) === [0, 4], 'Right enters the first segment of a gap.');
expectNavigation($splitGap->moveCursor(0, 0, 4, 1) === [1, 0], 'Right stops at the next bar instead of skipping a cross-bar gap.');
expectNavigation($splitGap->moveCursor(0, 1, 0, 1) === [1, 3], 'Right leaves the second gap segment at the next note.');
expectNavigation($splitGap->moveCursor(0, 1, 3, -1) === [1, 0], 'Left selects the gap segment in the note’s bar.');
expectNavigation($splitGap->moveCursor(0, 1, 0, -1) === [0, 4], 'Left reaches the previous bar’s separate gap segment.');
expectNavigation($splitGap->freeLength(0, 0, 4, 1) === 4 && $splitGap->freeLength(0, 1, 0, 1) === 3,
  'Automatic gap widths stop at bar boundaries.');
$longGap = new MADSCORE\Score\Score(12);
$longGap->put(0, 0, 2, 4, 'C', 2);
$longGap->put(0, 3, 1, 4, 'D', 2);
expectNavigation($longGap->moveCursor(0, 0, 4, 1) === [1, 0]
  && $longGap->moveCursor(0, 1, 0, 1) === [2, 0]
  && $longGap->moveCursor(0, 2, 0, 1) === [3, 0]
  && $longGap->moveCursor(0, 3, 0, 1) === [3, 1],
  'A gap crossing several bars remains one navigable segment per bar.');
expectNavigation($longGap->freeLength(0, 1, 0, 1) === 8 && $longGap->freeLength(0, 2, 0, 1) === 8,
  'A fully empty bar between notes has one bar-wide gap.');
$splitGap->resizeGapAndShift(0, 0, 4, 4, 5);
$splitGap->resizeGapAndShift(0, 0, 4, 5, 6);
expectNavigation($splitGap->notes[$following]['bar'] === 1 && $splitGap->notes[$following]['slot'] === 5,
  'An explicitly lengthened empty cursor can cross a bar and continue shifting later notes.');
foreach (['score' => $splitGap, 'bar' => 0, 'slot' => 4, 'melody' => 0, 'cursorLength' => 6, 'cursorLengthExplicit' => true] as $name => $value) {
  (new \ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
expectNavigation(MADSCORE\App\Controller::cursorLength() === 6, 'An explicit cursor width can extend beyond its bar’s automatic gap width.');
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'cursorLengthExplicit'))->setValue(null, false);
expectNavigation(MADSCORE\App\Controller::cursorLength() === 4, 'The automatic cursor width remains within its bar.');

$trailing = new MADSCORE\Score\Score(12);
expectNavigation(!$trailing->removeEmptyLastBar(0), 'The first bar is never removed.');
$trailing->ensureBar(1);
expectNavigation(!$trailing->removeEmptyLastBar(0) && count($trailing->bars) === 2, 'Leaving a nonfinal bar does not remove the final bar.');
expectNavigation($trailing->removeEmptyLastBar(1) && count($trailing->bars) === 1, 'Leaving an empty final bar shortens the score.');
$trailing->ensureBar(1);
$trailing->put(0, 0, 6, 4, 'C', 2);
expectNavigation($trailing->removeEmptyLastBar(1), 'A note ending at the boundary leaves the final bar empty.');
$trailing->ensureBar(1);
$trailing->setLength(0, 3);
expectNavigation(!$trailing->removeEmptyLastBar(1), 'A note sustained into the final bar keeps it.');
$trailing->setLength(0, 2);
$trailing->setSectionLeader(1, true);
expectNavigation($trailing->removeEmptyLastBar(1), 'Leaving an empty final section also removes its leader.');
$trailing->ensureBar(1);
$trailing->setTempo(1, '90');
expectNavigation($trailing->removeEmptyLastBar(1), 'Leaving an empty final bar discards its tempo setting.');
$trailing->ensureBar(1);
$trailing->setBeats(1, 3);
expectNavigation($trailing->removeEmptyLastBar(1), 'Leaving an empty final bar discards its beat count setting.');

foreach (['score' => $score, 'bar' => 0, 'slot' => 2, 'melody' => 0, 'cursorLength' => 4, 'cursorLengthExplicit' => false] as $name => $value) {
  (new \ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
expectNavigation(MADSCORE\App\Controller::cursorLength() === 1, 'The editor reports the shortened preview width.');
expectNavigation(MADSCORE\App\Controller::cursorStateForSheet()['end'] === 3, 'The drawn cursor ends where the next note starts.');

$viewportScore = new MADSCORE\Score\Score(12);
$viewportScore->ensureBar(4);
foreach (['score' => $viewportScore, 'firstBar' => 0, 'columns' => 2, 'bar' => 1, 'slot' => 6, 'cursorLength' => 2] as $name => $value) {
  (new \ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$alignTimeline = new \ReflectionMethod(MADSCORE\App\Controller::class, 'alignTimeline');
$firstBar = new \ReflectionProperty(MADSCORE\App\Controller::class, 'firstBar');
$length = new \ReflectionProperty(MADSCORE\App\Controller::class, 'cursorLength');
$bar = new \ReflectionProperty(MADSCORE\App\Controller::class, 'bar');
$alignTimeline->invoke(null);
expectNavigation($firstBar->getValue() === 0, 'A cursor ending at the visible edge keeps the timeline in place.');
$length->setValue(null, 3);
$alignTimeline->invoke(null);
expectNavigation($firstBar->getValue() === 1, 'A cursor extending into the next bar shifts the timeline to show its end.');
$bar->setValue(null, 0);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'slot'))->setValue(null, 0);
$alignTimeline->invoke(null);
expectNavigation($firstBar->getValue() === 0, 'The timeline shifts back when the cursor ends before the visible bars.');

$viewportScore->place(0, 1, 6, 4, 4, 3);
$bar->setValue(null, 1);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'slot'))->setValue(null, 6);
$length->setValue(null, 1);
$alignTimeline->invoke(null);
expectNavigation($firstBar->getValue() === 1, 'A selected note crossing the visible edge shifts the timeline by its full width.');

$spanningScore = new MADSCORE\Score\Score(12);
$spanningScore->place(0, 0, 6, 4, 4, 18);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $spanningScore);
$bar->setValue(null, 0);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'slot'))->setValue(null, 6);
$alignTimeline->invoke(null);
expectNavigation($firstBar->getValue() === 1, 'A selected note wider than the sheet aligns its end.');
$alignTimeline->invoke(null);
expectNavigation($firstBar->getValue() === 1, 'An oversized cursor keeps the timeline aligned with its end.');

$trailingPreview = new MADSCORE\Score\Score(12);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $trailingPreview);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'lastCursorEndBar'))->setValue(null, 0);
$bar->setValue(null, 0);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'slot'))->setValue(null, 7);
$length->setValue(null, 2);
$syncTimelineBars = new \ReflectionMethod(MADSCORE\App\Controller::class, 'syncTimelineBars');
$syncTimelineBars->invoke(null);
expectNavigation(count($trailingPreview->bars) === 2, 'A cursor extending past the final bar adds the bar it reaches.');
$bar->setValue(null, 1);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'slot'))->setValue(null, 0);
$syncTimelineBars->invoke(null);
$bar->setValue(null, 0);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'slot'))->setValue(null, 7);
$syncTimelineBars->invoke(null);
expectNavigation(count($trailingPreview->bars) === 2, 'Moving the cursor start back across a boundary keeps the bar while its end remains there.');
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'slot'))->setValue(null, 6);
$syncTimelineBars->invoke(null);
expectNavigation(count($trailingPreview->bars) === 1, 'Moving the cursor start within its bar removes the trailing bar when its end leaves it.');
$length->setValue(null, 3);
$syncTimelineBars->invoke(null);
expectNavigation(count($trailingPreview->bars) === 2, 'Lengthening the cursor into the next bar adds that bar.');
$trailingPreview->place(0, 1, 2, 4, 4, 2);
$length->setValue(null, 1);
$syncTimelineBars->invoke(null);
expectNavigation(count($trailingPreview->bars) === 2, 'A trailing bar containing a note remains after the cursor leaves it.');

$pages = new MADSCORE\Score\Score(12);
$pages->ensureBar(7);
$pages->put(0, 2, 5, 4, 'C', 2);
$pages->put(0, 2, 1, 4, 'D', 2);
$pages->put(1, 4, 2, 4, 'E', 2);
$pages->put(0, 7, 3, 4, 'F', 2);
$pages->put(0, 7, 5, 4, 'G', 2);
foreach (['score' => $pages, 'firstBar' => 2, 'columns' => 3, 'bar' => 3, 'melody' => 0] as $name => $value) {
  (new \ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$visibleEdge = new \ReflectionMethod(MADSCORE\App\Controller::class, 'visibleEdgeDestination');
expectNavigation($visibleEdge->invoke(null, false) === [2, 2, 0], 'Home reaches the leading gap in the first visible bar.');
$bar->setValue(null, 2);
expectNavigation($visibleEdge->invoke(null, false) === [0, 0, 0], 'Home pages backward when already in the first visible bar.');
$bar->setValue(null, 3);
expectNavigation($visibleEdge->invoke(null, true) === [2, 4, 0], 'End falls back to the last visible bar start when that melody has no note there.');
$bar->setValue(null, 4);
expectNavigation($visibleEdge->invoke(null, true) === [5, 7, 7], 'End pages forward to the trailing gap in the last visible bar.');
$firstBar->setValue(null, 5);
$bar->setValue(null, 7);
expectNavigation($visibleEdge->invoke(null, true) === [5, 7, 7], 'End stops at the trailing gap in the score final bar without adding another page.');
expectNavigation(count($pages->bars) === 8, 'Home and End destinations do not add score bars.');
$scoreEdge = new \ReflectionMethod(MADSCORE\App\Controller::class, 'scoreEdgeDestination');
expectNavigation($scoreEdge->invoke(null, false) === [0, 0], 'Ctrl+Home reaches the first score bar from a later page.');
expectNavigation($scoreEdge->invoke(null, true) === [7, 7], 'Ctrl+End reaches the last note or gap in the final score bar.');
expectNavigation(count($pages->bars) === 8, 'Score edge shortcuts do not add a bar.');

$canvas = new MADSCORE\App\SheetCanvas();
expectNavigation($canvas->releaseNotification(SPTK\SDLWrapper\SDL::KEY_RETURN, 0) === null, 'Return stays in the editor for note placement.');
expectNavigation($canvas->releaseNotification(SPTK\SDLWrapper\SDL::KEY_ESCAPE, 0) === 'cancel', 'Esc leaves the editor.');

echo "Navigation checks passed\n";
