<?php

namespace MADSCORE\Score;

use MADSCORE\Play\Drum;

/** Atomic time-range edits for one melody or every melody. Times are half-beats. */
final class RangeEdit {

  public static function copy(Score $score, array $range): array {
    $notes = [];
    $selected = [];
    foreach ($score->notes as $index => $note) {
      if (!self::selected($score, $note, $range)) {
        continue;
      }
      $selected[$index] = true;
      $start = $score->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      $from = max($start, $range['start']);
      $to = min($end, $range['end']);
      $notes[$index] = ['time' => $from - $range['start'],
        'note' => self::fragment($note, $start, $end, $from, $to)];
    }
    foreach ($notes as $index => &$item) {
      if (!isset($selected[$score->nextAdjacent($index) ?? -1])) {
        $item['note']['slurToNext'] = false;
        $item['note']['glissandoToNext'] = false;
      }
      if (!isset($selected[$score->nextMelodyNote($index) ?? -1])) {
        $item['note']['dynamicToNext'] = '';
      }
    }
    unset($item);
    $dynamics = [];
    if (($range['scope'] ?? 'melody') === 'score') {
      foreach ($score->scoreDynamics as $mark) {
        if ($mark['time'] < $range['start'] || $mark['time'] >= $range['end']) {
          continue;
        }
        $mark['time'] -= $range['start'];
        if ($mark['end'] !== null) {
          $mark['end'] = min($mark['end'], $range['end']) - $range['start'];
        }
        $dynamics[] = $mark;
      }
    }
    return ['scope' => $range['scope'] ?? 'melody', 'duration' => $range['end'] - $range['start'],
      'notes' => array_values($notes), 'dynamics' => $dynamics];
  }

  public static function clear(Score $score, array $range): Score {
    self::assertEditable($score, $range);
    $data = $score->data();
    $notes = [];
    foreach ($score->notes as $note) {
      if (!self::selected($score, $note, $range)) {
        $notes[] = $note;
        continue;
      }
      $start = $score->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      if (($range['scope'] ?? 'melody') === 'score') {
        if ($start < $range['start']) {
          $notes[] = self::placedFragment($data['bars'], $note, $start, $end, $start, $range['start']);
        }
        if ($end > $range['end']) {
          $notes[] = self::placedFragment($data['bars'], $note, $start, $end, $range['end'], $end);
        }
      }
    }
    $data['notes'] = $notes;
    return self::validated($data);
  }

  public static function close(Score $score, array $range): Score {
    self::assertEditable($score, $range);
    $data = $score->data();
    $length = $range['end'] - $range['start'];
    foreach ($data['notes'] as &$note) {
      if (!self::inScope($note, $range)) {
        continue;
      }
      $start = $score->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      if ($start < $range['end'] && $end > $range['start']) {
        throw new \DomainException('The selected time still contains notes.');
      }
      if ($start >= $range['end']) {
        [$note['bar'], $note['slot']] = self::position($data['bars'], $start - $length);
      }
    }
    unset($note);
    if (($range['scope'] ?? 'melody') === 'score') {
      $marks = [];
      foreach ($data['scoreDynamics'] as $mark) {
        if ($mark['time'] >= $range['start'] && $mark['time'] < $range['end']) {
          continue;
        }
        if ($mark['time'] >= $range['end']) {
          $mark['time'] -= $length;
        }
        if ($mark['end'] !== null && $mark['end'] > $range['start']) {
          $mark['end'] = $mark['end'] <= $range['end'] ? $range['start'] : $mark['end'] - $length;
          if ($mark['end'] <= $mark['time']) {
            $mark['end'] = null;
            $mark['direction'] = '';
          }
        }
        if ($mark['level'] !== '' || $mark['direction'] !== '') {
          $marks[] = $mark;
        }
      }
      $data['scoreDynamics'] = $marks;
    }
    return self::validated($data);
  }

  public static function transpose(Score $score, array $range, int $semitones): Score {
    self::assertEditable($score, $range);
    $data = $score->data();
    $notes = [];
    $changed = false;
    foreach ($score->notes as $note) {
      if (!self::selected($score, $note, $range)) {
        $notes[] = $note;
        continue;
      }
      $changed = true;
      $start = $score->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      $from = max($start, $range['start']);
      $to = min($end, $range['end']);
      if (($range['scope'] ?? 'melody') === 'score' && $start < $from) {
        $notes[] = self::placedFragment($data['bars'], $note, $start, $end, $start, $from);
      }
      $moved = self::placedFragment($data['bars'], $note, $start, $end, $from, $to);
      $absolute = $moved['octave'] * 12 + Pitch::position($moved) + $semitones;
      if ($absolute < 1 || $absolute > 108) {
        throw new \DomainException('This pitch is outside the nine octave bands.');
      }
      $moved['octave'] = intdiv($absolute - 1, 12);
      [$moved['pitch'], $moved['alter']] = Pitch::spelling(($absolute - 1) % 12 + 1);
      if ($moved['octave'] * 12 + Pitch::position($moved) + ($moved['trill'] ?? 0) > 108
        || $moved['octave'] * 12 + Pitch::position($moved) + ($moved['trill'] ?? 0) < 1) {
        $moved['trill'] = 0;
      }
      $notes[] = $moved;
      if (($range['scope'] ?? 'melody') === 'score' && $to < $end) {
        $notes[] = self::placedFragment($data['bars'], $note, $start, $end, $to, $end);
      }
    }
    if (!$changed) {
      throw new \DomainException('The selection contains no notes.');
    }
    $data['notes'] = $notes;
    return self::validated($data);
  }

