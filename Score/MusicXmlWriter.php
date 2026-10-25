<?php

namespace MADSCORE\Score;

/** Exchange the score's written pitches and half-beat timing as MusicXML. */
final class MusicXmlWriter {

  private const MODE_OFFSETS = ['Major' => 0, 'Dorian' => 2, 'Phrygian' => 4,
    'Lydian' => 5, 'Mixolydian' => 7, 'Minor' => 9, 'Locrian' => 11];
  private const TYPES = [8 => ['whole', false], 6 => ['half', true],
    4 => ['half', false], 3 => ['quarter', true], 2 => ['quarter', false], 1 => ['eighth', false]];
  private const SHARPS = ['F', 'C', 'G', 'D', 'A', 'E', 'B'];
  private const FLATS = ['B', 'E', 'A', 'D', 'G', 'C', 'F'];

  public static function write(string $path, Score $score): void {
    $data = self::encode($score);
    if (file_put_contents($path, $data) !== strlen($data)) {
      throw new \RuntimeException('Could not export MusicXML: ' . $path);
    }
  }

  public static function encode(Score $score): string {
    $parts = [];
    foreach ($score->melodies as $index => $melody) {
      if (!$score->melodyMuted($index) && Sound::drumKey($melody['instrument'] ?? Sound::legacyInstrument($melody['pattern'])) === null) {
        $parts[] = $index;
      }
    }
    if ($parts === []) {
      throw new \DomainException('MusicXML export needs a visible pitched melody.');
    }
    $starts = [0];
    foreach ($score->bars as $bar) {
      $starts[] = end($starts) + 2 * $bar['beats'];
    }
    $keys = self::keys($score, $starts);
    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
      . '<!DOCTYPE score-partwise  PUBLIC "-//Recordare//DTD MusicXML 4.0 Partwise//EN" "http://www.musicxml.org/dtds/partwise.dtd">' . "\n"
      . '<score-partwise version="4.0">' . "\n"
      . '  <work><work-title>' . self::escape($score->title) . '</work-title></work>' . "\n"
      . '  <movement-title>' . self::escape($score->title) . '</movement-title>' . "\n";
    if ($score->author !== '') {
      $xml .= '  <identification><creator type="composer">' . self::escape($score->author) . '</creator></identification>' . "\n";
    }
    $xml .= "  <part-list>\n";
    foreach ($parts as $index) {
      $id = 'P' . ($index + 1);
      $xml .= '    <score-part id="' . $id . '"><part-name>Melody ' . ($index + 1) . '</part-name></score-part>' . "\n";
    }
    $xml .= "  </part-list>\n";
    foreach ($parts as $index) {
      $clef = self::clef($score, $index);
      $xml .= '  <part id="P' . ($index + 1) . '">' . "\n";
      $previousKey = null;
      $previousBeats = null;
      $previousTempo = null;
      foreach ($score->bars as $barIndex => $bar) {
        $xml .= '    <measure number="' . ($barIndex + 1) . '">' . "\n";
        $key = $keys[$barIndex];
        if ($barIndex === 0 || $key !== $previousKey || $bar['beats'] !== $previousBeats) {
          $xml .= "      <attributes>\n";
          if ($barIndex === 0) {
            $xml .= "        <divisions>2</divisions>\n";
          }
          if ($barIndex === 0 || $key !== $previousKey) {
            $xml .= '        <key><fifths>' . $key['fifths'] . '</fifths><mode>' . $key['mode'] . '</mode></key>' . "\n";
          }
          if ($barIndex === 0 || $bar['beats'] !== $previousBeats) {
            $xml .= '        <time><beats>' . $bar['beats'] . '</beats><beat-type>4</beat-type></time>' . "\n";
          }
          if ($barIndex === 0) {
            $xml .= $clef === 'bass'
              ? "        <clef><sign>F</sign><line>4</line></clef>\n"
              : "        <clef><sign>G</sign><line>2</line></clef>\n";
          }
          $xml .= "      </attributes>\n";
        }
        $previousKey = $key;
        $previousBeats = $bar['beats'];
        if ($index === $parts[0] && ($barIndex === 0 || $bar['tempo'] !== $previousTempo)) {
          $tempo = self::escape((string)$bar['tempo']);
          $xml .= '      <direction><direction-type><metronome><beat-unit>quarter</beat-unit>'
            . '<per-minute>' . $tempo . '</per-minute></metronome></direction-type>'
            . '<sound tempo="' . $tempo . '"/></direction>' . "\n";
        }
        $previousTempo = $bar['tempo'];
        $events = [];
        foreach ($score->notes as $note) {
          if ($note['melody'] !== $index) {
            continue;
          }
          $onset = $starts[$note['bar']] + $note['slot'];
          $finish = $onset + $note['length'];
          $begin = max($onset, $starts[$barIndex]);
          $end = min($finish, $starts[$barIndex + 1]);
          if ($begin < $end) {
            $events[$begin][] = ['note' => $note, 'end' => $end,
              'fromPrevious' => $onset < $begin, 'toNext' => $finish > $end];
          }
        }
        ksort($events, SORT_NUMERIC);
        $cursor = $starts[$barIndex];
        foreach ($events as $begin => $group) {
          if ($begin > $cursor) {
            $xml .= self::fragment(null, $begin - $cursor, false, false);
          }
          $end = $group[0]['end'];
          $pieces = self::durations($end - $begin);
          foreach ($pieces as $pieceIndex => $duration) {
            foreach ($group as $chordIndex => $item) {
              $xml .= self::fragment($item['note'], $duration,
                $pieceIndex > 0 || $item['fromPrevious'],
                $pieceIndex < count($pieces) - 1 || $item['toNext'],
                $chordIndex > 0);
            }
          }
          $cursor = $end;
        }
        if ($cursor < $starts[$barIndex + 1]) {
          $xml .= self::fragment(null, $starts[$barIndex + 1] - $cursor, false, false);
        }
        $xml .= "    </measure>\n";
      }
      $xml .= "  </part>\n";
    }
    return $xml . "</score-partwise>\n";
  }

