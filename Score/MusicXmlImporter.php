<?php

namespace MADSCORE\Score;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/** Import timed notes from partwise MusicXML into MadScore's half-beat grid. */
final class MusicXmlImporter {
  private const MAX_XML_BYTES = 33554432;
  private const MAX_TICKS_PER_QUARTER = 1000000;
  private const MODE_OFFSETS = ['major' => ['Major', 0], 'ionian' => ['Major', 0],
    'dorian' => ['Dorian', 2], 'phrygian' => ['Phrygian', 4],
    'lydian' => ['Lydian', 5], 'mixolydian' => ['Mixolydian', 7],
    'minor' => ['Minor', 9], 'aeolian' => ['Minor', 9], 'locrian' => ['Locrian', 11]];
  private const PITCH_CLASSES = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5,
    'G' => 7, 'A' => 9, 'B' => 11];

  /** Return the imported score and names of notation features left out. */
  public static function read(string $path): array {
    $xml = self::source($path);
    $document = self::document($xml);
    if ($document->documentElement?->localName !== 'score-partwise') {
      throw new \DomainException('MusicXML import needs a partwise score.');
    }
    $quarter = self::quarterTicks($document);
    $parts = self::children($document->documentElement, 'part');
    if ($parts === []) {
      throw new \DomainException('The MusicXML score has no parts.');
    }
    $rawBars = [];
    $events = [];
    $voices = [];
    $tempos = [];
    $keys = [];
    $meters = [];
    $warnings = [];
    $barCount = null;
    foreach ($parts as $partIndex => $part) {
      $measures = self::children($part, 'measure');
      if ($barCount === null) {
        $barCount = count($measures);
      } else if ($barCount !== count($measures)) {
        throw new \DomainException('MusicXML parts have different measure counts.');
      }
      if ($measures === []) {
        throw new \DomainException('A MusicXML part has no measures.');
      }
      $voices[$partIndex] = [];
      $divisions = 1;
      $beats = 4;
      $beatType = 4;
      $transpose = 0;
      foreach ($measures as $bar => $measure) {
        if ($partIndex > 0) {
          [$beats, $beatType] = $meters[$bar];
        }
        $cursor = 0;
        $furthest = 0;
        $lastStart = null;
        $lastVoice = null;
        foreach ($measure->childNodes as $node) {
          if (!$node instanceof DOMElement) {
            continue;
          }
          switch ($node->localName) {
            case 'attributes':
              if (($shift = self::child($node, 'transpose')) !== null) {
                $transpose = self::transposition($shift);
              }
              if (($value = self::value($node, 'divisions')) !== null) {
                $divisions = self::positiveInt($value, 'divisions');
              }
              if (($time = self::child($node, 'time')) !== null) {
                if ($cursor !== 0) {
                  throw new \DomainException('Mid-measure time changes are not supported.');
                }
                $beats = self::positiveInt(self::value($time, 'beats'), 'time signature');
                $beatType = self::positiveInt(self::value($time, 'beat-type'), 'time signature');
              }
              if (($key = self::child($node, 'key')) !== null) {
                if ($cursor !== 0) {
                  throw new \DomainException('Mid-measure key changes are not supported.');
                }
                $music = self::key($key, $warnings, $transpose);
                if (array_key_exists($bar, $keys) && $keys[$bar] !== $music) {
                  throw new \DomainException('MusicXML parts have conflicting keys in measure ' . ($bar + 1) . '.');
                }
                $keys[$bar] = $music;
              }
              break;
            case 'direction':
              $tempo = self::directionTempo($node, $warnings);
              $directionType = self::child($node, 'direction-type');
              if ($directionType !== null) {
                foreach ($directionType->childNodes as $mark) {
                  if ($mark instanceof DOMElement && $mark->localName !== 'metronome') {
                    $warnings[] = 'other directions';
                  }
                }
              }
              if ($tempo !== null) {
                $offset = self::value($node, 'offset') ?? '0';
                $soundOffset = self::child($node, 'sound');
                $offset = $soundOffset === null ? $offset : (self::value($soundOffset, 'offset') ?? $offset);
                if ($cursor !== 0 || (float)$offset !== 0.0) {
                  throw new \DomainException('Mid-measure tempo changes are not supported.');
                }
                if (isset($tempos[$bar]) && abs($tempos[$bar] - $tempo) > 0.000001) {
                  throw new \DomainException('MusicXML parts have conflicting tempos in measure ' . ($bar + 1) . '.');
                }
                $tempos[$bar] = $tempo;
              } else {
                $warnings[] = 'other directions';
              }
              break;
            case 'sound':
              $tempoText = $node->getAttribute('tempo');
              if ($tempoText !== '') {
                if ($cursor !== 0 || (float)(self::value($node, 'offset') ?? '0') !== 0.0) {
                  throw new \DomainException('Mid-measure tempo changes are not supported.');
                }
                $tempo = self::tempo($tempoText);
                if (isset($tempos[$bar]) && abs($tempos[$bar] - $tempo) > 0.000001) {
                  throw new \DomainException('MusicXML parts have conflicting tempos in measure ' . ($bar + 1) . '.');
                }
                $tempos[$bar] = $tempo;
              }
              break;
            case 'note':
              if (self::child($node, 'grace') !== null) {
                $warnings[] = 'grace notes';
                break;
              }
              $duration = self::ticks(self::value($node, 'duration'), $divisions, $quarter);
              $voice = self::value($node, 'voice') ?? '1';
              $chord = self::child($node, 'chord') !== null;
              if ($chord && ($lastStart === null || $lastVoice !== $voice)) {
                throw new \DomainException('A MusicXML chord has no preceding note in its voice.');
              }
              $onset = $chord ? $lastStart : $cursor;
              if (!$chord) {
                $lastStart = $onset;
                $lastVoice = $voice;
                $cursor += $duration;
                $furthest = max($furthest, $cursor);
              }
              if (self::child($node, 'rest') !== null) {
                break;
              }
              $pitch = self::child($node, 'pitch');
              if ($pitch === null) {
                $warnings[] = 'unpitched notes';
                break;
              }
              $voices[$partIndex][$voice] = true;
              [$octave, $position, $written] = self::pitch($pitch, $transpose);
              $tieStart = false;
              $tieStop = false;
              foreach (self::children($node, 'tie') as $tie) {
                $tieStart = $tieStart || $tie->getAttribute('type') === 'start';
                $tieStop = $tieStop || $tie->getAttribute('type') === 'stop';
              }
              if (($notations = self::child($node, 'notations')) !== null) {
                foreach ($notations->childNodes as $mark) {
                  if ($mark instanceof DOMElement && $mark->localName !== 'tied') {
                    $warnings[] = 'note marks';
                  }
                }
                if (!$tieStart && !$tieStop) {
                  foreach (self::children($notations, 'tied') as $tie) {
                    $tieStart = $tieStart || $tie->getAttribute('type') === 'start';
                    $tieStop = $tieStop || $tie->getAttribute('type') === 'stop';
                  }
                }
              }
              if (self::child($node, 'lyric') !== null) {
                $warnings[] = 'lyrics';
              }
              if (self::child($node, 'time-modification') !== null) {
                $warnings[] = 'tuplet notation';
              }
              $events[] = compact('partIndex', 'bar', 'voice', 'onset', 'duration',
                'octave', 'position', 'written', 'tieStart', 'tieStop');
              break;
            case 'backup':
              $cursor -= self::ticks(self::value($node, 'duration'), $divisions, $quarter);
              if ($cursor < 0) {
                throw new \DomainException('MusicXML backup moves before the measure.');
              }
              $lastStart = null;
              break;
            case 'forward':
              $cursor += self::ticks(self::value($node, 'duration'), $divisions, $quarter);
              $furthest = max($furthest, $cursor);
              $lastStart = null;
              break;
            case 'barline':
              if (self::child($node, 'repeat') !== null || self::child($node, 'ending') !== null) {
                $warnings[] = 'repeats and endings';
              }
              break;
            case 'harmony':
              $warnings[] = 'chord symbols';
              break;
          }
        }
        $length = self::measureTicks($beats, $beatType, $quarter);
        if ($furthest > $length) {
          throw new \DomainException('Notes extend beyond MusicXML measure ' . ($bar + 1) . '.');
        }
        if ($measure->getAttribute('implicit') === 'yes' && $furthest > 0 && $furthest < $length) {
          $length = $furthest;
        }
        if ($length % 2 !== 0) {
          throw new \DomainException('MusicXML pickup measure cannot fit MadScore beats.');
        }
        if (isset($rawBars[$bar]) && $rawBars[$bar] !== $length) {
          throw new \DomainException('MusicXML parts have conflicting time signatures in measure ' . ($bar + 1) . '.');
        }
        $rawBars[$bar] = $length;
        if ($partIndex === 0) {
          $meters[$bar] = [$beats, $beatType];
        }
      }
      if ($voices[$partIndex] === []) {
        $voices[$partIndex]['1'] = true;
      }
    }
    $grids = [];
    $starts = [0];
    foreach ($rawBars as $bar => $length) {
      $grid = self::gcd(intdiv($quarter, 2), intdiv($length, 2));
      foreach ($events as $event) {
        if ($event['bar'] === $bar) {
          $grid = self::gcd($grid, $event['onset']);
          $grid = self::gcd($grid, $event['onset'] + $event['duration']);
        }
      }
      $beatCount = intdiv($length, 2 * $grid);
      if ($beatCount < 1 || $beatCount > 32) {
        throw new \DomainException('Measure ' . ($bar + 1) . ' needs ' . $beatCount
          . ' MadScore beats (maximum 32).');
      }
      $grids[$bar] = $grid;
      $starts[] = end($starts) + $beatCount * 2;
    }
    $melodyMap = [];
    $melodyCount = 0;
    foreach ($voices as $partIndex => $partVoices) {
      foreach ($partVoices as $voice => $_) {
        $melodyMap[$partIndex][(string)$voice] = $melodyCount++;
      }
    }
    $score = new Score($melodyCount);
    $score->title = self::metadata($document, 'movement-title')
      ?? self::metadata($document, 'work-title') ?? 'Untitled';
    $creator = (new DOMXPath($document))->query('/*[local-name()="score-partwise"]/*[local-name()="identification"]/*[local-name()="creator"]');
    $score->author = $creator?->length ? self::clean($creator->item(0)->textContent) : '';
    $score->bars = [];
    $xmlTempo = 120.0;
    foreach ($rawBars as $bar => $length) {
      $xmlTempo = $tempos[$bar] ?? $xmlTempo;
      $nativeTempo = $xmlTempo * $quarter / (2 * $grids[$bar]);
      $score->bars[] = ['beats' => intdiv($length, 2 * $grids[$bar]),
        'tempo' => self::decimal($nativeTempo), 'sectionLeader' => $bar === 0,
        'repeats' => 0, 'note' => ''];
      if (array_key_exists($bar, $keys)) {
        $score->bars[$bar]['music'] = $keys[$bar];
      }
    }
    $mapped = [];
    foreach ($events as $event) {
      $bar = $event['bar'];
      $grid = $grids[$bar];
      if ($event['onset'] + $event['duration'] > $rawBars[$bar]) {
        throw new \DomainException('A note extends beyond MusicXML measure ' . ($bar + 1) . '.');
      }
      $event['melody'] = $melodyMap[$event['partIndex']][(string)$event['voice']];
      $event['time'] = $starts[$bar] + intdiv($event['onset'], $grid);
      $event['length'] = intdiv($event['duration'], $grid);
      $mapped[] = $event;
    }
    usort($mapped, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
    $open = [];
    $notes = [];
    foreach ($mapped as $event) {
      $id = $event['melody'] . ':' . (12 * $event['octave'] + $event['position']);
      if ($event['tieStop']) {
        $previous = $open[$id] ?? null;
        if ($previous === null || $notes[$previous]['time'] + $notes[$previous]['length'] !== $event['time']) {
          throw new \DomainException('A MusicXML tie does not join adjacent notes.');
        }
        $notes[$previous]['length'] += $event['length'];
        unset($open[$id]);
        if ($event['tieStart']) {
          $open[$id] = $previous;
        }
      } else {
        $notes[] = $event;
        if ($event['tieStart']) {
          $open[$id] = count($notes) - 1;
        }
      }
    }
    if ($open !== []) {
      throw new \DomainException('A MusicXML tie has no ending note.');
    }
    usort($notes, static fn (array $a, array $b): int => [$a['time'], $a['melody'], $a['position']]
      <=> [$b['time'], $b['melody'], $b['position']]);
    $lanes = [];
    foreach ($notes as $note) {
      if ($note['length'] > 198) {
        throw new \DomainException('An imported note exceeds the 198 half-beat limit.');
      }
      $bar = $note['bar'];
      $slot = $note['time'] - $starts[$bar];
      $sourceMelody = $note['melody'];
      $lanes[$sourceMelody] ??= [['melody' => $sourceMelody, 'start' => -1,
        'end' => -1, 'length' => 0]];
      $laneIndex = null;
      foreach ($lanes[$sourceMelody] as $candidate => $lane) {
        if ($lane['start'] === $note['time'] && $lane['length'] === $note['length']) {
          $laneIndex = $candidate;
          break;
        }
      }
      if ($laneIndex === null) {
        foreach ($lanes[$sourceMelody] as $candidate => $lane) {
          if ($lane['end'] <= $note['time']) {
            $laneIndex = $candidate;
            break;
          }
        }
      }
      if ($laneIndex === null) {
        $laneIndex = count($lanes[$sourceMelody]);
        $lanes[$sourceMelody][] = ['melody' => $score->addMelody(), 'start' => -1,
          'end' => -1, 'length' => 0];
      }
      $lanes[$sourceMelody][$laneIndex]['start'] = $note['time'];
      $lanes[$sourceMelody][$laneIndex]['end'] = $note['time'] + $note['length'];
      $lanes[$sourceMelody][$laneIndex]['length'] = $note['length'];
      $note['melody'] = $lanes[$sourceMelody][$laneIndex]['melody'];
      $at = $score->noteAt($note['melody'], $bar, $slot);
      if ($at === null) {
        $index = $score->place($note['melody'], $bar, $slot,
          $note['octave'], $note['position'], $note['length']);
      } else if ($score->notes[$at]['bar'] === $bar && $score->notes[$at]['slot'] === $slot) {
        $index = $score->addChordTone($note['melody'], $bar, $slot,
          $note['octave'], $note['position'], $note['length']);
      } else {
        throw new \DomainException('Overlapping voices in a MusicXML part need separate voice labels.');
      }
      if ($note['written'] !== null) {
        [$score->notes[$index]['octave'], $score->notes[$index]['pitch'],
          $score->notes[$index]['alter']] = $note['written'];
      } else {
        $warnings[] = 'enharmonic spellings outside MadScore range';
      }
    }
    return [Score::fromData($score->data()), array_values(array_unique($warnings))];
  }

  private static function source(string $path): string {
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'mxl') {
      $xml = file_get_contents($path);
    } else {
      $zip = new ZipArchive();
      if ($zip->open($path) !== true) {
        throw new \DomainException('Invalid compressed MusicXML file.');
      }
      try {
        $container = self::document($zip->getFromName('META-INF/container.xml') ?: '');
        $root = (new DOMXPath($container))->query('//*[local-name()="rootfile"]')->item(0);
        $name = $root instanceof DOMElement ? $root->getAttribute('full-path') : '';
        if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
          throw new \DomainException('Compressed MusicXML has no valid root file.');
        }
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > self::MAX_XML_BYTES) {
          throw new \DomainException('Compressed MusicXML exceeds 32 MB or has no root score.');
        }
        $xml = $zip->getFromName($name);
      } finally {
        $zip->close();
      }
    }
    if (!is_string($xml) || $xml === '' || strlen($xml) > self::MAX_XML_BYTES) {
      throw new \DomainException('MusicXML is empty or exceeds 32 MB.');
    }
    return $xml;
  }

  private static function document(string $xml): DOMDocument {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
      if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new \DomainException('Invalid MusicXML document.');
      }
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    return $document;
  }

  private static function quarterTicks(DOMDocument $document): int {
    $ticks = 2;
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//*[local-name()="divisions"]') as $node) {
      $ticks = self::lcm($ticks, 2 * self::positiveInt($node->textContent, 'divisions'));
    }
    foreach ($xpath->query('//*[local-name()="beat-type"]') as $node) {
      $type = self::positiveInt($node->textContent, 'time signature');
      $ticks = self::lcm($ticks, intdiv($type, self::gcd($type, 2)));
    }
    if ($ticks > self::MAX_TICKS_PER_QUARTER) {
      throw new \DomainException('MusicXML timing resolution is too fine to import.');
    }
    return $ticks;
  }

  private static function child(DOMNode $parent, string $name): ?DOMElement {
    foreach ($parent->childNodes as $node) {
      if ($node instanceof DOMElement && $node->localName === $name) {
        return $node;
      }
    }
    return null;
  }

  private static function children(DOMNode $parent, string $name): array {
    $result = [];
    foreach ($parent->childNodes as $node) {
      if ($node instanceof DOMElement && $node->localName === $name) {
        $result[] = $node;
      }
    }
    return $result;
  }

  private static function value(DOMNode $parent, string $name): ?string {
    return ($node = self::child($parent, $name)) === null ? null : trim($node->textContent);
  }

  private static function positiveInt(?string $value, string $label): int {
    if ($value === null || preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (float)$value > 1000000) {
      throw new \DomainException('Invalid MusicXML ' . $label . '.');
    }
    return (int)$value;
  }

  private static function ticks(?string $value, int $divisions, int $quarter): int {
    $duration = self::positiveInt($value, 'duration');
    return intdiv($duration * $quarter, $divisions);
  }

  private static function measureTicks(int $beats, int $beatType, int $quarter): int {
    $length = 4 * $beats * $quarter;
    if ($length % $beatType !== 0 || intdiv($length, $beatType) % 2 !== 0) {
      throw new \DomainException('This MusicXML time signature cannot fit MadScore bars.');
    }
    return intdiv($length, $beatType);
  }

  private static function transposition(DOMElement $transpose): int {
    $chromatic = self::value($transpose, 'chromatic');
    $octave = self::value($transpose, 'octave-change') ?? '0';
    if ($chromatic === null || preg_match('/^-?[0-9]+$/D', $chromatic) !== 1
      || preg_match('/^-?[0-9]+$/D', $octave) !== 1
      || abs((int)$chromatic) > 24 || abs((int)$octave) > 8
      || self::child($transpose, 'double') !== null
      || $transpose->hasAttribute('number')) {
      throw new \DomainException('Unsupported MusicXML instrument transposition.');
    }
    return (int)$chromatic + 12 * (int)$octave;
  }

  private static function pitch(DOMElement $pitch, int $transpose = 0): array {
    $step = self::value($pitch, 'step');
    $octave = self::value($pitch, 'octave');
    $alter = self::value($pitch, 'alter') ?? '0';
    if (!isset(self::PITCH_CLASSES[$step]) || preg_match('/^-?[0-9]+$/D', $octave ?? '') !== 1
      || preg_match('/^-?[0-9]+$/D', $alter) !== 1) {
      throw new \DomainException('Unsupported MusicXML pitch or microtone.');
    }
    $octave = (int)$octave;
    $alter = (int)$alter;
    $global = 12 * ($octave + 1) + self::PITCH_CLASSES[$step] + $alter
      + $transpose - 20;
    if ($global < 1 || $global > 108) {
      throw new \DomainException('A MusicXML pitch is outside the MadScore range.');
    }
    $band = $global % 12 === 0 ? intdiv($global, 12) - 1 : intdiv($global, 12);
    $position = $global - 12 * $band;
    $writtenBand = $octave - (in_array($step, ['A', 'B'], true) ? 0 : 1);
    $writtenPosition = Pitch::naturalPosition($step) + $alter;
    $written = $transpose === 0
      ? (abs($alter) <= 1 && $writtenBand >= 0 && $writtenBand <= 8
        && $writtenPosition >= 1 && $writtenPosition <= 12
        ? [$writtenBand, $step, $alter] : null)
      : [$band, ...Pitch::spelling($position)];
    return [$band, $position, $written];
  }

  private static function key(DOMElement $key, array &$warnings, int $transpose = 0): ?array {
    $fifths = self::value($key, 'fifths');
    $mode = strtolower(self::value($key, 'mode') ?? 'major');
    if ($fifths === null || preg_match('/^-?[0-9]+$/D', $fifths) !== 1 || abs((int)$fifths) > 7
      || !isset(self::MODE_OFFSETS[$mode])) {
      $warnings[] = 'nonstandard key signatures';
      return null;
    }
    [$scale, $offset] = self::MODE_OFFSETS[$mode];
    $homeClass = (((int)$fifths * 7 + $offset + $transpose) % 12 + 12) % 12;
    $home = array_search($homeClass, MotifScoreGenerator::HOME_NOTES, true);
    return ['scale' => $scale, 'homeNote' => $home];
  }

  private static function directionTempo(DOMElement $direction, array &$warnings): ?float {
    $sound = self::child($direction, 'sound');
    $value = $sound?->getAttribute('tempo');
    $soundTempo = $value !== null && $value !== '' ? self::tempo($value) : null;
    $type = self::child($direction, 'direction-type');
    $mark = $type === null ? null : self::child($type, 'metronome');
    if ($mark === null) {
      return $soundTempo;
    }
    $perMinute = self::value($mark, 'per-minute');
    $unit = self::value($mark, 'beat-unit');
    $length = ['whole' => 4, 'half' => 2, 'quarter' => 1,
      'eighth' => 0.5, '16th' => 0.25, '32nd' => 0.125][$unit] ?? null;
    if ($perMinute === null || $length === null) {
      return $soundTempo;
    }
    $length *= 1.5 ** count(self::children($mark, 'beat-unit-dot'));
    if (preg_match('/^(?:c\.?|ca\.?|circa|approx\.?|~)\s*([0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/iD',
      $perMinute, $match) === 1) {
      $warnings[] = 'approximate tempos';
      $perMinute = $match[1];
    } else if (preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $perMinute) !== 1) {
      $warnings[] = 'unreadable tempo marks';
      return $soundTempo;
    }
    return $soundTempo ?? self::tempo($perMinute) * $length;
  }

  private static function tempo(string $value): float {
    if (preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value) !== 1
      || !is_finite((float)$value) || (float)$value <= 0) {
      throw new \DomainException('Invalid MusicXML tempo.');
    }
    return (float)$value;
  }

  private static function metadata(DOMDocument $document, string $name): ?string {
    $node = (new DOMXPath($document))->query('/*[local-name()="score-partwise"]/*[local-name()="' . $name . '"]')->item(0);
    if ($node === null && $name === 'work-title') {
      $node = (new DOMXPath($document))->query('/*[local-name()="score-partwise"]/*[local-name()="work"]/*[local-name()="work-title"]')->item(0);
    }
    return $node === null ? null : self::clean($node->textContent);
  }

  private static function clean(string $value): string {
    return trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value));
  }

  private static function decimal(float $value): string {
    if (!is_finite($value) || $value <= 0) {
      throw new \DomainException('MusicXML tempo cannot fit MadScore.');
    }
    $formatted = rtrim(rtrim(sprintf('%.8f', $value), '0'), '.');
    if ($formatted === '0') {
      throw new \DomainException('MusicXML tempo is too small to represent.');
    }
    return $formatted;
  }

  private static function gcd(int $a, int $b): int {
    while ($b !== 0) {
      [$a, $b] = [$b, $a % $b];
    }
    return $a;
  }

  private static function lcm(int $a, int $b): int {
    $result = intdiv($a, self::gcd($a, $b)) * $b;
    if ($result > self::MAX_TICKS_PER_QUARTER) {
      throw new \DomainException('MusicXML timing resolution is too fine to import.');
    }
    return $result;
  }
}