  public static function paste(Score $score, array $clip, ?array $range, int $melody, int $at): Score {
    $scope = $clip['scope'];
    if ($range !== null && ($range['scope'] ?? 'melody') !== $scope) {
      throw new \DomainException('Copied and selected ranges must have the same scope.');
    }
    $start = $range['start'] ?? $at;
    $end = $range['end'] ?? $at;
    $target = $range ?? ['scope' => $scope, 'melody' => $melody, 'start' => $start, 'end' => $end];
    self::assertEditable($score, $target);
    $data = $score->data();
    $notes = [];
    foreach ($score->notes as $note) {
      if (!self::inScope($note, $target)) {
        $notes[] = $note;
        continue;
      }
      $noteStart = $score->start($note['bar']) + $note['slot'];
      $noteEnd = $noteStart + $note['length'];
      if ($noteEnd <= $start || $noteStart >= $end && $range !== null) {
        $notes[] = $note;
        continue;
      }
      if ($noteStart < $start && $noteEnd > $start) {
        $notes[] = self::placedFragment($data['bars'], $note, $noteStart, $noteEnd, $noteStart, $start);
      }
      if ($noteEnd > $end && $range !== null) {
        $notes[] = self::placedFragment($data['bars'], $note, $noteStart, $noteEnd, $end, $noteEnd);
      } else if ($range === null && $noteStart >= $start) {
        $notes[] = $note;
      } else if ($range === null && $noteStart < $start && $noteEnd > $start) {
        $notes[] = self::placedFragment($data['bars'], $note, $noteStart, $noteEnd, $start, $noteEnd);
      }
    }
    $delta = $clip['duration'] - ($end - $start);
    foreach ($notes as &$note) {
      if (!self::inScope($note, $target)) {
        continue;
      }
      $noteStart = self::time($data['bars'], $note);
      if ($noteStart >= $end) {
        [$note['bar'], $note['slot']] = self::position($data['bars'], $noteStart + $delta);
      }
    }
    unset($note);
    $nextChordId = max([0, ...array_map(static fn (array $note): int =>
      $note['chord']['id'] ?? 0, $notes)]) + 1;
    $copiedChordIds = [];
    $copiedChordCounts = [];
    foreach ($clip['notes'] as $item) {
      if (isset($item['note']['chord'])) {
        $id = $item['note']['chord']['id'];
        $copiedChordCounts[$id] = ($copiedChordCounts[$id] ?? 0) + 1;
      }
    }
    foreach ($clip['notes'] as $item) {
      $note = $item['note'];
      if (isset($note['chord'])) {
        $originalId = $note['chord']['id'];
        if ($copiedChordCounts[$originalId] !== $note['chord']['count']) {
          unset($note['chord']);
        } else {
          $copiedChordIds[$originalId] ??= $nextChordId++;
          $note['chord']['id'] = $copiedChordIds[$originalId];
        }
      }
      $note['melody'] = $scope === 'score' ? $note['melody'] : $melody;
      if (!isset($data['melodies'][$note['melody']])) {
        throw new \DomainException('The destination has too few melodies for this copy.');
      }
      [$note['bar'], $note['slot']] = self::position($data['bars'], $start + $item['time']);
      $notes[] = $note;
    }
    $data['notes'] = $notes;
    if ($scope === 'score') {
      $marks = [];
      foreach ($data['scoreDynamics'] as $mark) {
        if ($range !== null && $mark['time'] >= $start && $mark['time'] < $end) {
          continue;
        }
        if ($mark['time'] >= $end) {
          $mark['time'] += $delta;
        }
        if ($mark['end'] !== null && $mark['end'] >= $end) {
          $mark['end'] += $delta;
        }
        $marks[] = $mark;
      }
      foreach ($clip['dynamics'] ?? [] as $mark) {
        $mark['time'] += $start;
        if ($mark['end'] !== null) {
          $mark['end'] += $start;
        }
        $marks[] = $mark;
      }
      usort($marks, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
      $data['scoreDynamics'] = $marks;
      foreach ($marks as $mark) {
        self::position($data['bars'], $mark['time']);
        if ($mark['end'] !== null) {
          self::position($data['bars'], $mark['end'] - 1);
        }
      }
    }
    return self::validated($data);
  }

  /** Change active-melody note-to-note marks across a selected range. */
  public static function mark(Score $score, array $range, string $kind): Score {
    $score->assertMelodyEditable($range['melody']);
    $copy = Score::fromData($score->data());
    $selected = [];
    foreach ($copy->notes as $index => $note) {
      if ($note['melody'] === $range['melody'] && self::selected($copy, $note, $range)) {
        $selected[$index] = true;
      }
    }
    $pairs = [];
    foreach (array_keys($selected) as $index) {
      $next = $kind === 'link' ? $copy->nextAdjacent($index) : $copy->nextMelodyNote($index);
      if ($next !== null && isset($selected[$next])) {
        $pairs[] = $index;
      }
    }
    if ($pairs === []) {
      throw new \DomainException('Select at least two eligible notes in this melody.');
    }
    if ($kind === 'link') {
      $set = !array_reduce($pairs, fn (bool $all, int $i): bool => $all && $copy->notes[$i]['slurToNext'], true);
      foreach ($pairs as $index) {
        $copy->setSlurToNext($index, $set);
      }
    } else {
      $values = array_map(static fn (int $i): string => $copy->notes[$i]['dynamicToNext'], $pairs);
      $next = count(array_unique($values)) === 1 ? Dynamics::nextDirection($values[0]) : 'crescendo';
      foreach ($pairs as $index) {
        $copy->setDynamicToNext($index, $next);
      }
    }
    return Score::fromData($copy->data());
  }

  private static function selected(Score $score, array $note, array $range): bool {
    if (!self::inScope($note, $range)) {
      return false;
    }
    $start = $score->start($note['bar']) + $note['slot'];
    $end = $start + $note['length'];
    return ($range['scope'] ?? 'melody') === 'score'
      ? $start < $range['end'] && $end > $range['start']
      : $start >= $range['start'] && $end <= $range['end'];
  }

  private static function assertEditable(Score $score, array $range): void {
    if (($range['scope'] ?? 'melody') === 'score') {
      $score->assertAllMelodiesEditable();
    } else {
      $score->assertMelodyEditable($range['melody']);
    }
  }

  private static function inScope(array $note, array $range): bool {
    return ($range['scope'] ?? 'melody') === 'score' || $note['melody'] === $range['melody'];
  }

  private static function fragment(array $note, int $originalStart, int $originalEnd, int $from, int $to): array {
    $note['length'] = $to - $from;
    if ($from !== $originalStart) {
      $note['articulation'] = 'ordinary';
      $note['fermata'] = false;
      $note['trill'] = 0;
      $note['finger'] = '';
      $note['dynamicLevel'] = '';
    }
    if ($from !== $originalStart || $to !== $originalEnd) {
      $note['slurToNext'] = false;
      $note['glissandoToNext'] = false;
      $note['dynamicToNext'] = '';
      $note['fermata'] = false;
    }
    return $note;
  }

  private static function placedFragment(array &$bars, array $note, int $originalStart, int $originalEnd, int $from, int $to): array {
    $note = self::fragment($note, $originalStart, $originalEnd, $from, $to);
    [$note['bar'], $note['slot']] = self::position($bars, $from);
    return $note;
  }

  private static function time(array $bars, array $note): int {
    $time = $note['slot'];
    for ($i = 0; $i < $note['bar']; $i++) {
      $time += 2 * $bars[$i]['beats'];
    }
    return $time;
  }

  private static function position(array &$bars, int $time): array {
    if ($time < 0) {
      throw new \DomainException('A note cannot begin before the score.');
    }
    $bar = 0;
    while (true) {
      if (!isset($bars[$bar])) {
        $next = $bars[$bar - 1];
        $next['sectionLeader'] = false;
        $next['repeats'] = 0;
        $next['note'] = '';
        $bars[] = $next;
      }
      $length = 2 * $bars[$bar]['beats'];
      if ($time < $length) {
        return [$bar, $time];
      }
      $time -= $length;
      $bar++;
    }
  }

  private static function validated(array $data): Score {
    // Removed or split neighbors cannot retain a link across the new boundary.
    $probe = new Score(count($data['melodies']));
    $probe->bars = $data['bars'];
    $probe->melodies = $data['melodies'];
    $probe->notes = $data['notes'];
    foreach ($probe->notes as $i => $note) {
      $next = $probe->nextAdjacent($i);
      if ($next === null) {
        $data['notes'][$i]['slurToNext'] = false;
        $data['notes'][$i]['glissandoToNext'] = false;
      } else if (($note['glissandoToNext'] ?? false) && (in_array($probe->melodies[$note['melody']]['pattern'], Drum::NAMES, true)
        || $note['alter'] !== 0 || $probe->notes[$next]['alter'] !== 0
        || $note['octave'] === $probe->notes[$next]['octave'] && Pitch::position($note) === Pitch::position($probe->notes[$next]))) {
        $data['notes'][$i]['glissandoToNext'] = false;
      }
      if ($probe->nextMelodyNote($i) === null) {
        $data['notes'][$i]['dynamicToNext'] = '';
      }
    }
    return Score::fromData($data);
  }
}