  /** Choose the staff with fewer out-of-staff steps across a melody's notes. */
  private static function clef(Score $score, int $melody): string {
    $cost = ['treble' => 0, 'bass' => 0];
    foreach ($score->notes as $note) {
      if ($note['melody'] !== $melody) {
        continue;
      }
      $octave = $note['octave'] + ($note['pitch'] === 'A' || $note['pitch'] === 'B' ? 0 : 1);
      $position = $octave * 7 + array_search($note['pitch'], ['C', 'D', 'E', 'F', 'G', 'A', 'B'], true);
      $cost['treble'] += $note['length'] * max(0, 30 - $position, $position - 38);
      $cost['bass'] += $note['length'] * max(0, 18 - $position, $position - 26);
    }
    return $cost['bass'] < $cost['treble'] ? 'bass' : 'treble';
  }

  /** Split durations into representable note values, retaining exact half beats. */
  private static function durations(int $length): array {
    $pieces = [];
    foreach (array_keys(self::TYPES) as $size) {
      while ($length >= $size) {
        $pieces[] = $size;
        $length -= $size;
      }
    }
    return $pieces;
  }

  private static function fragment(?array $note, int $length, bool $stop, bool $start, bool $chord = false): string {
    $xml = '';
    foreach (self::durations($length) as $pieceIndex => $size) {
      [$type, $dotted] = self::TYPES[$size];
      $xml .= "      <note>\n";
      if ($chord && $pieceIndex === 0) {
        $xml .= "        <chord/>\n";
      }
      if ($note === null) {
        $xml .= "        <rest/>\n";
      } else {
        $octave = $note['octave'] + ($note['pitch'] === 'A' || $note['pitch'] === 'B' ? 0 : 1);
        $xml .= '        <pitch><step>' . $note['pitch'] . '</step><alter>' . $note['alter']
          . '</alter><octave>' . $octave . '</octave></pitch>' . "\n";
      }
      $xml .= '        <duration>' . $size . '</duration>' . "\n";
      $tieStop = $note !== null && ($stop || $pieceIndex > 0);
      $tieStart = $note !== null && ($start || $pieceIndex < count(self::durations($length)) - 1);
      if ($note !== null) {
        if ($tieStop) {
          $xml .= "        <tie type=\"stop\"/>\n";
        }
        if ($tieStart) {
          $xml .= "        <tie type=\"start\"/>\n";
        }
      }
      $xml .= '        <type>' . $type . '</type>';
      if ($dotted) {
        $xml .= '<dot/>';
      }
      $xml .= "\n";
      if ($tieStop || $tieStart) {
        $xml .= "        <notations>\n";
        if ($tieStop) {
          $xml .= "          <tied type=\"stop\"/>\n";
        }
        if ($tieStart) {
          $xml .= "          <tied type=\"start\"/>\n";
        }
        $xml .= "        </notations>\n";
      }
      $xml .= "      </note>\n";
    }
    return $xml;
  }

