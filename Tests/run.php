<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Fail a focused regression check with a readable message. */
function check(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Read one RGB pixel from SPTK's packed Cairo output. */
function rgb(SPTK\Core\RasterImage $image, int $x, int $y): int {
  $offset = ($y * $image->width + $x) * 4;
  return (unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff;
}

$score = new MADSCORE\Score\Score(12);
$primaryRgb = hexdec(substr(MADSCORE\Score\Score::COLORS[0], 1));
$parser = new SPTK\XmlParser\XmlParser();
check(in_array('resize', array_map(static fn ($event): string => $event->type, $parser->events), true), 'The app registers its resize event.');
check(in_array('close', array_map(static fn ($event): string => $event->type, $parser->events), true), 'The app registers its session close event.');
check($score->moveCursor(0, 0, 0, 1) === [0, 1], 'An empty melody moves by one half-beat.');
$navigation = new MADSCORE\Score\Score(12);
$navigation->put(0, 0, 2, 1, 'A');
$navigation->put(0, 0, 6, 1, 'C');
$navigation->put(1, 0, 4, 1, 'B');
check($navigation->notePositions(0, 0) === [2, 6], 'Navigation positions contain only the current melody.');
check($navigation->moveCursor(0, 0, 0, 1) === [0, 2], 'Right crosses the leading gap to the first note.');
check($navigation->moveCursor(0, 0, 1, 1) === [0, 2], 'Right from inside the leading gap enters the note.');
check($navigation->moveCursor(0, 0, 2, 1) === [0, 4], 'Right reaches the first free position after a note.');
check($navigation->moveCursor(0, 0, 6, -1) === [0, 4], 'Left reaches the start of the gap before a note.');
check($navigation->moveCursor(0, 0, 2, -1) === [0, 0], 'Left from the first note reaches the leading gap.');
check($navigation->moveCursor(0, 0, 1, -1) === [0, 0], 'Left from inside the leading gap selects its start.');
check($navigation->moveCursor(0, 0, 6, 1) === [1, 0], 'Right crosses into an empty following bar.');
check($navigation->moveCursor(0, 1, 0, 1) === [1, 1], 'Half-beat movement resumes in an empty bar.');
check($navigation->moveCursor(0, 1, 0, -1) === [0, 6], 'Left returns to the last note in the previous bar.');
check($navigation->moveCursor(1, 0, 0, 1) === [0, 4], 'A different melody has its own leading gap.');
$carried = new MADSCORE\Score\Score(12);
$carried->put(0, 0, 7, 1, 'G');
check($carried->notePositions(0, 1) === [0], 'A note continuing through a bar edge occupies the next bar start.');
check($carried->moveCursor(0, 0, 7, 1) === [1, 1], 'Right skips a continued note to the first free position after it.');
check($carried->moveCursor(0, 1, 0, -1) === [0, 0], 'Left from a continued note reaches the start of its leading gap.');
$carried->setLength(0, 4);
$isolated = new MADSCORE\Score\Score(12);
$isolated->put(0, 0, 2, 1, 'D');
$isolated->setLength(0, 4);
check($isolated->moveCursor(0, 0, 5, -1) === [0, 0], 'Left skips the whole current note to the leading gap.');
check($isolated->moveCursor(0, 0, 2, 1) === [0, 6], 'Right from a note with no successor reaches the first free slot after it.');
$gap = new MADSCORE\Score\Score(12);
$gap->put(0, 0, 0, 1, 'C', 2);
$gap->put(0, 0, 3, 1, 'D', 2);
check($gap->moveCursor(0, 0, 0, 1) === [0, 2] && $gap->moveCursor(0, 0, 3, -1) === [0, 2], 'Both arrows can reach a one-half-beat gap.');
check($gap->moveCursor(0, 0, 2, -1) === [0, 0] && $gap->moveCursor(0, 0, 2, 1) === [0, 3], 'Arrows leave the gap through note onsets.');
check($gap->freeLength(0, 0, 2, 4) === 1, 'The empty cursor stops at the next note.');
check($gap->freeLength(1, 0, 2, 4) === 4, 'Other melodies do not shorten the cursor.');
$sized = new MADSCORE\Score\Score(12);
$sized->put(0, 0, 0, 1, 'C', 5);
check($sized->notes[0]['length'] === 5, 'A new note keeps its chosen duration.');
$placed = new MADSCORE\Score\Score(12);
check(MADSCORE\Score\Pitch::naturalPosition('E') === 8, 'A natural pitch key selects its chromatic cursor position.');
check(MADSCORE\Score\Pitch::step(1, 11, 1) === [1, 12], 'The preview pitch reaches G-sharp at the end of its octave.');
check(MADSCORE\Score\Pitch::step(1, 4, -1) === [1, 3], 'The preview pitch moves down by one semitone.');
$first = $placed->place(0, 0, 0, 1, 4, 2);
check($placed->notes[$first]['pitch'] === 'C' && $placed->notes[$first]['length'] === 2, 'Note placement uses the preview pitch and width.');
$placed->place(0, 0, 2, 1, 5, 1);
check($placed->notes[1]['pitch'] === 'C' && $placed->notes[1]['alter'] === 1 && $placed->notes[1]['length'] === 1, 'A half-beat preview places an altered half note.');
$placed->place(0, 0, 0, 1, 6, 2);
check(count($placed->notes) === 2 && $placed->notes[0]['pitch'] === 'D', 'Space replaces a note at the same onset.');
$placed->setPitchPosition(0, 2, 8);
check($placed->notes[0]['octave'] === 2 && $placed->notes[0]['pitch'] === 'E', 'Pitch edits update an existing note.');
try {
  $placed->place(0, 0, 0, 1, 7, 3);
  throw new RuntimeException('A replacement overlapped the following note.');
} catch (DomainException $error) {
  check($placed->notes[0]['pitch'] === 'E' && $placed->notes[0]['length'] === 2, 'A rejected replacement preserves the original note.');
}
$ripple = new MADSCORE\Score\Score(12);
$ripple->place(0, 0, 0, 1, 4, 2);
$ripple->place(0, 0, 4, 1, 6, 2);
$ripple->place(0, 0, 7, 1, 8, 2);
$ripple->place(1, 0, 4, 2, 1, 2);
$ripple->resizeAndShift(0, 4);
check($ripple->notes[1]['slot'] === 4 && $ripple->notes[2]['slot'] === 7, 'Growing into a free gap leaves later notes in place.');
$changed = $ripple->resizeAndShift(0, 5);
check($changed === [0, 1, 2], 'A width edit reports the note and shifted suffix.');
check($ripple->notes[1]['slot'] === 5 && $ripple->notes[2]['bar'] === 1 && $ripple->notes[2]['slot'] === 0, 'Growth beyond a gap pushes later notes across bars.');
check($ripple->notes[3]['bar'] === 0 && $ripple->notes[3]['slot'] === 4, 'A different melody stays in place.');
$ripple->resizeAndShift(0, 3);
check($ripple->notes[1]['slot'] === 3 && $ripple->notes[2]['slot'] === 6, 'Shrinking pulls later notes and preserves their gap.');
check(MADSCORE\Score\Score::fromData($ripple->data())->data() === $ripple->data(), 'A shifted score remains valid for saving.');
$score->put(0, 0, 7, 1, 'C');
check(count($score->bars) === 2, 'Note continuation creates the next bar.');
check($score->noteAt(0, 1, 0) === 0, 'A note sounds in the following bar.');
try {
  $score->put(0, 1, 0, 1, 'D');
  throw new RuntimeException('Overlapping note was accepted.');
} catch (DomainException $error) {
  check(str_contains($error->getMessage(), 'inside'), 'Overlap identifies the occupied position.');
}
$score->put(1, 1, 0, 2, 'D');
check(count($score->notes) === 2, 'Another melody may sound at the same time.');
$score->setLength(0, 6);
check($score->noteAt(0, 1, 4) === 0, 'Length edits continue across bars.');
$score->setBeats(0, 5);
check($score->noteAt(0, 1, 2) === 0, 'Changing bar beats shifts later bar positions without losing note continuity.');
$shortened = new MADSCORE\Score\Score(2);
for ($beat = 0; $beat < 4; $beat++) {
  $shortened->put(0, 0, 2 * $beat, 1, 'C');
}
$shortened->put(0, 1, 0, 1, 'D');
$shortened->put(1, 1, 0, 1, 'E');
$shortened->setBeats(0, 3);
check($shortened->notes[3]['bar'] === 1 && $shortened->notes[3]['slot'] === 0, 'Shortening a bar carries its fourth note into the next bar.');
check($shortened->notes[4]['bar'] === 1 && $shortened->notes[4]['slot'] === 2
  && $shortened->notes[5]['bar'] === 1 && $shortened->notes[5]['slot'] === 2,
  'Notes in following bars keep their timing across melodies when a bar shrinks.');
check(MADSCORE\Score\Score::fromData($shortened->data())->data() === $shortened->data(), 'A shortened bar saves and loads with its carried notes.');
$saved = MADSCORE\Score\Score::fromData($score->data());
check($saved->data() === $score->data(), 'JSON model round trip preserves the score.');
$stepping = new MADSCORE\Score\Score(12);
$stepping->put(0, 0, 0, 1, 'E');
$stepping->stepPitch(0, 1);
check($stepping->notes[0]['pitch'] === 'F' && $stepping->notes[0]['alter'] === 0, 'A semitone step crosses E to F without an accidental.');
$stepping->stepPitch(0, 1);
check($stepping->notes[0]['pitch'] === 'F' && $stepping->notes[0]['alter'] === 1, 'A second upward step reaches F-sharp.');
$stepping->stepPitch(0, -1);
check($stepping->notes[0]['pitch'] === 'F' && $stepping->notes[0]['alter'] === 0, 'A downward step reverses the previous move.');
$stepping->notes[0]['pitch'] = 'G';
$stepping->stepPitch(0, 1);
check($stepping->notes[0]['octave'] === 1 && $stepping->notes[0]['pitch'] === 'G' && $stepping->notes[0]['alter'] === 1, 'Stepping above G reaches G-sharp in the same octave.');
$stepping->stepPitch(0, -1);
check($stepping->notes[0]['octave'] === 1 && $stepping->notes[0]['pitch'] === 'G' && $stepping->notes[0]['alter'] === 0, 'Stepping down crosses back into the lower octave row.');
$stepping->notes[0]['pitch'] = 'D';
$stepping->notes[0]['alter'] = -1;
$stepping->stepPitch(0, 1);
check($stepping->notes[0]['pitch'] === 'D' && $stepping->notes[0]['alter'] === 0, 'Enharmonic spellings move by their actual semitone position.');
$stepping->notes[0]['octave'] = 8;
$stepping->notes[0]['pitch'] = 'G';
$stepping->notes[0]['alter'] = 0;
$stepping->stepPitch(0, 1);
check($stepping->notes[0]['octave'] === 8 && $stepping->notes[0]['pitch'] === 'G' && $stepping->notes[0]['alter'] === 1, 'The highest G steps up to G-sharp.');
try {
  $stepping->stepPitch(0, 1);
  throw new RuntimeException('A note moved beyond the highest row.');
} catch (DomainException $error) {
  check($stepping->notes[0]['pitch'] === 'G' && $stepping->notes[0]['alter'] === 1, 'An out-of-range pitch move leaves the highest G-sharp unchanged.');
}
$stepping->notes[0]['octave'] = 0;
$stepping->notes[0]['pitch'] = 'A';
$stepping->notes[0]['alter'] = -1;
try {
  $stepping->stepPitch(0, -1);
  throw new RuntimeException('A note moved beyond the lowest row.');
} catch (DomainException $error) {
  check($stepping->notes[0]['pitch'] === 'A' && $stepping->notes[0]['alter'] === -1, 'The lowest note stays in place after an out-of-range move.');
}
$span = new MADSCORE\Score\Score(12);
$span->put(0, 0, 7, 1, 'C');
$truncated = $span->data();
array_pop($truncated['bars']);
$restored = MADSCORE\Score\Score::fromData($truncated);
check(count($restored->bars) === 2, 'Loading restores bars needed by continuing notes.');

$repeated = new MADSCORE\Score\Score(12);
$source = $repeated->put(2, 0, 7, 3, 'F');
$repeated->setLength($source, 5);
$repeated->notes[$source]['alter'] = -1;
$copy = $repeated->repeatAfter($source);
check($repeated->notes[$copy] === ['melody' => 2, 'bar' => 1, 'slot' => 4, 'octave' => 3, 'pitch' => 'F', 'alter' => -1, 'length' => 5,
  'articulation' => 'ordinary', 'slurToNext' => false, 'glissandoToNext' => false, 'fermata' => false, 'trill' => 0, 'finger' => '', 'dynamicToNext' => '', 'dynamicLevel' => ''], 'Repeat keeps note settings and begins at the source end across a bar.');
check(count($repeated->bars) === 3, 'A repeated note adds bars for its full duration.');
$next = $repeated->repeatAfter($copy);
check($repeated->notes[$next]['bar'] === 2 && $repeated->notes[$next]['slot'] === 1, 'Repeated copies can be chained.');
$blocked = new MADSCORE\Score\Score(12);
$blocked->put(0, 0, 0, 1, 'A');
$following = $blocked->put(0, 0, 2, 1, 'B');
$otherMelody = $blocked->put(1, 0, 2, 1, 'C');
$inserted = $blocked->repeatAfter(0);
check($blocked->notes[$inserted]['slot'] === 2 && $blocked->notes[$following]['slot'] === 4, 'Repeat inserts time in its melody and moves an adjacent note.');
check($blocked->notes[$otherMelody]['slot'] === 2, 'Repeat leaves another melody in place.');

$blank = new MADSCORE\Score\Score(12);
$upper = MADSCORE\Render\BarPainter::render($blank, 0, 0, 240, 120);
$lower = MADSCORE\Render\BarPainter::render($blank, 0, 1, 240, 120);
foreach ([10, 50, 90] as $line) {
  check(rgb($upper, 100, $line) !== 0xffffff, 'F-sharp, D, and A-sharp staff lines use regular pitch spacing.');
  check(rgb($lower, 100, $line) === rgb($upper, 100, $line), 'The lower octave lines use the same pitch spacing.');
}
foreach ([20, 60, 100] as $gap) {
  check(rgb($upper, 100, $gap) === 0xffffff, 'No staff line is drawn at a neighboring pitch.');
}
check(rgb($upper, 100, 119) === 0xffffff, 'No text or line fills the octave boundary.');
check(rgb($upper, 30, 40) !== 0xffffff && rgb($upper, 15, 40) === 0xffffff, 'A faint guide marks each half-beat boundary.');
check(rgb($upper, 30, 0) === 0xffffff, 'Half-beat guides stay within the staff.');
check(rgb($upper, 0, 0) === 0xffffff, 'Bar lines do not enter the space above the staff.');
check(rgb($upper, 0, 40) !== 0xffffff, 'Bar lines connect the three staff lines.');
check(rgb($upper, 0, 119) === 0xffffff, 'Bar lines leave the octave gap clear.');
check(rgb($upper, 239, 40) === 0xffffff, 'An adjoining bar does not draw a second separator.');
$endBar = MADSCORE\Render\BarPainter::render($blank, 0, 0, 240, 120, endBar: true);
check(rgb($endBar, 239, 40) !== 0xffffff, 'The outer edge keeps its ending separator.');
$preview = ['start' => 2, 'end' => 4, 'octave' => 1, 'pitch' => 4];
$cursor = MADSCORE\Render\BarPainter::render($blank, 0, 1, 240, 120, $preview, 0);
check(rgb($cursor, 60, 0) === $primaryRgb && rgb($cursor, 60, 99) === $primaryRgb && rgb($cursor, 60, 100) !== $primaryRgb, 'The H sides end at the lower G-sharp center.');
check(rgb($cursor, 120, 0) === $primaryRgb && rgb($cursor, 120, 99) === $primaryRgb, 'Both H sides mark the preview width.');
check(rgb($cursor, 90, 70) === $primaryRgb && rgb($cursor, 90, 40) !== $primaryRgb, 'The H crossbar marks only the preview pitch.');
$aboveCursor = MADSCORE\Render\BarPainter::render($blank, 0, 2, 240, 120, $preview, 0);
$belowCursor = MADSCORE\Render\BarPainter::render($blank, 0, 0, 240, 120, $preview, 0);
check(rgb($aboveCursor, 60, 110) === $primaryRgb && rgb($aboveCursor, 60, 100) !== $primaryRgb, 'The H sides start at A in the tile above.');
check(rgb($belowCursor, 60, 50) !== $primaryRgb, 'The H sides do not enter the octave below.');
$sharpPreview = ['start' => 2, 'end' => 4, 'octave' => 1, 'pitch' => 0];
$sharpCursor = MADSCORE\Render\BarPainter::render($blank, 0, 1, 240, 120, $sharpPreview, 0);
$sharpBelow = MADSCORE\Render\BarPainter::render($blank, 0, 0, 240, 120, $sharpPreview, 0);
$sharpAbove = MADSCORE\Render\BarPainter::render($blank, 0, 2, 240, 120, $sharpPreview, 0);
check(rgb($sharpCursor, 90, 110) === $primaryRgb && rgb($sharpCursor, 60, 110) === $primaryRgb && rgb($sharpCursor, 60, 100) !== $primaryRgb, 'At G-sharp the crossbar sits just below the A endpoint.');
check(rgb($sharpBelow, 60, 50) === $primaryRgb && rgb($sharpAbove, 60, 110) !== $primaryRgb, 'At G-sharp the sides extend into the octave below.');
$cursor = MADSCORE\Render\BarPainter::render($blank, 0, 0, 240, 120, ['start' => 0, 'end' => 2, 'octave' => 0, 'pitch' => 4], 0);
check(rgb($cursor, 0, 40) === $primaryRgb, 'An H side at a bar start covers the separator.');
$spanning = ['start' => 7, 'end' => 9, 'octave' => 0, 'pitch' => 4];
$cursor = MADSCORE\Render\BarPainter::render($blank, 0, 0, 240, 120, $spanning, 0);
$nextCursor = MADSCORE\Render\BarPainter::render($blank, 1, 0, 240, 120, $spanning, 0);
check(rgb($cursor, 210, 40) === $primaryRgb && rgb($nextCursor, 30, 40) === $primaryRgb, 'An H spanning bars has outer sides in both tiles.');
check(rgb($cursor, 239, 40) !== $primaryRgb && rgb($nextCursor, 0, 40) !== $primaryRgb, 'An H spanning bars has no side at the internal edge.');
$boundary = new MADSCORE\Score\Score(12);
$boundary->put(0, 0, 0, 1, 'G');
$upper = MADSCORE\Render\BarPainter::render($boundary, 0, 2, 240, 120);
$lower = MADSCORE\Render\BarPainter::render($boundary, 0, 1, 240, 120);
check(MADSCORE\Render\BarPainter::visibleOctaves($boundary->notes[0]) === [2, 1], 'A note centered on an octave boundary dirties both tiles.');
check(rgb($upper, 30, 111) !== 0xffffff, 'A boundary note extends into the octave above.');
check(rgb($lower, 30, 8) !== 0xffffff, 'The same note continues in its own octave.');
$circle = new MADSCORE\Score\Score(12);
$circle->put(0, 0, 0, 1, 'B');
check(MADSCORE\Render\BarPainter::visibleOctaves($circle->notes[0]) === [1], 'A note inside an octave dirties only its own tile.');
$round = MADSCORE\Render\BarPainter::render($circle, 0, 1, 240, 120);
check(rgb($round, 30, 80) === 0xffffff, 'A natural base note has a hollow center.');
$roundWithCursor = MADSCORE\Render\BarPainter::render($circle, 0, 1, 240, 120, ['start' => 0, 'end' => 2, 'octave' => 1, 'pitch' => 3], 0);
check(rgb($roundWithCursor, 30, 80) === $primaryRgb, 'The cursor crossbar passes through the chosen note center.');
check(rgb($round, 21, 80) !== 0xffffff, 'The base circle reaches one pitch position left of its center.');
check(rgb($round, 40, 80) === 0xffffff, 'The base circle stops one pitch position right of its center.');
check(rgb($round, 20, 70) === 0xffffff, 'The base note has true circular corners.');
$circle->notes[0]['alter'] = 1;
$enharmonic = MADSCORE\Render\BarPainter::render($circle, 0, 1, 240, 120);
check(rgb($enharmonic, 30, 70) === 0xffffff, 'B-sharp is a hollow white-key note.');
foreach ([
  ['E', 1, 20, 0xffffff, 'E-sharp'],
  ['C', -1, 80, 0xffffff, 'C-flat'],
  ['F', -1, 30, 0xffffff, 'F-flat'],
  ['A', 1, 90, $primaryRgb, 'A-sharp'],
  ['C', 1, 60, $primaryRgb, 'C-sharp'],
  ['D', 1, 40, $primaryRgb, 'D-sharp'],
  ['F', 1, 10, $primaryRgb, 'F-sharp'],
  ['G', -1, 10, $primaryRgb, 'G-flat'],
  ['A', -1, 110, $primaryRgb, 'A-flat'],
] as [$pitch, $alter, $y, $expected, $name]) {
  $key = new MADSCORE\Score\Score(12);
  $key->put(0, 0, 0, 1, $pitch);
  $key->notes[0]['alter'] = $alter;
  $keyImage = MADSCORE\Render\BarPainter::render($key, 0, 1, 240, 120);
  check(rgb($keyImage, 30, $y) === $expected, $name . ' uses the fill of its piano key.');
}
$half = new MADSCORE\Score\Score(12);
$half->put(0, 0, 0, 1, 'B');
$half->setLength(0, 1);
$halfImage = MADSCORE\Render\BarPainter::render($half, 0, 1, 240, 120);
check(rgb($halfImage, 10, 80) === 0xffffff && rgb($halfImage, 15, 80) === $primaryRgb, 'A half-base note has a straight left edge at its center.');
check(rgb($halfImage, 20, 80) === 0xffffff && rgb($halfImage, 24, 80) !== 0xffffff, 'A hollow half-base note has only the right circular arc.');
$half->notes[0]['pitch'] = 'C';
$half->notes[0]['alter'] = 1;
$filledHalf = MADSCORE\Render\BarPainter::render($half, 0, 1, 240, 120);
check(rgb($filledHalf, 10, 60) === 0xffffff && rgb($filledHalf, 20, 60) === $primaryRgb, 'A black-key half-base note fills only its right semicircle.');
$touching = new MADSCORE\Score\Score(12);
$touching->put(0, 0, 0, 1, 'C');
$touching->put(1, 0, 0, 1, 'D');
$touchImage = MADSCORE\Render\BarPainter::render($touching, 0, 1, 240, 120);
$emptyImage = MADSCORE\Render\BarPainter::render(new MADSCORE\Score\Score(12), 0, 1, 240, 120);
check(rgb($touchImage, 30, 60) !== rgb($emptyImage, 30, 60), 'C and D circles touch at the midpoint of their centers.');
$overlap = new MADSCORE\Score\Score(12);
$overlap->put(0, 0, 0, 1, 'E');
$eImage = MADSCORE\Render\BarPainter::render($overlap, 0, 1, 240, 120);
$overlap->notes[0]['pitch'] = 'F';
$fImage = MADSCORE\Render\BarPainter::render($overlap, 0, 1, 240, 120);
check(rgb($eImage, 22, 25) !== 0xffffff && rgb($fImage, 22, 25) !== 0xffffff, 'E and F circles overlap between their centers.');
$crossing = new MADSCORE\Score\Score(12);
$crossing->put(0, 0, 6, 1, 'C');
$crossing->setLength(0, 4);
$leftBar = MADSCORE\Render\BarPainter::render($crossing, 0, 1, 240, 120);
$rightBar = MADSCORE\Render\BarPainter::render($crossing, 1, 1, 240, 120);
check(rgb($leftBar, 239, 70) === $primaryRgb, 'A beat mark remains visible at the right edge of a bar.');
check(rgb($rightBar, 0, 70) === $primaryRgb, 'The same beat mark continues at the left edge of the next bar.');
$crossing->notes[0]['alter'] = 1;
$blackLeft = MADSCORE\Render\BarPainter::render($crossing, 0, 1, 240, 120);
$blackRight = MADSCORE\Render\BarPainter::render($crossing, 1, 1, 240, 120);
check(rgb($blackLeft, 239, 60) === 0xffffff && rgb($blackRight, 0, 60) === 0xffffff, 'A filled note also keeps its contrasting beat mark across the bar edge.');

$legacy = tempnam(sys_get_temp_dir(), 'madscore-legacy-');
file_put_contents($legacy, '{"title":"Old song","tempo":"120"}' . "\n{C2 D/ E'} [lyrics]\n{G2}\n");
[$imported, $warnings] = MADSCORE\Score\LegacyImporter::read($legacy);
unlink($legacy);
check($imported->title === 'Old song' && count($imported->notes) === 4 && $imported->bars[0]['tempo'] === '120', 'Legacy import keeps its BPM tempo.');
check(in_array('lyrics', $warnings, true), 'Legacy import names omitted lyrics.');

$printed = new MADSCORE\Score\Score(12);
$printed->ensureBar(24);
$pdf = tempnam(sys_get_temp_dir(), 'madscore-pdf-');
MADSCORE\Render\PdfWriter::write($pdf, $printed);
$pdfData = file_get_contents($pdf);
check(str_starts_with($pdfData, '%PDF-'), 'Export writes a PDF document.');
check(strlen($pdfData) > 1000, 'Vector PDF export contains the score drawing.');
unlink($pdf);
echo "madscore checks passed.\n";
