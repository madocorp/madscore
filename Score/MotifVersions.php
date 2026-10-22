<?php

namespace MADSCORE\Score;

/** Generated motif sections and their repeatable transformation settings. */
final class MotifVersions {

  public const ALGORITHMS = [
    'keep-opening' => 'Keep opening, new ending',
    'new-ending' => 'Change last notes',
    'transpose' => 'Transpose in scale',
    'invert' => 'Invert pitch contour',
    'reverse' => 'Reverse pitches',
    'rhythm' => 'Move half-beat splits',
  ];

  public static function validateSpec(mixed $spec, int $versionIndex): array {
    if (!is_array($spec) || !is_string($spec['algorithm'] ?? null)
      || !isset(self::ALGORITHMS[$spec['algorithm']])
      || !is_int($spec['source'] ?? null) || $spec['source'] < 0
      || $spec['source'] >= $versionIndex
      || !is_array($spec['parameters'] ?? null)
      || array_diff(array_keys($spec), ['algorithm', 'source', 'parameters']) !== []) {
      throw new \DomainException('Invalid motif version settings.');
    }
    $parameters = $spec['parameters'];
    $expected = match ($spec['algorithm']) {
      'keep-opening' => ['keepBeats'],
      'new-ending' => ['replaceNotes'],
      'transpose' => ['steps'],
      'rhythm' => ['shiftBeats'],
      default => [],
    };
    if (array_keys($parameters) !== $expected) {
      throw new \DomainException('Invalid motif version parameters.');
    }
    foreach ($parameters as $value) {
      if (!is_int($value)) {
        throw new \DomainException('Motif version parameters must be whole numbers.');
      }
    }
    $value = $parameters[$expected[0] ?? ''] ?? null;
    if (($spec['algorithm'] === 'transpose' && ($value < -3 || $value > 3 || $value === 0))
      || ($spec['algorithm'] !== 'transpose' && $value !== null && $value < 1)) {
      throw new \DomainException('Motif version parameter is outside its range.');
    }
    return $spec;
  }

  /** Split a score at its existing section boundaries. */
  private static function sections(Score $score): array {
    $starts = [];
    foreach ($score->bars as $bar => $settings) {
      if ($settings['sectionLeader']) {
        $starts[] = $bar;
      }
    }
    $sections = [];
    foreach ($starts as $index => $start) {
      $end = $starts[$index + 1] ?? count($score->bars);
      $startTime = $score->start($start);
      $endTime = $score->start($end);
      $sections[] = [
        'startBar' => $start, 'startTime' => $startTime,
        'bars' => array_slice($score->bars, $start, $end - $start),
        'notes' => array_values(array_filter($score->notes,
          static fn (array $note): bool => $note['bar'] >= $start && $note['bar'] < $end)),
        'marks' => array_values(array_filter($score->scoreDynamics,
          static fn (array $mark): bool => $mark['time'] >= $startTime && $mark['time'] < $endTime)),
      ];
    }
    return $sections;
  }

  private static function appendSection(Score $score, array $section): int {
    $index = count(self::sections($score));
    $barOffset = count($score->bars) - $section['startBar'];
    $timeOffset = $score->start(count($score->bars)) - $section['startTime'];
    foreach ($section['bars'] as $bar) {
      $score->bars[] = $bar;
    }
    foreach ($section['notes'] as $note) {
      $note['bar'] += $barOffset;
      $score->notes[] = $note;
    }
    foreach ($section['marks'] as $mark) {
      $mark['time'] += $timeOffset;
      if ($mark['end'] !== null) {
        $mark['end'] += $timeOffset;
      }
      $score->scoreDynamics[] = $mark;
    }
    return $index;
  }

  private static function midi(array $note): int {
    return 20 + 12 * $note['octave'] + Pitch::position($note);
  }

  private static function pitchForDegree(int $degree, string $scale, string $home): int {
    $octave = (int)floor($degree / 7);
    $position = (($degree % 7) + 7) % 7;
    return 60 + MotifScoreGenerator::HOME_NOTES[$home] + 12 * $octave
      + MotifScoreGenerator::SCALES[$scale][$position];
  }

  private static function degreeForPitch(int $pitch, string $scale, string $home): int {
    $closest = 0;
    $distance = PHP_INT_MAX;
    for ($degree = -40; $degree <= 40; $degree++) {
      $candidateDistance = abs(self::pitchForDegree($degree, $scale, $home) - $pitch);
      if ($candidateDistance < $distance) {
        $closest = $degree;
        $distance = $candidateDistance;
        if ($distance === 0) {
          break;
        }
      }
    }
    return $closest;
  }

