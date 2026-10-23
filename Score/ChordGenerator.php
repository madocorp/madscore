<?php

namespace MADSCORE\Score;

/** Places diatonic chords into dedicated, reusable melody voices. */
final class ChordGenerator {

  private const MAX_TRIES = 12;

  public static function validateMetadata(mixed $value): array {
    if (!is_array($value)
      || !in_array(array_keys($value), [
        ['id', 'count', 'degree', 'inversion', 'scale', 'homeNote'],
        ['id', 'count', 'degree', 'inversion', 'scale', 'homeNote', 'anchor'],
        ['id', 'count', 'degree', 'inversion', 'scale', 'homeNote', 'anchor', 'placement'],
        ['id', 'count', 'degree', 'inversion', 'scale', 'homeNote', 'anchor', 'placement', 'fixed'],
      ], true)
      || !is_int($value['id']) || $value['id'] < 1
      || !is_int($value['count']) || $value['count'] < 2 || $value['count'] > 7
      || !is_int($value['degree']) || $value['degree'] < 1 || $value['degree'] > 7
      || !is_int($value['inversion']) || $value['inversion'] < 0
      || $value['inversion'] >= $value['count']
      || (array_key_exists('anchor', $value) && (!is_int($value['anchor'])
        || $value['anchor'] < 21 || $value['anchor'] > 128))
      || (array_key_exists('placement', $value)
        && !in_array($value['placement'], ['top', 'fixed'], true))
      || (($value['placement'] ?? null) === 'fixed'
        && !array_key_exists('fixed', $value))
      || (array_key_exists('fixed', $value)
        && ($value['placement'] !== 'fixed' || !is_bool($value['fixed'])))) {
      throw new \DomainException('Invalid chord settings.');
    }
    HarmonicContext::validate(['scale' => $value['scale'],
      'homeNote' => $value['homeNote']]);
    return $value;
  }

  /** Return the generated chord sounding at this cursor, if any. */
  public static function groupAt(Score $score, int $bar, int $slot,
    ?int $melody = null): ?array {
    $time = $score->start($bar) + $slot;
    foreach ($score->notes as $note) {
      if (!isset($note['chord']) || ($melody !== null && $note['melody'] !== $melody)) {
        continue;
      }
      $start = $score->start($note['bar']) + $note['slot'];
      if ($time >= $start && $time < $start + $note['length']) {
        return $note['chord'];
      }
    }
    return null;
  }

  private static function voices(Score $score, int $count, int $sourceMelody): array {
    $voices = [];
    $used = [];
    foreach ($score->melodies as $index => $settings) {
      if (isset($settings['chordVoice'])) {
        $used[$settings['chordVoice']] = true;
        if ($index !== $sourceMelody) {
          $voices[$settings['chordVoice']] = $index;
        }
      }
    }
    ksort($voices);
    $voices = array_values($voices);
    while (count($voices) < $count - 1) {
      $voice = 0;
      while (isset($used[$voice])) {
        $voice++;
      }
      if ($voice > 6) {
        throw new \DomainException('No free chord voice is available.');
      }
      $index = $score->addMelody();
      foreach (['instrument', 'pattern', 'volume'] as $field) {
        $score->melodies[$index][$field] = $score->melodies[$sourceMelody][$field];
      }
      $score->melodies[$index]['chordVoice'] = $voice;
      $used[$voice] = true;
      $voices[] = $index;
    }
    return [...array_slice($voices, 0, $count - 1), $sourceMelody];
  }

  /** Form ascending scale thirds, invert, then put the bass nearest the anchor. */
  private static function pitches(int $count, int $degree, int $inversion,
    array $music, int $anchor, string $placement): array {
    $scale = MotifScoreGenerator::SCALES[$music['scale']];
    $home = MotifScoreGenerator::HOME_NOTES[$music['homeNote']];
    $pitches = [];
    for ($voice = 0; $voice < $count; $voice++) {
      $position = $degree - 1 + 2 * $voice;
      $pitches[] = 60 + $home + 12 * intdiv($position, 7) + $scale[$position % 7];
    }
    for ($step = 0; $step < $inversion; $step++) {
      $pitches[] = array_shift($pitches) + 12;
    }
    $reference = $placement === 'top' ? end($pitches) : $pitches[0];
    $octaves = (int)round(($anchor - $reference) / 12);
    $pitches = array_map(static fn (int $pitch): int => $pitch + 12 * $octaves,
      $pitches);
    if ($pitches[0] < 21 || end($pitches) > 128) {
      throw new \DomainException('The chord is outside the score pitch range.');
    }
    return $pitches;
  }