  /** Use explicit modal keys; infer a conventional signature for each unkeyed stretch. */
  private static function keys(Score $score, array $starts): array {
    $keys = [];
    $count = count($score->bars);
    for ($bar = 0; $bar < $count;) {
      $music = $score->sectionMusic($bar);
      $end = $bar + 1;
      while ($end < $count && !array_key_exists('music', $score->bars[$end])) {
        $end++;
      }
      $key = $music === null ? self::inferredKey($score, $starts[$bar], $starts[$end]) : self::explicitKey($music);
      for (; $bar < $end; $bar++) {
        $keys[$bar] = $key;
      }
    }
    return $keys;
  }

  private static function explicitKey(array $music): array {
    $root = (MotifScoreGenerator::HOME_NOTES[$music['homeNote']] - self::MODE_OFFSETS[$music['scale']] + 12) % 12;
    $matches = [];
    for ($fifths = -7; $fifths <= 7; $fifths++) {
      if ((7 * $fifths % 12 + 12) % 12 === $root) {
        $matches[] = $fifths;
      }
    }
    if (str_contains($music['homeNote'], '#')) {
      usort($matches, static fn (int $a, int $b): int => ($b >= 0) <=> ($a >= 0));
    } else {
      usort($matches, static fn (int $a, int $b): int => abs($a) <=> abs($b));
    }
    return ['fifths' => $matches[0], 'mode' => strtolower($music['scale'])];
  }

  private static function inferredKey(Score $score, int $begin, int $end): array {
    $best = 0;
    $cost = PHP_INT_MAX;
    for ($fifths = -7; $fifths <= 7; $fifths++) {
      $candidate = 0;
      foreach ($score->notes as $note) {
        if ($score->melodyMuted($note['melody'])) {
          continue;
        }
        $instrument = $score->melodies[$note['melody']]['instrument'];
        if (Sound::drumKey($instrument) !== null) {
          continue;
        }
        $onset = $score->start($note['bar']) + $note['slot'];
        $overlap = max(0, min($end, $onset + $note['length']) - max($begin, $onset));
        if ($overlap === 0) {
          continue;
        }
        $order = $fifths >= 0 ? self::SHARPS : self::FLATS;
        $expected = in_array($note['pitch'], array_slice($order, 0, abs($fifths)), true) ? ($fifths >= 0 ? 1 : -1) : 0;
        $candidate += $overlap * (int)($note['alter'] !== $expected);
      }
      if ($candidate < $cost || ($candidate === $cost && abs($fifths) < abs($best))) {
        $best = $fifths;
        $cost = $candidate;
      }
    }
    return ['fifths' => $best, 'mode' => 'major'];
  }

  private static function escape(string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