  private static function setPitch(array &$note, int $pitch): void {
    $position = $pitch - 20;
    if ($position < 1 || $position > 108) {
      throw new \DomainException('The motif version is outside the pitch range.');
    }
    $note['octave'] = intdiv($position - 1, 12);
    [$note['pitch'], $note['alter']] = Pitch::spelling(($position - 1) % 12 + 1);
    $note['trill'] = 0;
    $note['glissandoToNext'] = false;
  }

  private static function canFinish(array $original, int $position, int $previous,
    int $seen, bool $changed, array $palette, int $all, array &$memo): bool {
    if ($position === count($original)) {
      return $seen === $all && $changed;
    }
    $key = "$position:$previous:$seen:" . (int)$changed;
    if (isset($memo[$key])) {
      return $memo[$key];
    }
    foreach ($palette as $index => $degree) {
      if (abs($degree - $previous) <= 3
        && self::canFinish($original, $position + 1, $degree,
          $seen | (1 << $index), $changed || $degree !== $original[$position],
          $palette, $all, $memo)) {
        return $memo[$key] = true;
      }
    }
    return $memo[$key] = false;
  }

  private static function changedSuffix(array $degrees, int $keep, callable $random): array {
    if ($keep < 1 || $keep >= count($degrees)) {
      throw new \DomainException('The version needs both a kept part and a changed part.');
    }
    $palette = array_values(array_unique($degrees));
    sort($palette);
    $all = (1 << count($palette)) - 1;
    $seen = 0;
    foreach (array_slice($degrees, 0, $keep) as $degree) {
      $seen |= 1 << array_search($degree, $palette, true);
    }
    $result = $degrees;
    $memo = [];
    for ($index = $keep; $index < count($degrees); $index++) {
      $previous = $result[$index - 1];
      $choices = [];
      foreach ($palette as $paletteIndex => $candidate) {
        $step = $candidate - $previous;
        if (abs($step) <= 3
          && self::canFinish($degrees, $index + 1, $candidate,
            $seen | (1 << $paletteIndex),
            array_slice($result, 0, $index) !== array_slice($degrees, 0, $index)
              || $candidate !== $degrees[$index], $palette, $all, $memo)) {
          for ($copy = 0; $copy < MotifScoreGenerator::STEP_WEIGHTS[$step]; $copy++) {
            $choices[] = $candidate;
          }
        }
      }
      if ($choices === []) {
        throw new \DomainException('No different ending can keep every source pitch.');
      }
      $result[$index] = $choices[$random(0, count($choices) - 1)];
      $seen |= 1 << array_search($result[$index], $palette, true);
    }
    return $result;
  }