  private static function choice(int $count, ?int $degree, ?int $inversion,
    array $music, int $anchor, callable $random, ?array $previous = null,
    ?callable $allowed = null, string $placement = 'bass'): array {
    $fallback = null;
    for ($attempt = 0; $attempt < self::MAX_TRIES; $attempt++) {
      $chosenDegree = $degree ?? $random(1, 7);
      $chosenInversion = $inversion ?? $random(0, $count - 1);
      try {
        $pitches = self::pitches($count, $chosenDegree, $chosenInversion,
          $music, $anchor, $placement);
      } catch (\DomainException $error) {
        if ($degree !== null && $inversion !== null) {
          throw $error;
        }
        continue;
      }
      if ($allowed !== null && !$allowed($pitches)) {
        continue;
      }
      if ($pitches !== $previous) {
        return [$chosenDegree, $chosenInversion, $pitches];
      }
      $fallback = [$chosenDegree, $chosenInversion, $pitches];
    }
    return $fallback ?? throw new \DomainException('No chord fits the available pitch rows.');
  }

  /** Choose a scale chord that contains the unchanged melody pitch. */
  private static function fixedChoice(int $count, ?int $degree, ?int $inversion,
    array $music, int $fixedPitch, callable $random, ?array $previous = null,
    ?callable $allowed = null): array {
    $candidates = [];
    foreach ($degree === null ? range(1, 7) : [$degree] as $chosenDegree) {
      foreach ($inversion === null ? range(0, $count - 1) : [$inversion] as $chosenInversion) {
        $base = self::pitches($count, $chosenDegree, $chosenInversion,
          $music, 60, 'bass');
        foreach ($base as $fixedIndex => $tone) {
          $shift = $fixedPitch - $tone;
          if ($shift % 12 !== 0) {
            continue;
          }
          $pitches = array_map(static fn (int $pitch): int => $pitch + $shift,
            $base);
          if ($pitches[0] < 21 || end($pitches) > 128
            || ($allowed !== null && !$allowed($pitches, $fixedIndex))) {
            continue;
          }
          $candidates[] = [$chosenDegree, $chosenInversion, $pitches, $fixedIndex];
        }
      }
    }
    if ($candidates === []) {
      throw new \DomainException('This note does not fit a chord in the chosen scale and settings.');
    }
    $different = array_values(array_filter($candidates,
      static fn (array $candidate): bool => $candidate[2] !== $previous));
    $pool = $different === [] ? $candidates : $different;
    return $pool[$random(0, count($pool) - 1)];
  }

  private static function atTime(Score $score, int $time): array {
    $bar = 0;
    while ($time >= $score->start($bar + 1)) {
      $bar++;
      $score->ensureBar($bar);
    }
    return [$bar, $time - $score->start($bar)];
  }

