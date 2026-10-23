<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\App\ScoreHistory;
use MADSCORE\Score\{MotifArrangement, Project, Score};

function expectArrangement(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$old = new Score();
$old->place(0, 0, 0, 4, 4, 2);
$project = Project::fromScore($old);
$a = $project->create();
$b = $project->create();
$first = new Score();
$first->place(0, 0, 0, 4, 4, 2);
$first->ensureBar(1);
$first->setSectionLeader(1, true);
$first->bars[1]['motifVersion'] = ['algorithm' => 'reverse', 'source' => 0,
  'parameters' => []];
$first->place(0, 1, 0, 4, 6, 2);
$project->setScore($a, $first);
$second = new Score();
$second->place(0, 0, 0, 4, 8, 2);
$project->setScore($b, $second);
$built = MotifArrangement::build($project, "A, B\nA1 | A B A1");
expectArrangement(count($built->bars) === 6
  && array_column($built->bars, 'sectionLeader') === [true, false, false, true, false, false]
  && !array_key_exists('motifVersion', $built->bars[2])
  && array_column($built->notes, 'bar') === [0, 1, 2, 3, 4, 5]
  && array_column($built->notes, 'pitch') === ['C', 'E', 'D', 'C', 'E', 'D'],
  'References copy motif sections in order; separators add no bars.');
expectArrangement($built->title === $old->title && $project->score('main')->data() === $old->data(),
  'Building leaves the main score untouched until confirmation.');
$history = new ScoreHistory($old);
$old->bars = $built->bars;
$old->notes = $built->notes;
$old->melodies = $built->melodies;
$old->scoreDynamics = $built->scoreDynamics;
$history->observe($old);
expectArrangement($history->undo($old) && count($old->bars) === 1
  && count($old->notes) === 1, 'One undo restores the replaced main score.');
foreach (['A2', 'Z', 'A |', 'A || B', '123'] as $invalid) {
  try {
    MotifArrangement::build($project, $invalid);
    throw new RuntimeException("Invalid pattern $invalid was accepted.");
  } catch (DomainException) {
  }
}
$obsolete = $project->data();
$obsolete['items'][1]['type'] = 'motif';
try {
  Project::fromData($obsolete);
  throw new RuntimeException('Obsolete item type was accepted.');
} catch (DomainException) {
}
echo "Motif arrangement checks passed\n";
