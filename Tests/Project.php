<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectProject(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$main = new MADSCORE\Score\Score();
$main->title = 'Project study';
$main->place(0, 0, 0, 4, 4, 2);
$project = MADSCORE\Score\Project::fromScore($main);
$motifId = $project->create('motif');
$phraseId = $project->create('phrase');
expectProject($motifId === 'A' && $phraseId === 'phrase-1'
  && $project->first('motif') === $motifId && $project->first('phrase') === $phraseId,
  'First visits create named motif and phrase items.');
$motif = $project->score($motifId);
expectProject($motif->notes === [] && $motif->title === 'Project study',
  'New motif starts empty and shares project metadata.');
$motif->place(0, 0, 0, 4, 7, 2);
$project->setScore($motifId, $motif);
$project->setName($motifId, 'Opening idea');
$loaded = MADSCORE\Score\Project::fromData($project->data());
expectProject($loaded->data() === $project->data()
  && $loaded->item($motifId)['name'] === 'Opening idea'
  && count($loaded->score('main')->notes) === 1
  && count($loaded->score($motifId)->notes) === 1
  && $loaded->score($phraseId)->notes === [],
  'All project items round trip independently.');
$active = $loaded->score($motifId);
$active->place(0, 0, 2, 4, 6, 2);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'project'))->setValue(null, $loaded);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'activeItemId'))->setValue(null, $motifId);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $active);
$saved = MADSCORE\App\Controller::projectData();
expectProject(count($saved['items'][0]['score']['notes']) === 1
  && count($saved['items'][1]['score']['notes']) === 2
  && $saved['items'][2]['score']['notes'] === [],
  'Saving the active motif retains every other project item.');
$secondMotif = $loaded->create('motif');
expectProject($secondMotif === 'B', 'Later motif IDs use consecutive letters.');
$chosen = MADSCORE\Score\MotifScoreGenerator::place($loaded->score($secondMotif),
  8, 4, scale: 'Dorian', homeNote: 'F#')['score'];
$loaded->setScore($secondMotif, $chosen);
expectProject($loaded->item($secondMotif)['music']
  === ['scale' => 'Dorian', 'homeNote' => 'F#']
  && MADSCORE\Score\Project::fromData($loaded->data())
    ->score($secondMotif)->melodies[0]['motif']['homeNote'] === 'F#',
  'Generated scale and home note survive project save and load.');
$invalid = $loaded->data();
$invalid['items'][1]['id'] = 'main';
try {
  MADSCORE\Score\Project::fromData($invalid);
  throw new \RuntimeException('Duplicate main ID was accepted.');
} catch (\DomainException $error) {
  expectProject(true, 'Duplicate IDs are rejected.');
}

$oldId = $loaded->data();
$oldId['items'][1]['id'] = 'motif-1';
try {
  MADSCORE\Score\Project::fromData($oldId);
  throw new \RuntimeException('An old motif ID was accepted.');
} catch (\DomainException $error) {
  expectProject(true, 'Motif IDs use letters only.');
}

$full = new MADSCORE\Score\Project();
foreach (range('A', 'Z') as $letter) {
  expectProject($full->create('motif') === $letter, 'Motif IDs follow the alphabet.');
}
expectProject($full->create('motif') === 'AA', 'Motif IDs continue after Z.');

echo "Project checks passed\n";