  /** Insert one chord or an evenly spaced series without touching other melodies. */
  public static function place(Score $score, int $bar, int $slot, int $length,
    int $count, int $series, array $music, int $anchor, int $sourceMelody,
    ?int $degree = null, ?int $inversion = null, ?callable $random = null,
    ?int $replaceId = null): Score {
    $music = HarmonicContext::validate($music);
    if ($length < 1 || $length > 198 || $count < 2 || $count > 7
      || $series < 1 || $series > 16 || ($degree !== null && ($degree < 1 || $degree > 7))
      || ($inversion !== null && ($inversion < 0 || $inversion >= $count))) {
      throw new \DomainException('Use 2–7 notes, 1–16 chords, and valid degree and inversion.');
    }
    $random ??= random_int(...);
    $next = clone $score;
    $next->assertMelodyEditable($sourceMelody);
    if ($replaceId !== null) {
      if ($series !== 1) {
        throw new \DomainException('Replace one chord at a time.');
      }
      $old = array_values(array_filter($next->notes,
        static fn (array $note): bool => ($note['chord']['id'] ?? null) === $replaceId));
      if ($old === []) {
        throw new \DomainException('The chord to replace does not exist.');
      }
      $fixed = array_values(array_filter($old,
        static fn (array $note): bool => ($note['chord']['fixed'] ?? false) === true));
      if (count($fixed) > 1) {
        throw new \DomainException('The generated chord has more than one fixed note.');
      }
      $bar = $old[0]['bar'];
      $slot = $old[0]['slot'];
      if ($fixed !== []) {
        $sourceMelody = $fixed[0]['melody'];
        $next->assertMelodyEditable($sourceMelody);
        $length = $fixed[0]['length'];
      }
      $next->notes = array_values(array_filter($next->notes,
        static fn (array $note): bool => ($note['chord']['id'] ?? null) !== $replaceId
          || ($note['chord']['fixed'] ?? false) === true));
    } else {
      $selected = $next->noteAt($sourceMelody, $bar, $slot);
      if ($selected !== null) {
        $bar = $next->notes[$selected]['bar'];
        $slot = $next->notes[$selected]['slot'];
      }
    }
    $voices = self::voices($next, $count, $sourceMelody);
    $id = $replaceId ?? max([0, ...array_map(static fn (array $note): int =>
      $note['chord']['id'] ?? 0, $next->notes)]) + 1;
    $startTime = $next->start($bar) + $slot;
    $sectionStart = $score->sectionStart($bar);
    $previous = null;
    for ($chord = 0; $chord < $series; $chord++) {
      [$atBar, $atSlot] = self::atTime($next, $startTime + $chord * $length);
      if ($atBar < count($score->bars) && $score->sectionStart($atBar) !== $sectionStart) {
        throw new \DomainException('A chord series cannot start in another section.');
      }
      $selected = $next->noteAt($sourceMelody, $atBar, $atSlot);
      if ($selected !== null && ($next->notes[$selected]['bar'] !== $atBar
        || $next->notes[$selected]['slot'] !== $atSlot
        || $next->notes[$selected]['length'] !== $length
        || (isset($next->notes[$selected]['chord'])
          && !($replaceId !== null && $chord === 0
            && ($next->notes[$selected]['chord']['id'] ?? null) === $replaceId
            && ($next->notes[$selected]['chord']['fixed'] ?? false) === true)))) {
        throw new \DomainException('The melody note cannot be used as a fixed chord note here.');
      }
      if ($selected !== null) {
        $fixedPitch = 20 + 12 * $next->notes[$selected]['octave']
          + Pitch::position($next->notes[$selected]);
        $allowed = static function (array $candidate, int $fixedIndex) use
          ($next, $voices): bool {
          array_splice($candidate, $fixedIndex, 1);
          foreach (array_slice($voices, 0, -1) as $voice => $melody) {
            $absolute = [$candidate[$voice] - 20];
            foreach ($next->notes as $note) {
              if ($note['melody'] === $melody) {
                $absolute[] = 12 * $note['octave'] + Pitch::position($note);
              }
            }
            if (MelodyRange::possibleBottoms($absolute) === []) {
              return false;
            }
          }
          return true;
        };
        [$chosenDegree, $chosenInversion, $pitches, $fixedIndex] =
          self::fixedChoice($count, $degree, $inversion, $music,
            $fixedPitch, $random, $previous, $allowed);
        $otherPitches = $pitches;
        array_splice($otherPitches, $fixedIndex, 1);
        $assignments = array_combine(array_slice($voices, 0, -1), $otherPitches);
        $metadata = ['id' => $id, 'count' => $count,
          'degree' => $chosenDegree, 'inversion' => $chosenInversion,
          'scale' => $music['scale'], 'homeNote' => $music['homeNote'],
          'anchor' => $fixedPitch, 'placement' => 'fixed'];
      } else {
        [$chosenDegree, $chosenInversion, $pitches] = self::choice($count,
          $degree, $inversion, $music, $anchor, $random, $previous,
          placement: 'top');
        $assignments = array_combine($voices, $pitches);
        $metadata = ['id' => $id, 'count' => $count,
          'degree' => $chosenDegree, 'inversion' => $chosenInversion,
          'scale' => $music['scale'], 'homeNote' => $music['homeNote'],
          'anchor' => $anchor, 'placement' => 'top'];
      }
      foreach ($assignments as $melody => $pitch) {
        if ($next->noteAt($melody, $atBar, $atSlot) !== null) {
          throw new \DomainException('A chord voice already has a note at this position.');
        }
        $position = $pitch - 20;
        $index = $next->place($melody, $atBar, $atSlot,
          intdiv($position - 1, 12), ($position - 1) % 12 + 1, $length);
        $next->notes[$index]['chord'] = $metadata
          + ($selected !== null ? ['fixed' => false] : []);
      }
      if ($selected !== null) {
        $next->notes[$selected]['chord'] = $metadata + ['fixed' => true];
      }
      $previous = $pitches;
      $id++;
    }
    return Score::fromData($next->data());
  }

