<?php

namespace MADSCORE\Score;

/** Generated motif sections and their repeatable transformation settings. */
final class MotifVersions {

  private const REGENERATION_ATTEMPTS = 12;

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
      || !is_array($spec['parameters'] ?? null)
      || array_diff(array_keys($spec), ['algorithm', 'source', 'parameters']) !== []) {
      throw new \DomainException('Invalid motif version settings.');
    }
    if ($spec['algorithm'] === 'generate') {
      $settings = $spec['parameters'];
      if (is_string($settings['type'] ?? null)) {
        $settings['type'] = MotifScoreGenerator::canonicalType($settings['type']);
      }
      if (!array_key_exists('source', $spec) || $spec['source'] !== null
        || array_keys($settings) !== ['beats', 'pitchCount', 'scale', 'homeNote', 'type']
        || !is_int($settings['beats']) || $settings['beats'] < 3 || $settings['beats'] > 32
        || !is_int($settings['pitchCount']) || $settings['pitchCount'] < 1
        || $settings['pitchCount'] > 7 || $settings['pitchCount'] > $settings['beats']
        || ($settings['scale'] !== null && (!is_string($settings['scale'])
          || !isset(MotifScoreGenerator::SCALES[$settings['scale']])))
        || ($settings['homeNote'] !== null && (!is_string($settings['homeNote'])
          || !isset(MotifScoreGenerator::HOME_NOTES[$settings['homeNote']])))
        || !is_string($settings['type'] ?? null)
        || !isset(MotifScoreGenerator::TYPES[$settings['type']])) {
        throw new \DomainException('Invalid generated section settings.');
      }
      $spec['parameters'] = $settings;
      return $spec;
    }
    if (!isset(self::ALGORITHMS[$spec['algorithm']])
      || !is_int($spec['source'] ?? null) || $spec['source'] < 0
      || $spec['source'] >= $versionIndex) {
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
    $nextChordId = max([0, ...array_map(static fn (array $note): int =>
      $note['chord']['id'] ?? 0, $score->notes)]) + 1;
    $chordIds = [];
    foreach ($section['bars'] as $bar) {
      $score->bars[] = $bar;
    }
    foreach ($section['notes'] as $note) {
      $note['bar'] += $barOffset;
      if (isset($note['chord'])) {
        $oldId = $note['chord']['id'];
        $note['chord']['id'] = $chordIds[$oldId] ??= $nextChordId++;
      }
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
    if ($spec['algorithm'] === 'generate') {
      $settings = $spec['parameters'];
      $fresh = new Score(count($score->melodies));
      $fresh->melodies = $score->melodies;
      $made = MotifScoreGenerator::place($fresh, $settings['beats'],
        $settings['pitchCount'], $melody, $random, $settings['scale'],
        $settings['homeNote'], $settings['type']);
      $generated = $made['score'];
      $generated->setSectionMusic(0, ['scale' => $made['motif']['scale'],
        'homeNote' => $made['motif']['homeNote']]);
      $generated->bars[0]['motifVersion'] = $spec;
      return self::sections($generated)[0];
    }
    $source = self::sections($score)[$spec['source']];
    $section = $source;
    $section['bars'][0]['sectionLeader'] = true;
    $section['bars'][0]['repeats'] = 0;
    $section['bars'][0]['note'] = '';
    $sourceMusic = $source['bars'][0]['music'] ?? $score->sectionMusic($source['startBar']);
    if ($sourceMusic !== null) {
      $section['bars'][0]['music'] = $sourceMusic;
    }
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
    $scale = $sourceMusic['scale'] ?? $settings['scale'];
    $home = $sourceMusic['homeNote'] ?? $settings['homeNote'];
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
      $beats = intdiv($score->start($source['startBar'] + count($source['bars']))
        - $source['startTime'], 2);
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

  /** Compare the musical content of a section independent of its score position. */
  private static function sectionSignature(array $section, int $melody): array {
    $notes = [];
    foreach ($section['notes'] as $note) {
      if ($note['melody'] === $melody) {
        $notes[] = [$note['bar'] - $section['startBar'], $note['slot'],
          $note['octave'], $note['pitch'], $note['alter'], $note['length']];
      }
    }
    sort($notes);
    return [array_column($section['bars'], 'beats'), $notes];
  }

  /** Redraw the original until its notes or random musical settings change. */
  private static function redrawBase(Score $fresh, Score $previous, array $old,
    int $beats, int $pitchCount, ?int $melody, callable $random,
    ?string $scale, ?string $homeNote, string $type = 'random-step'): array {
    $oldMotif = $melody === null ? null : ($previous->melodies[$melody]['motif'] ?? null);
    $oldSignature = $oldMotif === null ? null : self::sectionSignature($old[0], $melody);
    for ($attempt = 0; $attempt < self::REGENERATION_ATTEMPTS; $attempt++) {
      $result = MotifScoreGenerator::place($fresh, $beats, $pitchCount,
        $melody, $random, $scale, $homeNote, $type);
      if ($oldSignature === null) {
        return $result;
      }
      $newMotif = $result['score']->melodies[$result['melody']]['motif'];
      if ($newMotif['scale'] !== $oldMotif['scale']
        || $newMotif['homeNote'] !== $oldMotif['homeNote']
        || self::sectionSignature(self::sections($result['score'])[0], $result['melody'])
          !== $oldSignature) {
        return $result;
      }
    }
    return $result;
  }

  /** Random ending algorithms retry a repeated result within a fixed limit. */
  private static function redrawVersion(Score $score, int $melody, array $spec,
    array $previous, callable $random): array {
    if (!in_array($spec['algorithm'], ['keep-opening', 'new-ending', 'generate'], true)) {
      return self::generatedSection($score, $melody, $spec, $random);
    }
    $oldSignature = self::sectionSignature($previous, $melody);
    for ($attempt = 0; $attempt < self::REGENERATION_ATTEMPTS; $attempt++) {
      $section = self::generatedSection($score, $melody, $spec, $random);
      if (self::sectionSignature($section, $melody) !== $oldSignature) {
        return $section;
      }
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

  /** Regenerate one section and every generated section derived from it. */
  public static function regenerateSection(Score $score, int $melody, int $sectionIndex,
    ?callable $random = null): Score {
    $score->assertMelodyEditable($melody);
    $old = self::sections($score);
    if (!isset($old[$sectionIndex])) {
      throw new \DomainException('The selected section does not exist.');
    }
    $motif = $score->melodies[$melody]['motif'] ?? null;
    if ($motif === null) {
      throw new \DomainException('The selected melody is not a motif.');
    }
    if ($sectionIndex > 0 && !isset($old[$sectionIndex]['bars'][0]['motifVersion'])) {
      throw new \DomainException('This section was added by hand and cannot be regenerated.');
    }
    $random ??= random_int(...);
    $next = new Score(count($score->melodies));
    $next->title = $score->title;
    $next->author = $score->author;
    $next->catalog = $score->catalog;
    $next->melodies = $score->melodies;
    $rebuilt = [$sectionIndex => true];
    if ($sectionIndex === 0) {
      $next->bars[0]['tempo'] = $old[0]['bars'][0]['tempo'];
      $next = self::redrawBase($next, $score, $old, $motif['beats'], $motif['pitchCount'],
        $melody, $random,
        ($motif['randomScale'] ?? false) ? null : $motif['scale'],
        ($motif['randomHomeNote'] ?? false) ? null : $motif['homeNote'],
        $motif['type'] ?? 'random-step')['score'];
      $newMotif = $next->melodies[$melody]['motif'];
      $next->setSectionMusic(0, ['scale' => $newMotif['scale'],
        'homeNote' => $newMotif['homeNote']]);
      $next->bars[0]['note'] = $old[0]['bars'][0]['note'];
    } else {
      $next->bars = $next->notes = $next->scoreDynamics = [];
      self::appendSection($next, $old[0]);
    }
    foreach (array_slice($old, 1, null, true) as $index => $section) {
      $spec = $section['bars'][0]['motifVersion'] ?? null;
      if ($spec !== null && ($index === $sectionIndex || isset($rebuilt[$spec['source']]))) {
        $section = self::redrawVersion($next, $melody, $spec, $section, $random);
        $rebuilt[$index] = true;
      }
      self::appendSection($next, $section);
    }
    return Score::fromData($next->data());
  }

  /** Rebuild generated sections from saved settings; copy manual sections verbatim. */
  public static function regenerate(Score $score, ?int $melody, int $beats,
    int $pitchCount, ?string $scale = null, ?string $homeNote = null,
    ?callable $random = null, string $type = 'random-step'): array {
    $old = self::sections($score);
    $fresh = new Score(count($score->melodies));
    $fresh->title = $score->title;
    $fresh->author = $score->author;
    $fresh->catalog = $score->catalog;
    $fresh->melodies = $score->melodies;
    $fresh->bars[0]['tempo'] = $score->bars[0]['tempo'];
    $random ??= random_int(...);
    $base = self::redrawBase($fresh, $score, $old, $beats, $pitchCount,
      $melody, $random, $scale, $homeNote, $type);
    $next = $base['score'];
    $newMotif = $next->melodies[$base['melody']]['motif'];
    $next->setSectionMusic(0, ['scale' => $newMotif['scale'],
      'homeNote' => $newMotif['homeNote']]);
    $next->bars[0]['note'] = $old[0]['bars'][0]['note'];
    $rebuilt = [0 => true];
    foreach (array_slice($old, 1, null, true) as $index => $section) {
      $spec = $section['bars'][0]['motifVersion'] ?? null;
      if ($spec !== null && $spec['source'] !== null && isset($rebuilt[$spec['source']])) {
        $section = self::redrawVersion($next, $base['melody'], $spec, $section, $random);
        $rebuilt[$index] = true;
      }
      self::appendSection($next, $section);
    }
    $base['score'] = Score::fromData($next->data());
    return $base;
  }

  /** Replace the selected section with an independent generated motif. */
  public static function generateAt(Score $score, int $melody, int $sectionIndex,
    int $beats, int $pitchCount, ?string $scale = null, ?string $homeNote = null,
    string $type = 'random-step', ?callable $random = null): array {
    if ($sectionIndex === 0) {
      return self::regenerate($score, $melody, $beats, $pitchCount,
        $scale, $homeNote, $random, $type);
    }
    $score->assertMelodyEditable($melody);
    $old = self::sections($score);
    if (!isset($old[$sectionIndex])) {
      throw new \DomainException('The selected section does not exist.');
    }
    $random ??= random_int(...);
    $spec = self::validateSpec(['algorithm' => 'generate', 'source' => null,
      'parameters' => ['beats' => $beats,
        'pitchCount' => $pitchCount,
        'scale' => $scale, 'homeNote' => $homeNote, 'type' => $type]], $sectionIndex);
    $next = new Score(count($score->melodies));
    $next->title = $score->title;
    $next->author = $score->author;
    $next->catalog = $score->catalog;
    $next->melodies = $score->melodies;
    $next->bars = $next->notes = $next->scoreDynamics = [];
    $rebuilt = [];
    foreach ($old as $index => $section) {
      if ($index === $sectionIndex) {
        $section = self::generatedSection($next, $melody, $spec, $random);
        $rebuilt[$index] = true;
      } else if ($index > $sectionIndex) {
        $version = $section['bars'][0]['motifVersion'] ?? null;
        if ($version !== null && $version['source'] !== null
          && isset($rebuilt[$version['source']])) {
          $section = self::redrawVersion($next, $melody, $version, $section, $random);
          $rebuilt[$index] = true;
        }
      }
      self::appendSection($next, $section);
    }
    return ['score' => Score::fromData($next->data()), 'melody' => $melody,
      'label' => $next->melodies[$melody]['motif']['label']];
  }
}
