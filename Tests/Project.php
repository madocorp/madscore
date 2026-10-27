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
$project->setPdfLayout(70, 200);
$motifId = $project->create();
expectProject($motifId === 'A' && $project->first() === $motifId,
  'First visit creates a named motif.');
$motif = $project->score($motifId);
expectProject($motif->notes === [] && $motif->title === 'Project study',
  'New motif starts empty and shares project metadata.');
$motif->place(0, 0, 0, 4, 7, 2);
$project->setScore($motifId, $motif);
$project->setName($motifId, 'Opening idea');
$project->setPattern("A B |\nA1");
$loaded = MADSCORE\Score\Project::fromData($project->data());
expectProject($loaded->data() === $project->data()
  && $loaded->pdfLayout() === ['noteSize' => 70, 'beatSize' => 200]
  && $loaded->pattern() === "A B |\nA1"
  && $loaded->item($motifId)['name'] === 'Opening idea'
  && count($loaded->score('main')->notes) === 1
  && count($loaded->score($motifId)->notes) === 1,
  'All project items round trip independently.');
MADSCORE\Render\PdfLayout::configure(100, 100);
$defaultPreview = MADSCORE\Render\PagePreview::render($main, 0, 842, 595);
$projectPreview = MADSCORE\Render\PagePreview::render($main, 0, 842, 595, $loaded);
expectProject($projectPreview->pixels !== $defaultPreview->pixels
  && MADSCORE\Render\PdfLayout::noteSize() === 100
  && MADSCORE\Render\PdfLayout::beatSize() === 100,
  'The PDF preview uses the project layout without changing another document.');
$layoutScore = new MADSCORE\Score\Score();
$layoutScore->ensureBar(49);
$layoutProject = MADSCORE\Score\Project::fromScore($layoutScore);
$layoutProject->setPdfLayout(70, 200);
expectProject(count(MADSCORE\Render\PagePainter::pages($layoutScore,
    project: $layoutProject)) > count(MADSCORE\Render\PagePainter::pages($layoutScore))
  && MADSCORE\Render\PdfLayout::beatSize() === 100,
  'Project PDF spacing controls pagination without changing global layout.');
$legacyLayout = $project->data();
unset($legacyLayout['pdfLayout']);
$legacyProject = MADSCORE\Score\Project::fromData($legacyLayout);
expectProject(!$legacyProject->hasPdfLayout()
  && $legacyProject->pdfLayout() === ['noteSize' => 100, 'beatSize' => 100],
  'Older projects without PDF sizes use the original defaults.');
foreach ([['noteSize' => 49, 'beatSize' => 100],
  ['noteSize' => 100, 'beatSize' => 99],
  ['noteSize' => 100, 'beatSize' => 201]] as $invalidLayout) {
  $invalidProject = $project->data();
  $invalidProject['pdfLayout'] = $invalidLayout;
  try {
    MADSCORE\Score\Project::fromData($invalidProject);
    throw new \RuntimeException('An invalid PDF layout was accepted.');
  } catch (\DomainException) {
  }
}
$legacyPattern = $project->data();
unset($legacyPattern['items'][0]['pattern']);
expectProject(MADSCORE\Score\Project::fromData($legacyPattern)->pattern() === null,
  'Projects saved before patterns were remembered still load.');
$invalidPattern = $project->data();
$invalidPattern['items'][1]['pattern'] = 'A';
try {
  MADSCORE\Score\Project::fromData($invalidPattern);
  throw new \RuntimeException('A motif accepted a score pattern.');
} catch (\DomainException) {
}
$active = $loaded->score($motifId);
$active->place(0, 0, 2, 4, 6, 2);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'project'))->setValue(null, $loaded);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'activeItemId'))->setValue(null, $motifId);
(new \ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $active);
$saved = MADSCORE\App\ControllerNoteEditing::projectData();
expectProject(count($saved['items'][0]['score']['notes']) === 1
  && count($saved['items'][1]['score']['notes']) === 2
  && count($saved['items']) === 2,
  'Saving the active motif retains every other project item.');
$secondMotif = $loaded->create();
expectProject($secondMotif === 'B', 'Later motif IDs use consecutive letters.');
$chosen = MADSCORE\Score\MotifScoreGenerator::place($loaded->score($secondMotif),
  8, 4, scale: 'Dorian', homeNote: 'F#')['score'];
$chosen->setSectionMusic(0, ['scale' => 'Dorian', 'homeNote' => 'F#']);
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
  expectProject($full->create() === $letter, 'Motif IDs follow the alphabet.');
}
expectProject($full->create() === 'AA', 'Motif IDs continue after Z.');

echo "Project checks passed\n";
