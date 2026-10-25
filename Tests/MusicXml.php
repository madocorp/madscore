<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Score\MusicXmlWriter;
use MADSCORE\Score\Score;

function expectMusicXml(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new Score(2);
$score->title = 'A & B';
$score->author = 'Me <You>';
$score->place(0, 0, 1, 3, 4, 3); // C4, after an eighth rest.
$score->place(0, 0, 4, 3, 7, 6); // D-sharp4, tied across bar one.
$score->place(1, 0, 0, 2, 1, 2);
$score->addChordTone(1, 0, 0, 2, 4, 2);
$score->ensureBar(2);
$score->setSectionMusic(1, ['scale' => 'Dorian', 'homeNote' => 'F#']);
$xml = MusicXmlWriter::encode($score);
$document = new DOMDocument();
expectMusicXml($document->loadXML($xml, LIBXML_NONET), 'MusicXML is well-formed.');
$xpath = new DOMXPath($document);
$query = static fn (string $path): array => iterator_to_array($xpath->query($path));
expectMusicXml($xpath->evaluate('string(/score-partwise/work/work-title)') === 'A & B'
  && $xpath->evaluate('string(/score-partwise/identification/creator)') === 'Me <You>',
  'Metadata is XML-escaped and preserved.');
expectMusicXml(count($query('/score-partwise/part')) === 2
  && count($query('/score-partwise/part/measure')) === 6,
  'Each melody has its own part and all bars.');
expectMusicXml($xpath->evaluate('string(/score-partwise/part[1]/measure[1]/attributes/clef/sign)') === 'G'
  && $xpath->evaluate('string(/score-partwise/part[1]/measure[1]/attributes/clef/line)') === '2'
  && $xpath->evaluate('string(/score-partwise/part[2]/measure[1]/attributes/clef/sign)') === 'F'
  && $xpath->evaluate('string(/score-partwise/part[2]/measure[1]/attributes/clef/line)') === '4',
  'High and deep melodies use treble and bass clefs separately.');
expectMusicXml($xpath->evaluate('string(/score-partwise/part[1]/measure[1]/attributes/divisions)') === '2'
  && $xpath->evaluate('string(/score-partwise/part[1]/measure[1]/direction/sound/@tempo)') === '120'
  && $xpath->evaluate('string(/score-partwise/part[1]/measure[1]/note[1]/rest)') === ''
  && $xpath->evaluate('string(/score-partwise/part[1]/measure[1]/note[1]/duration)') === '1',
  'A leading half-beat gap becomes an eighth rest.');
expectMusicXml($xpath->evaluate('string(/score-partwise/part[1]/measure[1]/note[2]/pitch/step)') === 'C'
  && $xpath->evaluate('string(/score-partwise/part[1]/measure[1]/note[2]/pitch/octave)') === '4'
  && $xpath->evaluate('string(/score-partwise/part[1]/measure[1]/note[2]/duration)') === '3'
  && count($query('/score-partwise/part[1]/measure[1]/note[2]/dot')) === 1,
  'Written pitch and dotted duration map to standard MusicXML notes.');
expectMusicXml(count($query('/score-partwise/part[1]/measure[1]/note[tie[@type="start"]]')) === 1
  && count($query('/score-partwise/part[1]/measure[2]/note[tie[@type="stop"]]')) === 1,
  'A note crossing the bar line has matching ties.');
expectMusicXml(count($query('/score-partwise/part[2]/measure[1]/note[chord]')) === 1,
  'Simultaneous tones in one melody form a chord.');
expectMusicXml($xpath->evaluate('string(/score-partwise/part[1]/measure[2]/attributes/key/fifths)') === '4'
  && $xpath->evaluate('string(/score-partwise/part[1]/measure[2]/attributes/key/mode)') === 'dorian',
  'An explicit modal key maps to its signature and mode.');
$unkeyed = new Score();
$unkeyed->place(0, 0, 0, 3, 10, 2); // F-sharp4.
$inferred = new DOMDocument();
$inferred->loadXML(MusicXmlWriter::encode($unkeyed), LIBXML_NONET);
expectMusicXml((new DOMXPath($inferred))->evaluate('string(/score-partwise/part/measure/attributes/key/fifths)') === '1',
  'An unkeyed passage chooses the smallest signature fitting its pitches.');
$unkeyed->setSectionMusic(0, ['scale' => 'Major', 'homeNote' => 'C#']);
$explicit = new DOMDocument();
$explicit->loadXML(MusicXmlWriter::encode($unkeyed), LIBXML_NONET);
expectMusicXml((new DOMXPath($explicit))->evaluate('string(/score-partwise/part/measure/attributes/key/fifths)') === '7',
  'An explicitly sharp key keeps its sharp spelling.');
$long = new Score();
$long->place(0, 0, 0, 3, 4, 5);
$longXml = new DOMDocument();
$longXml->loadXML(MusicXmlWriter::encode($long), LIBXML_NONET);
$longQuery = new DOMXPath($longXml);
expectMusicXml($longQuery->evaluate('string(/score-partwise/part/measure/note[1]/duration)') === '4'
  && $longQuery->evaluate('string(/score-partwise/part/measure/note[2]/duration)') === '1'
  && $longQuery->query('/score-partwise/part/measure/note[1]/notations/tied[@type="start"]')->length === 1
  && $longQuery->query('/score-partwise/part/measure/note[2]/notations/tied[@type="stop"]')->length === 1,
  'Nonstandard durations split into exact tied note values.');
$empty = new DOMDocument();
$empty->loadXML(MusicXmlWriter::encode(new Score()), LIBXML_NONET);
expectMusicXml((new DOMXPath($empty))->evaluate('string(/score-partwise/part/measure/attributes/clef/sign)') === 'G',
  'An empty melody defaults to treble clef.');

echo "MusicXML checks passed\n";