  private static function generatedSection(Score $score, int $melody, array $spec,
    callable $random): array {
    $source = self::sections($score)[$spec['source']];
    $section = $source;
    $section['bars'][0]['sectionLeader'] = true;
    $section['bars'][0]['repeats'] = 0;
    $section['bars'][0]['note'] = '';
    $section['bars'][0]['motifVersion'] = $spec;
    foreach ($section['bars'] as $barIndex => &$bar) {
      if ($barIndex > 0) {
        unset($bar['motifVersion']);
      }
    }
    unset($bar);
    $indices = [];
    foreach ($section['notes'] as $noteIndex => $note) {
      if ($note['melody'] === $melody) {
        $indices[] = $noteIndex;
      }
    }
    usort($indices, static fn (int $a, int $b): int =>
      [$section['notes'][$a]['bar'], $section['notes'][$a]['slot']]
      <=> [$section['notes'][$b]['bar'], $section['notes'][$b]['slot']]);
    if (count($indices) < 2) {
      throw new \DomainException('A motif version needs at least two notes.');
    }
    $settings = $score->melodies[$melody]['motif'] ?? null;
    if ($settings === null) {
      throw new \DomainException('The selected melody is not a motif.');
    }
    $scale = $settings['scale'];
    $home = $settings['homeNote'];
    $degrees = array_map(static fn (int $noteIndex): int => self::degreeForPitch(
      self::midi($section['notes'][$noteIndex]), $scale, $home), $indices);
    $algorithm = $spec['algorithm'];
    $parameters = $spec['parameters'];
    if ($algorithm === 'keep-opening' || $algorithm === 'new-ending') {
      if ($algorithm === 'keep-opening') {
        $keepBeats = $parameters['keepBeats'];
        $sourceBeats = intdiv($score->start($source['startBar'] + count($source['bars']))
          - $source['startTime'], 2);
        if ($keepBeats >= $sourceBeats) {
          throw new \DomainException('Keep beats must be shorter than the source section.');
        }
        $keep = 0;
        foreach ($indices as $noteIndex) {
          $note = $section['notes'][$noteIndex];
          $time = $score->start($note['bar']) + $note['slot'] - $source['startTime'];
          if ($time >= 2 * $keepBeats) {
            break;
          }
          $keep++;
        }
      } else {
        $keep = count($indices) - $parameters['replaceNotes'];
      }
      $degrees = self::changedSuffix($degrees, $keep, $random);
    } else if ($algorithm === 'transpose') {
      $degrees = array_map(static fn (int $degree): int => $degree + $parameters['steps'], $degrees);
    } else if ($algorithm === 'invert') {
      $axis = $degrees[0];
      $degrees = array_map(static fn (int $degree): int => 2 * $axis - $degree, $degrees);
    } else if ($algorithm === 'reverse') {
      $degrees = array_reverse($degrees);
    } else if ($algorithm === 'rhythm') {
      $beats = $settings['beats'];
      $split = [];
      foreach ($indices as $noteIndex) {
        $note = $section['notes'][$noteIndex];
        $time = $score->start($note['bar']) + $note['slot'] - $source['startTime'];
        if ($note['length'] === 1 && $time < 2 * $beats) {
          $split[intdiv($time, 2)] = true;
        }
      }
      if ($split === [] || $parameters['shiftBeats'] >= $beats) {
        throw new \DomainException('Rhythm variation needs half-beat splits and a shorter shift.');
      }
      $shifted = [];
      foreach (array_keys($split) as $beat) {
        $shifted[($beat + $parameters['shiftBeats']) % $beats] = true;
      }
      $originalSplits = array_keys($split);
      $shiftedSplits = array_keys($shifted);
      sort($originalSplits);
      sort($shiftedSplits);
      if ($originalSplits === $shiftedSplits) {
        throw new \DomainException('This shift leaves the rhythm unchanged.');
      }
      $times = [];
      for ($beat = 0; $beat < $beats; $beat++) {
        $times[] = [2 * $beat, isset($shifted[$beat]) ? 1 : 2];
        if (isset($shifted[$beat])) {
          $times[] = [2 * $beat + 1, 1];
        }
      }
      if (count($times) !== count($indices)) {
        throw new \DomainException('The source rhythm cannot be shifted.');
      }
      foreach ($indices as $position => $noteIndex) {
        [$time, $length] = $times[$position];
        $bar = 0;
        while ($time >= 2 * $section['bars'][$bar]['beats']) {
          $time -= 2 * $section['bars'][$bar]['beats'];
          $bar++;
        }
        $section['notes'][$noteIndex]['bar'] = $source['startBar'] + $bar;
        $section['notes'][$noteIndex]['slot'] = $time;
        $section['notes'][$noteIndex]['length'] = $length;
      }
    }
    foreach ($indices as $position => $noteIndex) {
      self::setPitch($section['notes'][$noteIndex],
        self::pitchForDegree($degrees[$position], $scale, $home));
    }
    return $section;
  }

  public static function append(Score $score, int $melody, array $spec,
    ?callable $random = null): array {
    $score->assertMelodyEditable($melody);
    $index = count(self::sections($score));
    $spec = self::validateSpec($spec, $index);
    $random ??= random_int(...);
    $next = clone $score;
    $section = self::generatedSection($next, $melody, $spec, $random);
    self::appendSection($next, $section);
    return ['score' => Score::fromData($next->data()), 'version' => $index];
  }

  /** Rebuild generated sections from saved settings; copy manual sections verbatim. */
  public static function regenerate(Score $score, ?int $melody, int $beats,
    int $pitchCount, ?string $scale = null, ?string $homeNote = null,
    ?callable $random = null): array {
    $old = self::sections($score);
    $fresh = new Score(count($score->melodies));
    $fresh->title = $score->title;
    $fresh->author = $score->author;
    $fresh->catalog = $score->catalog;
    $fresh->melodies = $score->melodies;
    $fresh->bars[0]['tempo'] = $score->bars[0]['tempo'];
    $base = MotifScoreGenerator::place($fresh, $beats, $pitchCount, $melody,
      $random, $scale, $homeNote);
    $next = $base['score'];
    $next->bars[0]['note'] = $old[0]['bars'][0]['note'];
    $random ??= random_int(...);
    foreach (array_slice($old, 1) as $section) {
      $spec = $section['bars'][0]['motifVersion'] ?? null;
      if ($spec !== null) {
        $section = self::generatedSection($next, $base['melody'], $spec, $random);
      }
      self::appendSection($next, $section);
    }
    $base['score'] = Score::fromData($next->data());
    return $base;
  }
}