  /** Replace a generated chord with another choice, preserving its voices and length. */
  public static function reroll(Score $score, int $bar, int $slot,
    ?callable $random = null, ?int $melody = null): Score {
    $settings = self::groupAt($score, $bar, $slot, $melody);
    if ($settings === null) {
      throw new \DomainException('No generated chord at the cursor.');
    }
    $notes = array_values(array_filter($score->notes,
      static fn (array $note): bool => ($note['chord']['id'] ?? null) === $settings['id']));
    if (count($notes) !== $settings['count']) {
      throw new \DomainException('The generated chord is incomplete.');
    }
    usort($notes, static fn (array $a, array $b): int =>
      (20 + 12 * $a['octave'] + Pitch::position($a))
      <=> (20 + 12 * $b['octave'] + Pitch::position($b)));
    $first = $notes[0];
    $time = $score->start($first['bar']) + $first['slot'];
    foreach ($notes as $note) {
      if ($score->start($note['bar']) + $note['slot'] !== $time
        || $note['length'] !== $first['length']) {
        throw new \DomainException('The generated chord has been edited unevenly.');
      }
    }
    $oldPitches = array_map(static fn (array $note): int =>
      20 + 12 * $note['octave'] + Pitch::position($note), $notes);
    $random ??= random_int(...);
    $music = ['scale' => $settings['scale'], 'homeNote' => $settings['homeNote']];
    $fixed = array_values(array_filter($notes,
      static fn (array $note): bool => ($note['chord']['fixed'] ?? false) === true));
    if (($settings['placement'] ?? null) === 'fixed') {
      if (count($fixed) !== 1) {
        throw new \DomainException('The generated chord needs one fixed melody note.');
      }
      $fixedNote = $fixed[0];
      $fixedPitch = 20 + 12 * $fixedNote['octave'] + Pitch::position($fixedNote);
      $otherNotes = array_values(array_filter($notes,
        static fn (array $note): bool => ($note['chord']['fixed'] ?? false) !== true));
      $allowed = static function (array $pitches, int $fixedIndex) use
        ($score, $otherNotes, $settings): bool {
        array_splice($pitches, $fixedIndex, 1);
        foreach ($otherNotes as $voice => $note) {
          $absolute = [$pitches[$voice] - 20];
          foreach ($score->notes as $existing) {
            if ($existing['melody'] === $note['melody']
              && ($existing['chord']['id'] ?? null) !== $settings['id']) {
              $absolute[] = 12 * $existing['octave'] + Pitch::position($existing);
            }
          }
          if (MelodyRange::possibleBottoms($absolute) === []) {
            return false;
          }
        }
        return true;
      };
      [$degree, $inversion, $pitches, $fixedIndex] = self::fixedChoice(
        $settings['count'], null, null, $music, $fixedPitch, $random,
        $oldPitches, $allowed);
      array_splice($pitches, $fixedIndex, 1);
      $next = clone $score;
      $next->notes = array_values(array_filter($next->notes,
        static fn (array $note): bool => ($note['chord']['id'] ?? null) !== $settings['id']
          || ($note['chord']['fixed'] ?? false) === true));
      $metadata = ['id' => $settings['id'], 'count' => $settings['count'],
        'degree' => $degree, 'inversion' => $inversion, 'scale' => $music['scale'],
        'homeNote' => $music['homeNote'], 'anchor' => $fixedPitch,
        'placement' => 'fixed'];
      foreach ($otherNotes as $voice => $note) {
        $pitch = $pitches[$voice];
        $position = $pitch - 20;
        $index = $next->place($note['melody'], $note['bar'], $note['slot'],
          intdiv($position - 1, 12), ($position - 1) % 12 + 1, $note['length']);
        $next->notes[$index]['chord'] = $metadata + ['fixed' => false];
      }
      foreach ($next->notes as &$note) {
        if (($note['chord']['id'] ?? null) === $settings['id']
          && ($note['chord']['fixed'] ?? false) === true) {
          $note['chord'] = $metadata + ['fixed' => true];
          break;
        }
      }
      unset($note);
      return Score::fromData($next->data());
    }
    $allowed = static function (array $pitches) use ($score, $notes, $settings): bool {
      foreach ($notes as $voice => $note) {
        $absolute = [$pitches[$voice] - 20];
        foreach ($score->notes as $other) {
          if ($other['melody'] === $note['melody']
            && ($other['chord']['id'] ?? null) !== $settings['id']) {
            $absolute[] = 12 * $other['octave'] + Pitch::position($other);
          }
        }
        if (MelodyRange::possibleBottoms($absolute) === []) {
          return false;
        }
      }
      return true;
    };
    [$degree, $inversion, $pitches] = self::choice($settings['count'], null,
      null, $music, $settings['anchor'] ?? $oldPitches[0], $random,
      $oldPitches, $allowed, $settings['placement'] ?? 'bass');
    $next = clone $score;
    $next->notes = array_values(array_filter($next->notes,
      static fn (array $note): bool => ($note['chord']['id'] ?? null) !== $settings['id']));
    foreach ($notes as $voice => $note) {
      $pitch = $pitches[$voice];
      $position = $pitch - 20;
      $index = $next->place($note['melody'], $note['bar'], $note['slot'],
        intdiv($position - 1, 12), ($position - 1) % 12 + 1, $note['length']);
      $next->notes[$index]['chord'] = ['id' => $settings['id'],
        'count' => $settings['count'], 'degree' => $degree,
        'inversion' => $inversion, 'scale' => $music['scale'],
        'homeNote' => $music['homeNote'],
        'anchor' => $settings['anchor'] ?? $oldPitches[0],
        ...(($settings['placement'] ?? null) === 'top' ? ['placement' => 'top'] : [])];
    }
    return Score::fromData($next->data());
  }
}
