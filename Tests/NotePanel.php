<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Check the note panel's title and compact details. */
function expectNotePanel(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
$score = new MADSCORE\Score\Score();
foreach (['window' => $window, 'score' => $score, 'bar' => 0, 'slot' => 0, 'octave' => 3, 'melody' => 0,
  'pitchPosition' => 2, 'cursorLength' => 2, 'cursorLengthExplicit' => false, 'displayedReadout' => ''] as $name => $value) {
  (new ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$refresh = new ReflectionMethod(MADSCORE\App\Controller::class, 'refreshCursorReadout');
$install = new ReflectionMethod(MADSCORE\App\Controller::class, 'installSheet');
$install->invoke(null, 'editor');
$editor = $window->screen('editor');
$editor->measureGrid(new SPTK\Layout\Tile(0, 0, 132, 38));
foreach (['melodyBox', 'actionsBox'] as $boxId) {
  $box = $editor->layout->findTile($boxId)->grid();
  $fillers = array_values(array_filter($editor->layout->leaves(), static function ($leaf) use ($box): bool {
    return $leaf->instance() instanceof SPTK\Widgets\Empty\Placeholder
      && $leaf->grid()->x === $box->x && $leaf->grid()->y >= $box->y;
  }));
  expectNotePanel(count($fillers) === 1 && $fillers[0]->grid()->height <= 1
    && $fillers[0]->grid()->y + $fillers[0]->grid()->height === $box->y + $box->height,
    "The {$boxId} filler reaches the compact settings panel bottom.");
}
$cursorBox = $editor->layout->findTile('cursorBox')->grid();
$cursorFillers = array_values(array_filter($editor->layout->leaves(), static function ($leaf) use ($cursorBox): bool {
  return $leaf->instance() instanceof SPTK\Widgets\Empty\Placeholder
    && $leaf->grid()->x === $cursorBox->x && $leaf->grid()->y >= $cursorBox->y;
}));
expectNotePanel(count($cursorFillers) === 1 && $cursorFillers[0]->grid()->height > 0
  && $cursorFillers[0]->grid()->y + $cursorFillers[0]->grid()->height === $cursorBox->y + $cursorBox->height,
  'A shorter box stretches its filler to the automatically measured panel bottom.');
expectNotePanel($editor->layout->findTile('noteBox')->grid()->width
  > $editor->layout->findTile('melodyBox')->grid()->width,
  'The Note box receives more width than the other controls.');
foreach (['noteHelp', 'cursorHelp', 'melodyHelp', 'actionsHelp'] as $helpId) {
  $help = $editor->layout->findTile($helpId);
  expectNotePanel($help->grid()->height >= substr_count($help->instance()->text(), "\n") + 1,
    "The {$helpId} text fits vertically in the editor layout.");
  expectNotePanel($help->grid()->y + $help->grid()->height <= 38,
    "The {$helpId} text remains inside the default window height.");
  foreach (explode("\n", $help->instance()->text()) as $line) {
    expectNotePanel(mb_strwidth($line) <= $help->grid()->width,
      "The {$helpId} line fits inside its column.");
  }
}
$helpGrid = new SPTK\Rendering\Grid(20, 1);
$editor->widget('melodyHelp')->paint(new SPTK\Rendering\GridWriter($helpGrid, new SPTK\Layout\Tile(0, 0, 20, 1)));
expectNotePanel($helpGrid->cell(0, 0)->glyph === 'T'
  && [$helpGrid->cell(0, 0)->fg->r, $helpGrid->cell(0, 0)->fg->g, $helpGrid->cell(0, 0)->fg->b] === [128, 203, 196]
  && $helpGrid->cell(4, 0)->glyph === 'N'
  && [$helpGrid->cell(4, 0)->fg->r, $helpGrid->cell(4, 0)->fg->g, $helpGrid->cell(4, 0)->fg->b] !== [128, 203, 196],
  'The XML highlighter colors the shortcut while leaving its description in the normal text color.');
$reader = new XMLReader();
$reader->XML('<TextEditor highlighter="MADSCORE\App\HelpHighlighter">Tab Next</TextEditor>');
$reader->read();
$editorParser = new SPTK\Widgets\TextEditor\Parser();
$editorParser->validateAttributes($reader, []);
$highlightedEditor = $editorParser->parse($reader, new SPTK\Core\Style())->widget;
$editorGrid = new SPTK\Rendering\Grid(20, 1);
$highlightedEditor->paint(new SPTK\Rendering\GridWriter($editorGrid, new SPTK\Layout\Tile(0, 0, 20, 1)));
expectNotePanel($editorGrid->cell(0, 0)->glyph === 'T'
  && [$editorGrid->cell(0, 0)->fg->r, $editorGrid->cell(0, 0)->fg->g, $editorGrid->cell(0, 0)->fg->b] === [128, 203, 196],
  'TextEditor also loads its app highlighter from XML.');
expectNotePanel($refresh->invoke(null), 'The initial gap updates the note panel.');
expectNotePanel($editor->widget('barSettingsTitle')->text() === '[R] Bar 1'
  && $editor->widget('selectedMelody')->text() === '[Y] Melody 1',
  'Bar and melody shortcuts appear in their tile titles.');
$score->setSectionMusic(0, ['scale' => 'Major', 'homeNote' => 'G']);
(new ReflectionMethod(MADSCORE\App\Controller::class, 'refreshSelectedBarFields'))->invoke(null);
expectNotePanel($editor->widget('barSettingsTitle')->text() === '[R] Bar 1 (Major G)',
  'The Bar title shows the current section scale and home note.');
expectNotePanel($editor->widget('section')->hotkey() === '|'
  && (new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label'))->getValue($editor->widget('section')) === 'Section start: Yes',
  'The Bar tile shows the highlighted section shortcut like other toggle buttons.');
expectNotePanel($editor->widget('noteTitle')->text() === '[T] Gap', 'The Note tile title shows T even in a gap.');
expectNotePanel($editor->widget('cursorTitle')->text() === 'Cursor A#3'
  && $editor->widget('cursorLength')->text() === 'Length 1 beat',
  'The Cursor title shows its pitch and the length remains below it.');
expectNotePanel($editor->widget('focusCursor') === null,
  'The Cursor readout has no button.');
expectNotePanel($editor->widget('slur')->hotkey() === 'l'
  && (new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label'))->getValue($editor->widget('slur')) === 'Link to next: —',
  'The Note box shows L and an empty link state in a gap.');
$score->place(0, 0, 0, 3, 2, 2);
expectNotePanel($refresh->invoke(null), 'Adding a note updates the title even when details stay the same.');
expectNotePanel($editor->widget('noteTitle')->text() === '[T] Note A#3', 'The note pitch appears beside T in the title.');
expectNotePanel($editor->widget('cursorTitle')->text() === 'Cursor A#3'
  && $editor->widget('cursorLength')->text() === 'Length 1 beat',
  'Adding a note leaves the cursor readout unchanged.');
expectNotePanel($editor->widget('staccato')->hotkey() === 's', 'The note panel offers S for staccato.');
expectNotePanel($editor->widget('dynamicLevel')->hotkey() === '/'
  && (new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label'))->getValue($editor->widget('dynamicLevel')) === 'All levels: —',
  'The slash button shows the shared level at the cursor.');
expectNotePanel($editor->widget('dynamic')->hotkey() === '*'
  && (new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label'))->getValue($editor->widget('dynamic')) === 'All wedge: =',
  'The star button shows the shared wedge at the cursor.');
$score->setScoreDynamicLevel(0, 'mf');
expectNotePanel($refresh->invoke(null)
  && (new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label'))->getValue($editor->widget('dynamicLevel')) === 'All levels: mf',
  'The slash button updates when the shared level changes.');
expectNotePanel($editor->widget('fermata')->preferredWidth() > 0, 'The note panel offers a fermata control.');
expectNotePanel($editor->widget('trill')->hotkey() === 'w', 'The note panel offers W for trill.');
$linkLabel = new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label');
expectNotePanel($linkLabel->getValue($editor->widget('slur')) === 'Link to next: No',
  'A note without a link shows No.');
$score->place(0, 0, 2, 3, 4, 2);
$score->setScoreDynamicWedge(0, 2, 'crescendo');
expectNotePanel($refresh->invoke(null)
  && (new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label'))->getValue($editor->widget('dynamic')) === 'All wedge: +',
  'The star button updates when the shared wedge changes.');
$score->setSlurToNext(0, true);
expectNotePanel($refresh->invoke(null) && $linkLabel->getValue($editor->widget('slur')) === 'Link to next: Yes',
  'A linked note shows Yes.');
$score->setFermata(0, true);
expectNotePanel($refresh->invoke(null), 'A fermata updates the note panel.');
expectNotePanel($editor->widget('cursorTitle')->text() === 'Cursor A#3'
  && $editor->widget('cursorLength')->text() === 'Length 1 beat'
  && (new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label'))->getValue($editor->widget('fermata')) === 'Fermata: Yes',
  'The button identifies the selected fermata without adding a details line.');
expectNotePanel($linkLabel->getValue($editor->widget('slur')) === 'Link to next: No',
  'The link button updates when fermata clears the link.');
(new ReflectionProperty(MADSCORE\App\Controller::class, 'pitchPosition'))->setValue(null, 4);
expectNotePanel($refresh->invoke(null)
  && $editor->widget('cursorTitle')->text() === 'Cursor C3'
  && $editor->widget('noteTitle')->text() === '[T] Note A#3',
  'Moving the cursor pitch changes its heading without changing the selected note heading.');
expectNotePanel(str_contains($editor->widget('noteHelp')->text(), 'Bksp Delete + gap')
  && !str_contains($editor->widget('noteHelp')->text(), 'Toggle staccato'),
  'The Note box shows note editing help beside its articulation controls.');
expectNotePanel(str_contains($editor->widget('cursorHelp')->text(), 'Left/Right Time')
  && str_contains($editor->widget('cursorHelp')->text(), '+/- Change length')
  && str_contains($editor->widget('noteHelp')->text(), '1-9 All ppp-fff')
  && str_contains($editor->widget('noteHelp')->text(), 'CTRL+1-9 ppp-fff')
  && !str_contains($editor->widget('melodyHelp')->text(), '0 Mute/unmute')
  && $editor->widget('muteMelody')->hotkey() === '0',
  'The Cursor box holds movement and entry help.');
expectNotePanel(str_contains($editor->widget('melodyHelp')->text(), 'Shift+Tab Prev')
  && str_contains($editor->widget('actionsHelp')->text(), 'P/M Play all/one')
  && str_contains($editor->widget('actionsHelp')->text(), 'V Preview')
  && str_contains($editor->widget('actionsHelp')->text(), 'C Composer')
  && str_contains($editor->widget('actionsHelp')->text(), 'O Chords'),
  'Melody and Actions show their own shortcuts.');
echo "Note panel checks passed\n";
